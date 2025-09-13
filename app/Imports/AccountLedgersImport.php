<?php

namespace App\Imports;

use App\Models\AccountLedger;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class AccountLedgersImport implements ToModel, WithHeadingRow
{
    public function model(array $row)
    {
        $hid = (int) (session('active_hotel_id') ?? 0);

        $parsed = self::parseExcelDate($row['date'] ?? null);

        return new AccountLedger([
            'hotel_id'    => $hid,
            'debit'       => (float)($row['debit'] ?? 0),
            'credit'      => (float)($row['credit'] ?? 0),
            // Jika kolom DB bertipe DATE:
            'date'        => $parsed?->format('Y-m-d'),
            // Jika kolom DB bertipe DATETIME, pakai ini:
            // 'date'     => $parsed?->toDateTimeString(),
            'method'      => $row['method'] ?? null,
            'description' => $row['description'] ?? null,
        ]);
    }

    /**
     * Terima berbagai bentuk tanggal dari Excel:
     * - numeric serial (45901)
     * - string dengan / atau - (d/m/Y atau m/d/Y), nama bulan, dll
     * - DateTimeInterface
     */
    private static function parseExcelDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Jika sudah DateTime/Carbon
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        // Jika numeric → serial Excel
        if (is_numeric($value)) {
            try {
                $dt = ExcelDate::excelToDateTimeObject($value); // dukung pecahan (jam/menit)
                return Carbon::instance($dt);
            } catch (\Throwable $e) {
                // lanjut ke percobaan string
            }
        }

        // Jika string
        if (is_string($value)) {
            // Buang apostrophe pembuka dari Excel: '9/1/2025
            $s = ltrim(trim($value), "'");

            if ($s === '') {
                return null;
            }

            // Normalisasi beberapa pemisah
            $sNorm = str_replace(['\\', '.'], ['/', '/'], $s);

            // Urutan format: default **day-first** (umum di ID),
            // lalu **month-first**, kemudian ISO & variasi nama bulan.
            $formats = [
                'd/m/Y H:i:s',
                'd/m/Y H:i',
                'd/m/Y',
                'm/d/Y H:i:s',
                'm/d/Y H:i',
                'm/d/Y',
                'Y-m-d H:i:s',
                'Y-m-d',
                'Y/m/d H:i:s',
                'Y/m/d',
                'd-m-Y H:i:s',
                'd-m-Y',
                'm-d-Y H:i:s',
                'm-d-Y',
                'd-M-Y H:i:s',
                'd-M-Y',      // 1-Sep-2025
                'j-M-Y',                      // 1-Sep-2025 (tanpa leading zero)
                'M d, Y H:i:s',
                'M d, Y',    // Sep 1, 2025
                'd M Y H:i:s',
                'd M Y',      // 1 Sep 2025
            ];

            foreach ($formats as $fmt) {
                try {
                    $dt = Carbon::createFromFormat($fmt, $sNorm);
                    if ($dt !== false) {
                        return $dt;
                    }
                } catch (\Throwable $e) {
                    // coba format berikutnya
                }
            }

            // Terakhir: parser bebas (cenderung mm/dd/yy untuk format mirip US)
            try {
                return Carbon::parse($sNorm);
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }
}
