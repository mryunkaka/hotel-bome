<?php

namespace App\Imports;

use App\Models\Bank;
use App\Models\BankLedger;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class BankLedgersImport implements ToModel, WithHeadingRow
{
    public function model(array $row)
    {
        $hid = (int) (session('active_hotel_id') ?? 0);

        // optional: kolom "bank" (nama). Jika ada, map ke bank_id hotel aktif.
        $bankId = null;
        if (!empty($row['bank'])) {
            $bankId = Bank::query()
                ->where('hotel_id', $hid)
                ->where('name', $row['bank'])
                ->value('id');
        }

        $parsed = self::parseExcelDate($row['date'] ?? null);

        return new BankLedger([
            'hotel_id'    => $hid,
            'bank_id'     => $bankId,
            'deposit'     => (float)($row['deposit'] ?? 0),
            'withdraw'    => (float)($row['withdraw'] ?? 0),
            // asumsikan kolom di DB bertipe DATE; jika DATETIME ubah ke ->toDateTimeString()
            'date'        => $parsed?->format('Y-m-d'),
            'description' => $row['description'] ?? null,
        ]);
    }

    private static function parseExcelDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') return null;

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_numeric($value)) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject($value));
            } catch (\Throwable $e) {
            }
        }

        if (is_string($value)) {
            $s = ltrim(trim($value), "'");
            if ($s === '') return null;

            $sNorm = str_replace(['\\', '.'], ['/', '/'], $s);

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
                'd-M-Y',
                'M d, Y H:i:s',
                'M d, Y',
                'd M Y H:i:s',
                'd M Y',
            ];

            foreach ($formats as $fmt) {
                try {
                    $dt = Carbon::createFromFormat($fmt, $sNorm);
                    if ($dt !== false) return $dt;
                } catch (\Throwable $e) {
                }
            }

            try {
                return Carbon::parse($sNorm);
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }
}
