<?php

namespace App\Imports;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Row;

class UsersImport implements OnEachRow, WithHeadingRow
{
    protected int $created = 0;
    protected int $updated = 0;
    protected int $skipped = 0;
    protected array $errors = [];

    public function onRow(Row $row)
    {
        $hid = (int) (session('active_hotel_id') ?? 0);
        $r   = $row->toArray();

        $name     = isset($r['name']) ? trim((string) $r['name']) : null;
        $emailRaw = $r['email'] ?? null;
        $email    = $emailRaw ? mb_strtolower(trim((string) $emailRaw)) : null;
        $password = isset($r['password']) ? (string) $r['password'] : null;

        // Email wajib untuk user
        $validator = Validator::make(
            ['email' => $email, 'name' => $name],
            [
                'email' => ['required', 'email'],
                'name'  => ['nullable', 'string', 'max:255'],
            ]
        );

        if ($validator->fails()) {
            $this->skipped++;
            $this->errors[] = $validator->errors()->all();
            return;
        }

        // Payload dasar
        $payload = [
            'hotel_id' => $hid,
            'name'     => $name,
            'email'    => $email,
        ];

        // Upsert by (hotel_id, email)
        $existing = User::query()
            ->when($hid, fn($q) => $q->where('hotel_id', $hid))
            ->where('email', $email)
            ->first();

        if ($existing) {
            $existing->fill($payload);

            // Update password hanya jika kolom password diisi
            if (filled($password)) {
                $existing->password = Hash::make($password);
            }

            $existing->save();
            $this->updated++;
        } else {
            // Password wajib saat create; kalau kosong beri default sederhana (silakan ubah)
            if (!filled($password)) {
                // kamu bisa ganti default ini sesuai kebijakan
                $password = 'ChangeMe123!';
            }
            $payload['password'] = Hash::make($password);

            User::create($payload);
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
