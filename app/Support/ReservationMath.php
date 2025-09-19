<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use App\Models\ReservationGuest;

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

    public static function nights($checkin, $checkout, int $min = 1): int
    {
        if (empty($checkin) || empty($checkout)) {
            return max(1, $min);
        }

        try {
            $in  = $checkin instanceof Carbon ? $checkin->copy() : Carbon::parse($checkin);
            $out = $checkout instanceof Carbon ? $checkout->copy() : Carbon::parse($checkout);
            $diff = $in->startOfDay()->diffInDays($out->startOfDay());
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
            // 1.234.567 | 1,234,567 -> 1234567
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
     * Basic rate yang SELALU spesifik ke ReservationGuest yang dihitung.
     * - Utamakan $rg->room_rate
     * - Fallback ke $rg->room->price jika kosong.
     */
    public static function basicRate(ReservationGuest $rg): int
    {
        $rate = (float) ($rg->room_rate ?? 0);
        if ($rate > 0) return (int) round($rate);
        $roomPrice = (float) ($rg->room->price ?? 0);
        return (int) round($roomPrice);
    }

    /**
     * Denda terlambat CHECK-IN (ceil per jam), dibatasi % dari basic rate.
     * expectedCheckin: utamakan milik guest; fallback ke reservation.
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
                $lateMins     = $arrivalAt->diffInMinutes($refTime);
                $penaltyHours = (int) ceil($lateMins / 60);
                $penaltyRp    = $penaltyHours * max(0, $perHour);

                if ($maxPercent > 0 && $basicRate > 0) {
                    $cap = (int) round(($basicRate * $maxPercent) / 100);
                    $penaltyRp = min($penaltyRp, $cap);
                }

                return ['hours' => $penaltyHours, 'amount' => (int) $penaltyRp];
            }
        } catch (\Throwable $e) {
            // ignore -> no penalty
        }

        return ['hours' => 0, 'amount' => 0];
    }

    /**
     * Hitung total final dari satu "row" generik (dipakai jika mau).
     */
    public static function calcFinalRate($row, array $opts = []): float
    {
        $extraBedPrice    = (int)  ($opts['extra_bed_price'] ?? self::EXTRA_BED_PRICE_DEFAULT);
        $taxLookup        = (array)($opts['tax_lookup'] ?? []);
        $rounding         = (int)  ($opts['rounding'] ?? 0);
        $allowAbsoluteTax = (bool) ($opts['allow_absolute_tax'] ?? false);

        $base = self::toNum(
            self::getVal(
                $row,
                'rate',
                self::getVal($row, 'unit_price', self::getVal($row, 'room_rate', 0))
            )
        );

        $disc = self::pct(self::toNum(self::getVal($row, 'discount_percent', self::getVal($row, 'discount', 0))));

        $taxPercent = self::toNum(self::getVal($row, 'tax_percent', null));
        if ($taxPercent === 0.0) {
            $idTax = self::getVal($row, 'id_tax');
            if ($idTax !== null && isset($taxLookup[(int) $idTax])) {
                $taxPercent = self::toNum($taxLookup[(int) $idTax]);
            }
        }
        $taxPercent = self::pct($taxPercent);

        $extraBedTotal = self::toNum(self::getVal($row, 'extra_bed_total', null));
        if ($extraBedTotal === 0.0) {
            $extraQty       = (int) self::toNum(self::getVal($row, 'extra_bed', 0));
            $extraBedTotal  = $extraQty * $extraBedPrice;
        }

        $service     = (float) self::toNum(self::getVal($row, 'service', 0));
        $latePenalty = (float) self::toNum(self::getVal($row, 'late_arrival_penalty', 0));

        $afterDisc = max(0.0, $base * (1 - $disc / 100));
        $subtotalBeforeTax = max(0.0, $afterDisc + $service + $extraBedTotal + $latePenalty);

        $percentTaxAmount = $taxPercent > 0 ? round($subtotalBeforeTax * $taxPercent / 100) : 0.0;

        $absoluteTaxRp = 0.0;
        if ($allowAbsoluteTax) {
            $rawTax = self::getVal($row, 'tax', null);
            if ($rawTax !== null) {
                $rawTaxNum = self::toNum($rawTax);
                if ($rawTaxNum > 100) $absoluteTaxRp = $rawTaxNum;
            }
        }

        $final = $subtotalBeforeTax + $percentTaxAmount + $absoluteTaxRp;

        if ($rounding > 0) {
            $final = round($final / $rounding) * $rounding;
        }

        return (float) $final;
    }

    /**
     * Breakdown billing satu RG.
     */
    public static function guestBill(ReservationGuest $rg, array $opts = []): array
    {
        $tz = $opts['tz'] ?? 'Asia/Makassar';

        $start  = $rg->actual_checkin ?: $rg->expected_checkin;
        $end    = $rg->actual_checkout ?: Carbon::now($tz);
        $nights = self::nights($start, $end, 1);

        // === FIX UTAMA: pakai basicRate() supaya tidak “ketarik guest lain / harga terkini”.
        $rate     = (int) self::basicRate($rg);
        $discPct  = (float) ($rg->discount_percent ?? 0);
        $service  = (int) ($rg->service ?? 0);
        $extra    = (int) ($rg->extra_bed_total ?? 0);
        $taxPct   = (float) ($rg->tax_percent ?? ($rg->tax?->percent ?? 0));

        // Penalty: utamakan expected_checkin milik GUEST
        $expectedArrival = $rg->expected_checkin ?: ($rg->reservation?->expected_arrival);
        $pen = self::latePenalty($expectedArrival, $rg->actual_checkin, $rate, ['tz' => $tz]);
        $penalty = (int) ($pen['amount'] ?? 0);
        $penalty_hours = (int) ($pen['hours'] ?? 0);

        $discPerNight = (int) round(($rate * $discPct) / 100);
        $rateAfterDiscPerNight = max(0, $rate - $discPerNight);
        $roomAfterDisc = $rateAfterDiscPerNight * max(1, $nights);

        $subtotal = $roomAfterDisc + $service + $extra + $penalty;
        $taxRp    = (int) round(($subtotal * $taxPct) / 100);
        $grand    = $subtotal + $taxRp;
        $deposit  = (int) ($rg->reservation?->deposit ?? 0);
        $due      = max(0, $grand - $deposit);

        return [
            'nights'                      => $nights,
            'rate'                        => $rate,
            'disc_percent'                => $discPct,
            'disc_per_night'              => $discPerNight,
            'rate_after_disc_per_night'   => $rateAfterDiscPerNight,
            'room_after_disc'             => $roomAfterDisc,
            'service'                     => $service,
            'extra'                       => $extra,
            'penalty'                     => $penalty,
            'penalty_hours'               => $penalty_hours,
            'tax_percent'                 => $taxPct,
            'tax_rp'                      => $taxRp,
            'grand'                       => $grand,
            'deposit'                     => $deposit,
            'due'                         => $due,
        ];
    }
}
