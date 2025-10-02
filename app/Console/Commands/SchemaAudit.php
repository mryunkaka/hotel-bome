<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

class SchemaAudit extends Command
{
    protected $signature = 'schema:audit
        {--only= : Comma separated table names to limit audit (e.g. reservations,reservation_guests)}
        {--no-extra : Hide Extra columns section (show Missing only)}';

    protected $description = 'Audit skema berdasar PEMAKAIAN NYATA dari Filament Resources, Models, dan Blade views.';

    /** @var array<string, array<string,bool>> table => set(field=>true) */
    protected array $used = [];

    /** @var array<string, string> class => table */
    protected array $modelTableMap = [];

    public function handle(): int
    {
        $fs = new Filesystem();

        $projectRoot = base_path(); // asumsikan struktur standar Laravel

        // 1) Petakan Model → Tabel + kumpulkan field dari $fillable, $casts, relasi belongsTo
        $this->info('Scan: app/Models …');
        $this->scanModels($projectRoot);

        // 2) Scan Filament → ambil static $model + semua ::make('field') pada Form/Table
        $this->info('Scan: app/Filament …');
        $this->scanFilament($projectRoot);

        // 3) Scan Blade views → pola umum $var->field (pakai kamus var→tabel)
        $this->info('Scan: resources/views …');
        $this->scanBlades($projectRoot);

        // (Opsional) di sini kamu bisa tambah scanner lain (Routes, Policies, dll) jika diperlukan.

        // 4) Audit dibandingkan DB
        $only = $this->option('only')
            ? collect(explode(',', $this->option('only')))->map(fn($s) => trim($s))->filter()->values()->all()
            : null;

        $hideExtra = (bool)$this->option('no-extra');

        $missingTotal = 0;
        $extraTotal   = 0;

        // Gabungkan semua tabel yang terdeteksi dipakai + tabel yang ada di DB (agar ketahuan table dipakai / tidak)
        $tablesUsed = array_keys($this->used);
        $tablesInDb = $this->listTablesFromDb();
        $allTables  = collect(array_unique(array_merge($tablesUsed, $tablesInDb)))->sort()->values()->all();

        foreach ($allTables as $table) {
            if ($only && !in_array($table, $only, true)) {
                continue;
            }

            if (!Schema::hasTable($table)) {
                // Tabel dipakai di kode tapi belum ada di DB
                if (!empty($this->used[$table])) {
                    $this->error("TABLE MISSING: {$table} (dipakai di kode, tidak ada di DB)");
                    $missingTotal++;
                }
                continue;
            }

            $wantCols = array_keys($this->used[$table] ?? []);
            sort($wantCols);
            $haveCols = Schema::getColumnListing($table);
            sort($haveCols);

            $missing = array_values(array_diff($wantCols, $haveCols));
            $extra   = array_values(array_diff($haveCols, $wantCols));

            if ($missing || (!$hideExtra && $extra)) {
                $this->warn("!= {$table}");
                if ($missing) {
                    $this->line('  - Missing: ' . implode(', ', $missing));
                    $missingTotal += count($missing);
                }
                if (!$hideExtra && $extra) {
                    $this->line('  - Extra:   ' . implode(', ', $extra));
                    $extraTotal   += count($extra);
                }
            } else {
                $this->info("== {$table} : OK");
            }
        }

        $this->line('');
        $this->line("Audit selesai. Missing cols = {$missingTotal}" . ($hideExtra ? '' : ", Extra cols = {$extraTotal}"));

        // Exit code 1 jika masih ada Missing
        return $missingTotal > 0 ? 1 : 0;
    }

    /* ---------------------------------------------------------------------
     * Scanners
     * -------------------------------------------------------------------*/

