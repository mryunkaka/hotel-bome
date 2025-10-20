<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Hanya jalan kalau tabelnya memang ada
        if (! Schema::hasTable('card_assignments')) return;

        Schema::table('card_assignments', function (Blueprint $t) {
            // ====== Kolom wajib kita ======
            if (! Schema::hasColumn('card_assignments', 'reservation_id')) {
                $t->foreignId('reservation_id')->nullable()->after('card_id');
            }
            if (! Schema::hasColumn('card_assignments', 'room_id')) {
                $t->foreignId('room_id')->nullable()->after('reservation_id');
            }

            // Sesuaikan penamaan waktu valid
            $hasValidUntil = Schema::hasColumn('card_assignments', 'valid_until');
            $hasValidTo    = Schema::hasColumn('card_assignments', 'valid_to');

            if (! $hasValidUntil && ! $hasValidTo) {
                // Tidak ada keduanya → tambah baru
                $t->timestamp('valid_from')->nullable()->after('room_id');
                $t->timestamp('valid_until')->nullable()->after('valid_from')->index();
            } elseif (! $hasValidUntil && $hasValidTo) {
                // Ada valid_to lama → rename ke valid_until
                $t->renameColumn('valid_to', 'valid_until');
                if (! Schema::hasColumn('card_assignments', 'valid_from')) {
                    $t->timestamp('valid_from')->nullable()->before('valid_until');
                }
            } else {
                // Sudah punya valid_until → pastikan valid_from ada
                if (! Schema::hasColumn('card_assignments', 'valid_from')) {
                    $t->timestamp('valid_from')->nullable()->before('valid_until');
                }
            }

            // Kolom clone opsional
            if (! Schema::hasColumn('card_assignments', 'is_clone_of')) {
                $t->foreignId('is_clone_of')->nullable()->after('valid_until');
            }

            // Index basic
            if (! $this->hasIndex('card_assignments', 'card_assignments_valid_until_index')) {
                $t->index('valid_until');
            }
        });

        // Pasang foreign key terpisah (lebih aman untuk tabel lama yang sudah berisi data)
        Schema::table('card_assignments', function (Blueprint $t) {
            // FK card_id → cards.id (kalau belum ada)
            $this->addFkIfMissing($t, 'card_assignments', 'card_id', 'cards');

            // FK reservation_id → reservations.id
            if (Schema::hasColumn('card_assignments', 'reservation_id')) {
                $this->addFkIfMissing($t, 'card_assignments', 'reservation_id', 'reservations');
            }

            // FK room_id → rooms.id
            if (Schema::hasColumn('card_assignments', 'room_id')) {
                $this->addFkIfMissing($t, 'card_assignments', 'room_id', 'rooms');
            }

            // FK is_clone_of → cards.id
            if (Schema::hasColumn('card_assignments', 'is_clone_of')) {
                $this->addFkIfMissing($t, 'card_assignments', 'is_clone_of', 'cards');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('card_assignments')) return;

        Schema::table('card_assignments', function (Blueprint $t) {
            // Revert ringan (jangan drop kolom beresiko)
            if (Schema::hasColumn('card_assignments', 'is_clone_of')) {
                $t->dropConstrainedForeignId('is_clone_of');
            }
            // NOTE: Biarkan reservation_id, room_id, valid_from/valid_until tetap ada.
        });
    }

    // ===== Helpers untuk cek index/FK aman di runtime lama =====
    private function hasIndex(string $table, string $indexName): bool
    {
        try {
            $sm = Schema::getConnection()->getDoctrineSchemaManager();
            $indexes = $sm->listTableIndexes($table);
            return array_key_exists($indexName, $indexes);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function addFkIfMissing(Blueprint $t, string $table, string $col, string $refTable): void
    {
        try {
            $conn = Schema::getConnection();
            $sm   = $conn->getDoctrineSchemaManager();
            $doctrineTable = $sm->listTableDetails($table);
            $hasFk = false;
            foreach ($doctrineTable->getForeignKeys() as $fk) {
                if (in_array($col, $fk->getLocalColumns(), true)) {
                    $hasFk = true;
                    break;
                }
            }
            if (! $hasFk) {
                $t->foreign($col)->references('id')->on($refTable)->nullOnDelete();
            }
        } catch (\Throwable $e) {
            // Kalau Doctrine gagal, skip FK (bisa ditambahkan manual nanti)
        }
    }
};
