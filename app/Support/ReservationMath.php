<?php

namespace App\Support;

final class ReservationMath
{
    public const EXTRA_BED_PRICE_DEFAULT = 100_000;

    private static function getVal($src, string $key, $default = null)
    {
        if (is_array($src) && array_key_exists($key, $src)) return $src[$key];
        if (is_object($src) && isset($src->{$key})) return $src->{$key};
        return $default;
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
        $extraBedPrice     = (int) ($opts['extra_bed_price'] ?? self::EXTRA_BED_PRICE_DEFAULT);
        $taxLookup         = (array) ($opts['tax_lookup'] ?? []);
        $serviceTaxable    = (bool) ($opts['service_taxable'] ?? false);
        $rounding          = (int)  ($opts['rounding'] ?? 0);
        $allowAbsoluteTax  = (bool) ($opts['allow_absolute_tax'] ?? false);

        // ==== BASE RATE ====
        $base = self::toNum(
            self::getVal(
                $row,
                'rate',
                self::getVal(
                    $row,
                    'unit_price',
                    self::getVal($row, 'room_rate', 0)
                )
            )
        );

        // ==== DISCOUNT % ====
        $disc = self::pct(
            self::toNum(self::getVal($row, 'discount_percent', self::getVal($row, 'discount', 0)))
        );

        // ==== TAX % (hanya dari tax_percent atau lookup) ====
        // Catatan: nilai 'tax' MUNGKIN rupiah absolut di data; JANGAN langsung dipakai sebagai persen.
        $taxPercent = self::toNum(self::getVal($row, 'tax_percent', null));
        if ($taxPercent === 0.0) {
            $idTax = self::getVal($row, 'id_tax');
            if ($idTax !== null && isset($taxLookup[(int) $idTax])) {
                $taxPercent = self::toNum($taxLookup[(int) $idTax]);
            }
        }
        $taxPercent = self::pct($taxPercent);

        // ==== EXTRA BED ====
        // Jika ada total rupiah langsung, gunakan itu. Kalau tidak, pakai qty * price.
        $extraBedTotal = self::toNum(self::getVal($row, 'extra_bed_total', null));
        if ($extraBedTotal === 0.0) {
            $extraQty = (int) self::toNum(self::getVal($row, 'extra_bed', 0));
            $extraBedTotal = $extraQty * $extraBedPrice;
        }

        // ==== SERVICE (rupiah) ====
        $service = (float) self::toNum(self::getVal($row, 'service', 0));

        // ==== LATE ARRIVAL PENALTY (rupiah) ====
        $latePenalty = (float) self::toNum(self::getVal($row, 'late_arrival_penalty', 0));

        // ==== DISCOUNT ====
        $afterDisc = max(0.0, $base * (1 - $disc / 100));

        // ==== TAXABLE & TAX ====
        if ($serviceTaxable) {
            // Service ikut kena pajak
            $taxable  = $afterDisc + $service;
            $afterTax = $taxable * (1 + $taxPercent / 100);
        } else {
            // Service tidak kena pajak (default)
            $afterTax = $afterDisc * (1 + $taxPercent / 100);
        }

        // ==== ABSOLUTE TAX (opsional; jarang dipakai) ====
        $absoluteTaxRp = 0.0;
        $rawTax = self::getVal($row, 'tax', null);
        if ($allowAbsoluteTax && $rawTax !== null) {
            $rawTaxNum = self::toNum($rawTax);
            // Heuristik sederhana: jika > 100 dianggap rupiah absolut, kalau <= 100 anggap sudah ditangani tax_percent.
            if ($rawTaxNum > 100) {
                $absoluteTaxRp = $rawTaxNum;
            }
        }

        // ==== FINAL ====
        $final = $afterTax + $extraBedTotal + $service + $latePenalty + $absoluteTaxRp;

        // Pembulatan akhir (jika diminta)
        if ($rounding > 0) {
            $final = round($final / $rounding) * $rounding;
        }

        return $final;
    }
}
