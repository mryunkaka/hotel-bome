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
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } else {
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
     * Hitung final rate (diskon → pajak → + extra bed + service).
     * $opts:
     *  - extra_bed_price (int) default 100_000
     *  - tax_lookup (array[id_tax=>percent])
     *  - service_taxable (bool) default false (set true kalau service ikut kena pajak)
     */
    public static function calcFinalRate($row, array $opts = []): float
    {
        $extraBedPrice  = (int)($opts['extra_bed_price'] ?? self::EXTRA_BED_PRICE_DEFAULT);
        $taxLookup      = (array)($opts['tax_lookup'] ?? []);
        $serviceTaxable = (bool)($opts['service_taxable'] ?? false);

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

        $disc = self::pct(self::toNum(
            self::getVal($row, 'discount_percent', self::getVal($row, 'discount', 0))
        ));

        $tax = self::toNum(self::getVal($row, 'tax_percent', self::getVal($row, 'tax', null)));
        if ($tax === 0.0) {
            $idTax = self::getVal($row, 'id_tax');
            if ($idTax !== null && isset($taxLookup[(int)$idTax])) {
                $tax = self::toNum($taxLookup[(int)$idTax]);
            }
        }
        $tax = self::pct($tax);

        $extraQty = (int) self::toNum(self::getVal($row, 'extra_bed', 0));
        $extra    = $extraQty * $extraBedPrice;

        $service  = (float) self::toNum(self::getVal($row, 'service', 0));

        $afterDisc = max(0.0, $base * (1 - $disc / 100));

        if ($serviceTaxable) {
            // service ikut basis pajak
            $taxable = $afterDisc + $service;
            $afterTax = $taxable * (1 + $tax / 100);
            return $afterTax + $extra;
        }

        // service tidak kena pajak (default)
        $afterTax = $afterDisc * (1 + $tax / 100);
        return $afterTax + $extra + $service;
    }
}
