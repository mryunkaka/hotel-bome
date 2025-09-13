<?php

namespace App\Imports;

use App\Models\IncomeItem;
use App\Models\IncomeCategory;
use App\Support\ValueParsers;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Row;

class IncomeItemsImport implements OnEachRow, WithHeadingRow
{
    protected int $created = 0;
    protected int $updated = 0;
    protected int $skipped = 0;
    protected array $errors = [];

    public function onRow(Row $row)
    {
        $hid = (int) (session('active_hotel_id') ?? 0);
        $r   = $row->toArray();

        // Normalisasi
        $categoryName = isset($r['category']) ? trim((string) $r['category']) : null;
        $amountRaw    = $r['amount'] ?? null;
        $amount       = is_string($amountRaw) ? (float) str_replace([','], [''], $amountRaw) : (float) $amountRaw;
        $description  = isset($r['description']) ? trim((string) $r['description']) : null;

        // tanggal fleksibel (Asia/Singapore)
        $dateUtc = ValueParsers::parseDateFlexible($r['date'] ?? null, 'Asia/Singapore');
        if (! $dateUtc) {
            $this->skipped++;
            $this->errors[] = ['Tanggal tidak dikenali pada baris ' . $row->getIndex()];
            return;
        }

        // Validasi ringan
        $validator = Validator::make([
            'category' => $categoryName,
            'amount'   => $amount,
            'date'     => $dateUtc,
        ], [
            'category' => ['required', 'string', 'max:255'],
            'amount'   => ['required', 'numeric'],
            'date'     => ['required'],
        ]);

        if ($validator->fails()) {
            $this->skipped++;
            $this->errors[] = $validator->errors()->all();
            return;
        }

        // Resolve / create IncomeCategory untuk hotel aktif
        $category = IncomeCategory::query()
            ->where('hotel_id', $hid)
            ->where('name', $categoryName)
            ->first();

        if (! $category) {
            $category = IncomeCategory::create([
                'hotel_id'    => $hid,
                'name'        => $categoryName,
                'description' => null,
            ]);
        }

        // Payload item
        $payload = [
            'hotel_id'           => $hid,
            'income_category_id' => $category->id,
            'amount'             => $amount,
            'description'        => $description ?: null,
            'date'               => $dateUtc, // simpan UTC di DB
        ];

        // === UPSERT (hindari duplikat tidak disengaja) ===
        $existing = IncomeItem::query()
            ->where('hotel_id', $hid)
            ->where('income_category_id', $category->id)
            ->where('amount', $amount)
            ->where('description', $description ?: null)
            ->where(function ($q) use ($dateUtc) {
                // samakan menit (bila excel kasih tanpa detik)
                $q->whereBetween('date', [
                    $dateUtc->copy()->startOfMinute(),
                    $dateUtc->copy()->endOfMinute(),
                ]);
            })
            ->first();

        if ($existing) {
            $existing->fill($payload)->save();
            $this->updated++;
        } else {
            IncomeItem::create($payload);
            $this->created++;
        }
    }

    public function resultSummary(): array
    {
        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'errors'  => $this->errors,
        ];
    }
}