    protected function scanModels(string $root): void
    {
        $finder = (new Finder())
            ->files()
            ->in($root . '/app/Models')
            ->name('*.php');

        foreach ($finder as $file) {
            $code = $file->getContents();
            $class = $this->match('/class\s+([A-Za-z0-9_\\\\]+)\s+extends\s+[A-Za-z0-9_\\\\]+/', $code, 1);
            if (!$class) {
                continue;
            }
            $short = class_basename($class);

            // namespace?
            $ns = $this->match('/namespace\s+([A-Za-z0-9_\\\\]+)\s*;/', $code, 1);
            $fqn = $ns ? ($ns . '\\' . $short) : $short;

            // tabel
            $table = $this->match('/protected\s+\$table\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/', $code, 1);
            if (!$table) {
                // fallback: plural snake dari nama class
                $table = Str::snake(Str::pluralStudly($short));
            }
            $this->modelTableMap[$fqn] = $table;

            // $fillable
            $fillable = $this->parsePhpArray($this->match('/protected\s+\$fillable\s*=\s*(\[[^\]]*\])\s*;?/s', $code, 1));
            foreach ($fillable as $col) {
                $this->markUsed($table, $col);
            }

            // $casts (keys)
            if (preg_match('/protected\s+\$casts\s*=\s*(\[[^\]]*\])\s*;?/s', $code, $m)) {
                $arr = $m[1];
                if (preg_match_all('/[\'"]([A-Za-z0-9_]+)[\'"]\s*=>/s', $arr, $m2)) {
                    foreach ($m2[1] as $col) {
                        $this->markUsed($table, $col);
                    }
                }
            }

            // belongsTo => tebakan FK
            // pola: return $this->belongsTo(Target::class, 'fk', 'owner');
            if (preg_match_all('/->belongsTo\(([^\)]+)\)/s', $code, $rels)) {
                foreach ($rels[1] as $args) {
                    $fk = null;
                    if (preg_match_all('/[\'"]([A-Za-z0-9_]+)[\'"]/', $args, $am)) {
                        // argumen kedua biasanya FK
                        if (isset($am[1][0])) {
                            $fk = $am[1][0];
                        }
                    }
                    if (!$fk) {
                        // kalau argumen tak ada, default: snake(target_class)_id
                        if (preg_match('/([A-Za-z0-9_\\\\:]+)::class/', $args, $tm)) {
                            $target = class_basename(str_replace('::class', '', $tm[1]));
                            $fk = Str::snake($target) . '_id';
                        }
                    }
                    if ($fk) {
                        $this->markUsed($table, $fk);
                    }
                }
            }

            // timestamps & softDeletes jika terlihat dipakai
            if (Str::contains($code, 'SoftDeletes')) {
                $this->markUsed($table, 'deleted_at');
            }
            if (Str::contains($code, 'public $timestamps') || Str::contains($code, 'timestamps()')) {
                $this->markUsed($table, 'created_at');
                $this->markUsed($table, 'updated_at');
            }
        }
    }

