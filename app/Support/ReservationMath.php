<?php

namespace App\Support;

use Illuminate\Support\Carbon;

final class ReservationMath
{
    public const EXTRA_BED_PRICE_DEFAULT = 100_000;
    public const LATE_PENALTY_PER_HOUR = 25_000;
    public const LATE_PENALTY_MAX_PERCENT_OF_BASE = 50; // % dari basic rate


    private static function getVal($src, string $key, $default = null)
    {
        if (is_array($src) && array_key_exists($key, $src)) return $src[$key];
        if (is_object($src) && isset($src->{$key})) return $src->{$key};
        return $default;
    }

    /**
     * Hitung denda keterlambatan check-in.
     *
     * @param  mixed       $expectedCheckin  (string|Carbon|null)
     * @param  mixed       $actualCheckin    (string|Carbon|null) kalau null → pakai now()
     * @param  float|int   $basicRate        room rate dasar (untuk batas maksimal %)
     * @param  array       $opts             ['tz' => 'Asia/Makassar', 'per_hour' => int, 'max_percent' => int]
     * @return array{hours:int, amount:int}
     */
    public static function latePenalty($expectedCheckin, $actualCheckin = null, $basicRate = 0, array $opts = []): array
    {
        $tz          = (string)($opts['tz'] ?? 'Asia/Makassar');
        $perHour     = (int)($opts['per_hour'] ?? self::LATE_PENALTY_PER_HOUR);
        $maxPercent  = (int)($opts['max_percent'] ?? self::LATE_PENALTY_MAX_PERCENT_OF_BASE);

        if (empty($expectedCheckin)) {
            return ['hours' => 0, 'amount' => 0];
        }

        try {
            $arrivalAt = $expectedCheckin instanceof Carbon
                ? $expectedCheckin->copy()
                : Carbon::parse($expectedCheckin);
            $arrivalAt = $arrivalAt->setTimezone($tz);

            $refTime = $actualCheckin
                ? ($actualCheckin instanceof Carbon ? $actualCheckin->copy() : Carbon::parse($actualCheckin))
                : Carbon::now();
            $refTime = $refTime->setTimezone($tz);

            if ($refTime->greaterThan($arrivalAt)) {
                $lateMins    = $arrivalAt->diffInMinutes($refTime);
                $penaltyHours = (int) ceil($lateMins / 60);
                $penaltyRp   = $penaltyHours * max(0, $perHour);

                if ($maxPercent > 0 && $basicRate > 0) {
                    $cap = (int) round(($basicRate * $maxPercent) / 100);
                    $penaltyRp = min($penaltyRp, $cap);
                }

                return ['hours' => $penaltyHours, 'amount' => (int) $penaltyRp];
            }
        } catch (\Throwable $e) {
            // fallback: no penalty
        }

        return ['hours' => 0, 'amount' => 0];
    }

    /**
     * Hitung nights (jumlah malam) dari tanggal check-in & check-out.
     * - Mengutamakan selisih hari kalender (startOfDay) -> konsisten slip/preview.
     * - Selalu minimal $min (default 1).
     * - Jika parsing gagal / input kosong: kembalikan $min.
     */
    public static function nights($checkin, $checkout, int $min = 1): int
    {
        if (empty($checkin) || empty($checkout)) {
            return max(1, $min);
        }

        try {
            $in  = $checkin instanceof Carbon ? $checkin->copy() : Carbon::parse($checkin);
            $out = $checkout instanceof Carbon ? $checkout->copy() : Carbon::parse($checkout);

            // Selisih hari kalender (bukan jam) → cocok untuk logika hotel
            $diff = $in->startOfDay()->diffInDays($out->startOfDay());

            // Minimal $min (biasanya 1 malam walau same-day)
            return max($min, (int) $diff);
        } catch (\Throwable $e) {
            return max(1, $min);
        }
    }

