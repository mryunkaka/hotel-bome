<?php

namespace App\Imports;

use App\Models\Hotel;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Row;

class HotelsImport implements OnEachRow, WithHeadingRow
{
    protected int $created = 0;
    protected int $updated = 0;
    protected int $skipped = 0;
    protected array $errors = [];

    public function onRow(Row $row)
    {
        $r = $row->toArray();

        $name    = isset($r['name']) ? trim((string) $r['name']) : null;
        $tipe    = isset($r['tipe']) ? trim((string) $r['tipe']) : null;
        $email   = isset($r['email']) ? mb_strtolower(trim((string) $r['email'])) : null;
        $phone   = isset($r['phone']) ? preg_replace('/\s+/', '', trim((string) $r['phone'])) : null;
        $address = isset($r['address']) ? trim((string) $r['address']) : null;
        $noReg   = isset($r['no_reg']) ? trim((string) $r['no_reg']) : null;

        $validator = Validator::make(compact('name', 'email'), [
            'name'  => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email'],
        ]);

        if ($validator->fails()) {
            $this->skipped++;
            $this->errors[] = $validator->errors()->all();
            return;
        }

        $payload = [
            'name'    => $name,
            'tipe'    => $tipe,
            'email'   => $email ?: null,
            'phone'   => $phone ?: null,
            'address' => $address ?: null,
            'no_reg'  => $noReg ?: null,
        ];

        $query = Hotel::query();
        if ($noReg) {
            $query->where('no_reg', $noReg);
        } elseif ($email) {
            $query->where('email', $email);
        } elseif ($name) {
            $query->where('name', $name);
        }

        $existing = $query->first();
        if ($existing) {
            $existing->fill($payload)->save();
            $this->updated++;
        } else {
            Hotel::create($payload);
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
