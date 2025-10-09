<?php

namespace App\Support;

use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlsDate;

class ValueParsers
{
    /**
     * Parse tanggal fleksibel:
     * - Excel serial (angka)
     * - Format umum: Y-m-d[ H:i[:s]], d/m/Y[ H:i[:s]], m/d/Y[ H:i[:s]], d-m-Y, m-d-Y
     * - "now" dst via strtotime
     *
     * @param mixed  $value   Nilai dari Excel (string|int|float|\DateTimeInterface|null)
     * @param string $tzInput Timezone input (default Asia/Singapore)
     * @return \Carbon\Carbon|null  (DIKEMBALIKAN DALAM UTC)
     */
    public static function parseDateFlexible(mixed $value, string $tzInput = 'Asia/Singapore'): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        // 1) Sudah DateTimeInterface
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->timezone($tzInput)->utc();
        }

        // Helper: parse sebagai Excel serial (days atau fraction)
        $parseAsExcelSerial = function (float $n) use ($tzInput): ?Carbon {
            try {
                $dt = XlsDate::excelToDateTimeObject($n);       // \DateTime basis 1899-12-31
                $c  = Carbon::instance($dt)->timezone($tzInput);
                // Jika masih di origin Excel (1899/1900/1901) ⇒ anggap time-only (fraction-of-day)
                if ((int) $c->year <= 1901) {
                    $base = Carbon::now($tzInput)->startOfDay()
                        ->setTime($c->hour, $c->minute, $c->second);
                    return $base->utc();
                }
                return $c->utc();
            } catch (\Throwable) {
                return null;
            }
        };

        // 2) Numeric murni
        if (is_int($value) || is_float($value)) {
            $n = (float) $value;

            // Coba sebagai Excel serial dulu (45910, 45678.75, 0.53, dll.)
            if (($asExcel = $parseAsExcelSerial($n)) !== null) {
                return $asExcel;
            }

            // Heuristik: 1..86399 → detik sejak 00:00 (time-only)
            if ($n >= 1 && $n < 86400) {
                $base = Carbon::now($tzInput)->startOfDay()->addSeconds((int) $n);
                return $base->utc();
            }
        }

        // 3) String
        $str = trim((string) $value);
        if ($str === '') {
            return null;
        }

        // String numeric → coba serial / detik-of-day
        if (preg_match('/^[+-]?(?:\d+\.?\d*|\.\d+)$/', $str)) {
            $n = (float) $str;

            if (($asExcel = $parseAsExcelSerial($n)) !== null) {
                return $asExcel;
            }

            if ($n >= 1 && $n < 86400) {
                $base = Carbon::now($tzInput)->startOfDay()->addSeconds((int) $n);
                return $base->utc();
            }
        }

        // Time-only "HH:MM[:SS]"
        if (preg_match('/^\s*(\d{1,2}):(\d{2})(?::(\d{2}))?\s*$/', $str, $m)) {
            [$all, $h, $i, $s] = $m + [null, 0, 0, 0];
            $base = Carbon::now($tzInput)->startOfDay()
                ->addHours((int) $h)
                ->addMinutes((int) $i)
                ->addSeconds((int) $s);
            return $base->utc();
        }

        // ISO-like
        try {
            return Carbon::parse($str, $tzInput)->utc();
        } catch (\Throwable) {
            // lanjut ke patterns
        }

        // Patterns umum
        $patterns = [
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'Y-m-d',
            'd/m/Y H:i:s',
            'd/m/Y H:i',
            'd/m/Y',
            'm/d/Y H:i:s',
            'm/d/Y H:i',
            'm/d/Y',
            'd-m-Y H:i:s',
            'd-m-Y H:i',
            'd-m-Y',
            'm-d-Y H:i:s',
            'm-d-Y H:i',
            'm-d-Y',
        ];

        foreach ($patterns as $fmt) {
            try {
                return Carbon::createFromFormat($fmt, $str, $tzInput)->utc();
            } catch (\Throwable) {
                // try next
            }
        }

        // Hindari fallback epoch
        return null;
    }
}