    private static function toNum($v): float
    {
        if ($v === null || $v === '') return 0.0;
        if (is_numeric($v)) return (float) $v;
        $s = trim((string) $v);
        $s = str_replace(['%', ' '], '', $s);
        if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
            // 1.234.567,89 -> 1234567.89
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } else {
            // 1.234.567 -> 1234567  |  1,234,567 -> 1234567
            $s = str_replace([',', ' '], '', $s);
        }
        $s = preg_replace('/[^0-9\.\-]/', '', $s);
        return is_numeric($s) ? (float) $s : 0.0;
    }

    private static function pct(float $p): float
    {
        return max(0.0, min(100.0, $p));
    }

    /**
     * Hitung final rate (diskon → pajak% → + extra bed + service [+ penalty]).
     *
     * $opts:
     *  - extra_bed_price (int) default 100_000
     *  - tax_lookup (array[id_tax=>percent])
     *  - service_taxable (bool) default false (service ikut basis pajak?)
     *  - rounding (int) default 0 (tanpa pembulatan). Contoh: 1000 untuk bulat ribuan.
     *  - allow_absolute_tax (bool) default false. Jika true dan ada nilai 'tax' besar (diasumsikan rupiah),
     *    akan ditambahkan di akhir perhitungan (jarang diperlukan).
     */
    public static function calcFinalRate($row, array $opts = []): float
    {
        $extraBedPrice    = (int)  ($opts['extra_bed_price'] ?? self::EXTRA_BED_PRICE_DEFAULT);
        $taxLookup        = (array)($opts['tax_lookup'] ?? []);
        $rounding         = (int)  ($opts['rounding'] ?? 0);
        $allowAbsoluteTax = (bool) ($opts['allow_absolute_tax'] ?? false);
        // NOTE: $opts['service_taxable'] diabaikan sesuai aturan baru (pajak dihitung SETELAH semua komponen dijumlahkan)

        // ==== BASE RATE ====
        $base = self::toNum(
            self::getVal(
                $row,
                'rate',
                self::getVal($row, 'unit_price', self::getVal($row, 'room_rate', 0))
            )
        );

        // ==== DISCOUNT % ====
        $disc = self::pct(self::toNum(self::getVal($row, 'discount_percent', self::getVal($row, 'discount', 0))));

        // ==== TAX % (tax_percent atau lookup) ====
        // Catatan: nilai 'tax' bisa rupiah absolut → jangan dipakai sebagai persen.
        $taxPercent = self::toNum(self::getVal($row, 'tax_percent', null));
        if ($taxPercent === 0.0) {
            $idTax = self::getVal($row, 'id_tax');
            if ($idTax !== null && isset($taxLookup[(int) $idTax])) {
                $taxPercent = self::toNum($taxLookup[(int) $idTax]);
            }
        }
        $taxPercent = self::pct($taxPercent);

        // ==== EXTRA BED (Rp) ====
        $extraBedTotal = self::toNum(self::getVal($row, 'extra_bed_total', null));
        if ($extraBedTotal === 0.0) {
            $extraQty       = (int) self::toNum(self::getVal($row, 'extra_bed', 0));
            $extraBedTotal  = $extraQty * $extraBedPrice;
        }

        // ==== SERVICE (Rp) & LATE PENALTY (Rp) ====
        $service     = (float) self::toNum(self::getVal($row, 'service', 0));
        $latePenalty = (float) self::toNum(self::getVal($row, 'late_arrival_penalty', 0));

        // ==== DISCOUNTED BASE ====
        $afterDisc = max(0.0, $base * (1 - $disc / 100));

        // ==== SUBTOTAL SEBELUM PAJAK (setelah semua komponen dijumlahkan) ====
        $subtotalBeforeTax = max(0.0, $afterDisc + $service + $extraBedTotal + $latePenalty);

        // ==== TAX % DI ATAS SUBTOTAL ====
        $percentTaxAmount = $taxPercent > 0 ? round($subtotalBeforeTax * $taxPercent / 100) : 0.0;

        // ==== ABSOLUTE TAX (opsional; jarang dipakai) ====
        $absoluteTaxRp = 0.0;
        if ($allowAbsoluteTax) {
            $rawTax = self::getVal($row, 'tax', null);
            if ($rawTax !== null) {
                $rawTaxNum = self::toNum($rawTax);
                // Heuristik: >100 dianggap rupiah absolut
                if ($rawTaxNum > 100) {
                    $absoluteTaxRp = $rawTaxNum;
                }
            }
        }

        // ==== FINAL ====
        $final = $subtotalBeforeTax + $percentTaxAmount + $absoluteTaxRp;

        // Pembulatan akhir (opsional)
        if ($rounding > 0) {
            $final = round($final / $rounding) * $rounding;
        }

        return (float) $final;
    }
}
