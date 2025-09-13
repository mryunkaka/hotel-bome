<?php

namespace App\Imports;

use App\Models\Guest;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class GuestsImport implements ToModel, WithHeadingRow
{
    public function model(array $row)
    {
        $hid = (int) (session('active_hotel_id') ?? 0);

        // Normalisasi ringan
        $name    = isset($row['name']) ? trim((string) $row['name']) : null;
        $email   = isset($row['email']) ? mb_strtolower(trim((string) $row['email'])) : null;
        $phone   = isset($row['phone']) ? preg_replace('/\s+/', '', trim((string) $row['phone'])) : null;
        $address = $row['address'] ?? null;

        // Opsional: minimal wajib isi name atau (email/phone)
        if (! $name && ! $email && ! $phone) {
            throw ValidationException::withMessages([
                'name' => ['Minimal isi nama atau email/phone untuk setiap baris.'],
            ]);
        }

        $data = [
            'hotel_id'          => $hid,
            'name'              => $name,
            'email'             => $email ?: null,
            'phone'             => $phone ?: null,
            'address'           => $address ?: null,
            'nid_no'            => $row['nid_no']        ?? null,
            'passport_no'       => $row['passport_no']   ?? null,
            'father'            => $row['father']        ?? null,
            'mother'            => $row['mother']        ?? null,
            'spouse'            => $row['spouse']        ?? null,
            // file path (scan dll) sengaja TIDAK diimport dari Excel
        ];

        // === Mode sederhana: selalu buat Guest baru
        return new Guest($data);

        /* === Mode upsert (aktifkan jika ingin update berdasar email/phone):
        $query = Guest::query()->where('hotel_id', $hid);
        if ($email) {
            $query->where('email', $email);
        } elseif ($phone) {
            $query->where('phone', $phone);
        } else {
            $query->where('name', $name);
        }

        $existing = $query->first();
        if ($existing) {
            $existing->fill($data);
            return $existing;
        }
        return new Guest($data);
        */
    }
}
