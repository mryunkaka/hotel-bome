<?php

namespace App\Imports;

use App\Models\Bank;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class BanksImport implements ToModel, WithHeadingRow
{
    public function model(array $row)
    {
        $hid = (int) (session('active_hotel_id') ?? 0);

        // account_no bisa datang numeric → paksa string tanpa desimal
        $accountNo = $row['account_no'] ?? null;
        if (is_numeric($accountNo)) {
            // NB: kalau di Excel aslinya hilang leading zero, kita tidak bisa re-cover.
            // Pastikan user menyimpan kolom ini sebagai Text saat input manual.
            $accountNo = rtrim(rtrim(sprintf('%.0f', (float) $accountNo), '0'), '.');
        } elseif (is_string($accountNo)) {
            $accountNo = trim($accountNo);
        }

        return new Bank([
            'hotel_id'  => $hid,
            'name'      => $row['name'] ?? null,
            'branch'    => $row['branch'] ?? null,
            'account_no' => $accountNo,
            'address'   => $row['address'] ?? null,
            'phone'     => $row['phone'] ?? null,
            'email'     => $row['email'] ?? null,
        ]);
    }
}