    protected function scanFilament(string $root): void
    {
        $finder = (new Finder())
            ->files()
            ->in($root . '/app/Filament')
            ->name('*.php');

        foreach ($finder as $file) {
            $code = $file->getContents();

            // resource → model
            $modelFqn = $this->match('/protected\s+static\s+string\s+\$model\s*=\s*([A-Za-z0-9_\\\\:]+)::class\s*;/', $code, 1);
            $table = null;
            if ($modelFqn) {
                $table = $this->resolveTableFromModelFqn($modelFqn);
            }

            // ambil semua komponen ::make('field') pada Form/Table
            if (preg_match_all('/::make\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $code, $m)) {
                foreach ($m[1] as $field) {
                    if ($table) {
                        $this->markUsed($table, $field);
                    } else {
                        // kalau tak tahu table-nya, simpan ke keranjang khusus (akan diabaikan)
                        $this->markUsed('_unknown', $field);
                    }
                }
            }
        }
    }

    protected function scanBlades(string $root): void
    {
        $finder = (new Finder())
            ->files()
            ->in($root . '/resources/views')
            ->name('*.blade.php');

        // kamus variabel → tabel (heuristik umum yang sering muncul di project ini)
        $varMap = [
            'reservation'        => 'reservations',
            'res'                => 'reservations',
            'rg'                 => 'reservation_guests',
            'reservationGuest'   => 'reservation_guests',
            'guest'              => 'guests',
            'room'               => 'rooms',
            'card'               => 'cards',
            'payment'            => 'payments',
            'closing'            => 'daily_closings',
            'invoice'            => 'invoices',
            'invoiceItem'        => 'invoice_items',
            'receipt'            => 'minibar_receipts',
            'receiptItem'        => 'minibar_receipt_items',
            'item'               => 'minibar_items',
            'closingItem'        => 'minibar_daily_closing_items',
            'hotel'              => 'hotels',
            'tax'                => 'tax_settings',
            'group'              => 'reservation_groups',
            'bankLedger'         => 'bank_ledgers',
        ];

        foreach ($finder as $file) {
            $code = $file->getContents();

            // pola $var->field
            if (preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)->([A-Za-z_][A-Za-z0-9_]*)/', $code, $m)) {
                $vars = $m[1];
                $fields = $m[2];
                foreach ($vars as $i => $var) {
                    $field = $fields[$i];
                    $table = $varMap[$var] ?? null;
                    if ($table) {
                        $this->markUsed($table, $field);
                    }
                }
            }

            // pola $var['field']
            if (preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)\s*\[\s*[\'"]([A-Za-z0-9_]+)[\'"]\s*\]/', $code, $m2)) {
                $vars = $m2[1];
                $fields = $m2[2];
                foreach ($vars as $i => $var) {
                    $field = $fields[$i];
                    $table = $varMap[$var] ?? null;
                    if ($table) {
                        $this->markUsed($table, $field);
                    }
                }
            }
        }
    }

    /* ---------------------------------------------------------------------
     * Helpers
     * -------------------------------------------------------------------*/

    protected function markUsed(string $table, string $column): void
    {
        if (!$table || $table === '_unknown' || !$column) {
            return;
        }
        $this->used[$table][$column] = true;
    }

    protected function match(string $pattern, string $haystack, int $group = 0): ?string
    {
        if (preg_match($pattern, $haystack, $m)) {
            return $m[$group] ?? null;
        }
        return null;
    }

    protected function parsePhpArray(?string $arr): array
    {
        if (!$arr) return [];
        $out = [];
        if (preg_match_all('/[\'"]([A-Za-z0-9_\.]+)[\'"]/', $arr, $m)) {
            foreach ($m[1] as $v) $out[] = $v;
        }
        return array_values(array_unique($out));
    }

    protected function resolveTableFromModelFqn(string $fqnWithClassLiteral): ?string
    {
        $fqn = str_replace('::class', '', $fqnWithClassLiteral);
        $fqn = trim($fqn, '\\');

        // pakai cache dari pemetaan models
        if (isset($this->modelTableMap[$fqn])) {
            return $this->modelTableMap[$fqn];
        }

        // fallback: snake plural dari class basename
        $short = class_basename($fqn);
        return Str::snake(Str::pluralStudly($short));
    }

    protected function listTablesFromDb(): array
    {
        // portable untuk MySQL/MariaDB; gunakan Schema::getAllTables() jika versi Laravelmu support
        try {
            $connection = Schema::getConnection();
            $driver = $connection->getDriverName();
            if ($driver === 'mysql') {
                $db = $connection->getDatabaseName();
                $rows = $connection->select("SELECT table_name FROM information_schema.tables WHERE table_schema = ?", [$db]);
                return collect($rows)->map(fn($r) => Arr::get((array)$r, 'table_name'))->filter()->values()->all();
            }
        } catch (\Throwable $e) {
            // abaikan
        }
        return []; // best-effort
    }
}
