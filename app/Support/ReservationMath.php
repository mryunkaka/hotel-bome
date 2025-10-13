<?php

namespace App\Support;

use App\Models\Room;
use App\Models\MinibarReceipt;
use Illuminate\Support\Carbon;
use App\Models\ReservationGuest;
use App\Models\MinibarReceiptItem;
use Illuminate\Support\Facades\Schema as DBSchema;

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

            // pakai actual jika ada; kalau tidak ada, anggap "sekarang"
            $refTime = $actualCheckin
                ? ($actualCheckin instanceof Carbon ? $actualCheckin->copy() : Carbon::parse($actualCheckin))
                : Carbon::now();
            $refTime = $refTime->setTimezone($tz);

            // Perubahan: penalti untuk early check-in (refTime < arrivalAt)
            if ($refTime->lessThan($arrivalAt)) {
                $earlyMins    = $refTime->diffInMinutes($arrivalAt);
                $penaltyHours = (int) ceil($earlyMins / 60);
                $penaltyRp    = $penaltyHours * max(0, $perHour);

                if ($maxPercent > 0 && $basicRate > 0) {
                    $cap = (int) round(($basicRate * $maxPercent) / 100);
                    $penaltyRp = min($penaltyRp, $cap);
                }

                return ['hours' => $penaltyHours, 'amount' => (int) $penaltyRp];
            }
        } catch (\Throwable $e) {
            // abaikan error parsing → tanpa penalty
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

        $charge      = (float) self::toNum(self::getVal($row, 'charge', 0));
        $latePenalty = (float) self::toNum(self::getVal($row, 'late_arrival_penalty', 0));

        $afterDisc = max(0.0, $base * (1 - $disc / 100));
        $subtotalBeforeTax = max(0.0, $afterDisc + $charge + $extraBedTotal + $latePenalty);

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

        // Pakai basicRate() (tidak ketarik harga lain).
        $rate     = (int) self::basicRate($rg);
        $discPct  = (float) ($rg->discount_percent ?? 0);
        $charge   = (int) ($rg->charge ?? 0);
        $extra    = (int) ($rg->extra_bed_total ?? 0);
        $taxPct   = (float) ($rg->tax_percent ?? ($rg->tax?->percent ?? 0));

        $expectedArrival = $rg->expected_checkin ?: ($rg->reservation?->expected_arrival);
        $pen = self::latePenalty($expectedArrival, $rg->actual_checkin, $rate, ['tz' => $tz]);
        $penalty = (int) ($pen['amount'] ?? 0);
        $penalty_hours = (int) ($pen['hours'] ?? 0);

        $discPerNight = (int) round(($rate * $discPct) / 100);
        $rateAfterDiscPerNight = max(0, $rate - $discPerNight);
        $roomAfterDisc = $rateAfterDiscPerNight * max(1, $nights);

        $subtotal = $roomAfterDisc + $charge + $extra + $penalty;
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
            'charge'                      => $charge,
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

    /**
     * Subtotal Minibar untuk RG.
     */
    public static function minibarSubtotal(ReservationGuest $g): int
    {
        return (int) MinibarReceiptItem::query()
            ->whereHas('receipt', fn($q) => $q->where('reservation_guest_id', $g->id))
            ->sum('line_total');
    }

    /**
     * Service% untuk RG (ambil dari reservation/service setting).
     */
    public static function servicePercent(ReservationGuest $g): float
    {
        return (float) ($g->reservation?->service_percent ?? ($g->reservation?->service?->percent ?? 0));
    }

    /**
     * Pajak% untuk RG (ambil dari reservation/tax setting).
     */
    public static function taxPercent(ReservationGuest $g): float
    {
        return (float) ($g->reservation?->tax?->percent ?? 0);
    }

    /**
     * Perhitungan "base" (sebelum pajak) utk satu RG, lengkap komponennya.
     * base = (rate after disc × nights) + charge + minibar + service(minibar×svc%) + extra + penalty
     */
    public static function guestBase(ReservationGuest $g): array
    {
        // Nights (guest-first expected)
        $in  = $g->actual_checkin ?: $g->expected_checkin;
        $out = $g->actual_checkout ?: Carbon::now('Asia/Makassar');
        $n   = self::nights($in, $out, 1);

        // Rate dan diskon
        $rate      = (float) self::basicRate($g);
        $discPct   = (float) ($g->discount_percent ?? 0);
        $discAmt   = (int) round(($rate * $discPct) / 100);
        $rateAfter = max(0, $rate - $discAmt);

        $roomAfter = (int) ($rateAfter * $n);

        // Charge, extra bed
        $chargeRp = (int) ($g->charge ?? 0);
        $extraRp  = (int) ($g->extra_bed_total ?? ((int) ($g->extra_bed ?? 0) * 100_000));

        // Minibar & service
        $minibarSub = self::unpaidMinibarSubtotal($g);
        $svcPct     = self::servicePercent($g);
        $serviceRp  = (int) round(($minibarSub * $svcPct) / 100);

        // Penalty
        $pen = self::latePenalty(
            $g->expected_checkin ?: $g->reservation?->expected_arrival,
            $g->actual_checkin,
            $rate,
            ['tz' => 'Asia/Makassar'],
        );
        $penaltyRp = (int) ($pen['amount'] ?? 0);

        $base = $roomAfter + $chargeRp + $minibarSub + $serviceRp + $extraRp + $penaltyRp;

        return [
            'nights'     => $n,
            'rate'       => $rate,
            'rate_after' => $rateAfter,
            'room_after' => $roomAfter,
            'charge'     => $chargeRp,
            'extra'      => $extraRp,
            'minibar'    => $minibarSub,
            'service'    => $serviceRp,
            'penalty'    => $penaltyRp,
            'base'       => (int) $base,
        ];
    }

    /**
     * Pajak total reservasi (SUM base semua RG × tax%) dan pajak rata per-guest.
     */
    public static function taxShareForReservation(ReservationGuest $current): array
    {
        $res = $current->reservation;
        if (!$res) {
            return ['tax_total' => 0, 'tax_per_guest' => 0, 'count' => 1, 'percent' => 0.0];
        }

        $guests = $res->reservationGuests ?? [];
        $count  = max(1, (int) count($guests));
        $pct    = (float) ($res->tax?->percent ?? 0);

        $sumBase = 0;
        foreach ($guests as $g) {
            $sumBase += (int) self::guestBase($g)['base'];
        }

        $taxTotal    = (int) round(($sumBase * $pct) / 100);
        $taxPerGuest = (int) round($taxTotal / $count);

        return [
            'tax_total'     => $taxTotal,
            'tax_per_guest' => $taxPerGuest,
            'count'         => $count,
            'percent'       => $pct,
            'sum_base'      => (int) $sumBase,
        ];
    }

    /**
     * Deposit untuk RG.
     */
    public static function deposits(ReservationGuest $g): array
    {
        $room = (int) ($g->deposit_room ?? 0);
        $card = (int) ($g->deposit_card ?? 0);
        return [
            'room'  => $room,
            'card'  => $card,
            'total' => $room + $card,
        ];
    }

    /**
     * Amount Due per-guest untuk tabel "Guest Information" (TANPA pajak):
     *   base - (deposit_room + deposit_card)
     */
    public static function amountDueGuestInfo(ReservationGuest $g): int
    {
        $base = (int) self::guestBase($g)['base'];
        $dep  = self::deposits($g)['total'];
        return max(0, $base - $dep);
    }

    /**
     * Subtotal untuk "Guest Bill" (sudah termasuk pajak per-guest dibagi rata BILLING),
     * dan sudah mengurangi deposit guest (room+card).
     */
    public static function subtotalGuestBill(ReservationGuest $g): array
    {
        $base   = (int) self::guestBaseForBilling($g)['base'];
        $share  = self::taxShareForReservationBilling($g);
        $dep    = self::deposits($g);

        $subtotal = max(0, ($base + (int) $share['tax_per_guest']) - (int) $dep['total']);

        return [
            // komponen yang bisa dipakai di Blade langsung
            'rate_after_disc_times_nights' => (int) self::guestBaseForBilling($g)['rate_after_times_nights'],
            'charge'        => (int) self::guestBaseForBilling($g)['charge'],
            'minibar'       => (int) self::guestBaseForBilling($g)['minibar_unpaid'],
            'service'       => (int) self::guestBaseForBilling($g)['service_minibar_unpaid'],
            'extra'         => (int) self::guestBaseForBilling($g)['extra'],
            'penalty'       => (int) self::guestBaseForBilling($g)['penalty'],

            'base'          => $base,
            'tax_per_guest' => (int) $share['tax_per_guest'],
            'deposit_room'  => (int) $dep['room'],
            'deposit_card'  => (int) $dep['card'],
            'subtotal'      => (int) $subtotal,
        ];
    }

    /**
     * Aggregasi untuk footer “Guest Information” dengan base BILLING (minibar unpaid only).
     */
    public static function aggregateGuestInfoFooter(ReservationGuest $current): array
    {
        $res = $current->reservation;
        $guests = $res?->reservationGuests ?? [];

        $sumBase = $sumTax = $sumDepRoom = $sumDepCard = 0;
        $sumBaseAfterDeps = $checkedGrand = 0;

        $pct = (float) ($res?->tax?->percent ?? 0);

        foreach ($guests as $g) {
            $gb   = self::guestBaseForBilling($g);
            $base = (int) $gb['base'];
            $tax  = (int) round(($base * $pct) / 100);

            $dep = self::deposits($g);
            $depTotal = (int) $dep['total'];
            $amountDueNoTax = max(0, $base - $depTotal);

            $sumBase          += $base;
            $sumTax           += $tax;
            $sumDepRoom       += (int) $dep['room'];
            $sumDepCard       += (int) $dep['card'];
            $sumBaseAfterDeps += $amountDueNoTax;

            if (filled($g->actual_checkout)) {
                $checkedGrand += ($amountDueNoTax + $tax);
            }
        }

        $totalDueAll = $sumBaseAfterDeps + $sumTax;
        $toPayNow    = max(0, $totalDueAll - $checkedGrand);

        return [
            'sum_base'                 => (int) $sumBase,
            'sum_tax'                  => (int) $sumTax,
            'sum_dep_room'             => (int) $sumDepRoom,
            'sum_dep_card'             => (int) $sumDepCard,
            'sum_base_after_deposits'  => (int) $sumBaseAfterDeps,
            'total_due_all'            => (int) $totalDueAll,
            'checked_grand'            => (int) $checkedGrand,
            'to_pay_now'               => (int) $toPayNow,
        ];
    }

    /**
     * View-model baris per-guest untuk dokumen BILL.
     * Supaya Blade cukup render angka.
     */
    public static function billRow(ReservationGuest $g, string $tz = 'Asia/Makassar'): array
    {
        $in  = $g->actual_checkin ?: $g->expected_checkin;
        $out = $g->actual_checkout ?: Carbon::now($tz);
        $n   = self::nights($in, $out, 1);

        $rate = (int) self::basicRate($g);
        $disc = (float) ($g->discount_percent ?? 0);
        $after = (int) self::guestBaseForBilling($g)['rate_after'];
        $afterTimesNights = (int) self::guestBaseForBilling($g)['rate_after_times_nights'];

        $b = self::guestBaseForBilling($g);
        $base = (int) $b['base'];

        $taxPct = (float) ($g->reservation?->tax?->percent ?? 0);
        $taxRp  = (int) round(($base * $taxPct) / 100);

        return [
            'guest_name'   => $g->guest?->name ?? '-',
            'room_no'      => $g->room?->room_no,
            'room_type'    => $g->room?->type,
            'rate'         => $rate,
            'disc_pct'     => $disc,
            'nights'       => $n,
            'checkin'      => $in,
            'checkout'     => $out,
            'status'       => filled($g->actual_checkout) ? 'CO' : 'IH',

            'rate_after_times_nights' => $afterTimesNights,
            'charge'     => (int) $b['charge'],
            'service'    => (int) $b['service_minibar_unpaid'],
            'extra'      => (int) $b['extra'],
            'penalty'    => (int) $b['penalty'],
            'amount'     => (int) $base,  // Amount (before tax)

            'tax_rp'     => $taxRp,       // jika butuh total termasuk pajak (mode=all)
        ];
    }

    /**
     * Cek apakah RG ini masih punya MINIBAR yang belum dibayar.
     * Prioritas indikator: `status` → `is_paid` → fallback (tidak dihitung).
     */
    public static function hasUnpaidMinibar(ReservationGuest $rg): bool
    {
        $res = $rg->reservation;
        if (! $res) return false;

        $q = MinibarReceipt::query()->where('reservation_guest_id', $rg->id);

        if (DBSchema::hasColumn('minibar_receipts', 'status')) {
            $q->where('status', '!=', 'PAID');
        } elseif (DBSchema::hasColumn('minibar_receipts', 'is_paid')) {
            $q->where(function ($qq) {
                $qq->whereNull('is_paid')->orWhere('is_paid', false);
            });
        } else {
            return false; // tak ada indikator → anggap tidak ada due agar tidak false positive
        }

        return $q->exists();
    }

    /**
     * Total MINIBAR DUE untuk RG:
     *   = SUM(total_amount unpaid) + service(minibar) + tax(minibar+service)
     * Unpaid mengikuti prioritas indikator: `status` → `is_paid`.
     */
    public static function minibarDue(ReservationGuest $rg): int
    {
        $res = $rg->reservation;
        if (! $res) return 0;

        $baseQuery = MinibarReceipt::query()->where('reservation_guest_id', $rg->id);

        if (DBSchema::hasColumn('minibar_receipts', 'status')) {
            $baseQuery->where('status', '!=', 'PAID');
        } elseif (DBSchema::hasColumn('minibar_receipts', 'is_paid')) {
            $baseQuery->where(function ($q) {
                $q->whereNull('is_paid')->orWhere('is_paid', false);
            });
        } else {
            return 0;
        }

        // jumlahkan hanya receipt yang benar-benar masih unpaid
        $minibarSub = (int) $baseQuery->sum('total_amount');
        if ($minibarSub <= 0) return 0;

        // service & tax ambil dari reservation setting
        $svcPct    = (float) ($res->service_percent ?? ($res->service->percent ?? 0));
        $serviceRp = (int) round(($minibarSub * $svcPct) / 100);

        $taxPct  = (float) ($res->tax->percent ?? 0);
        $taxBase = $minibarSub + $serviceRp;
        $taxRp   = (int) round(($taxBase * $taxPct) / 100);

        return (int) ($taxBase + $taxRp);
    }

    /**
     * Subtotal MINIBAR yang masih UNPAID (tanpa service & tax).
     * Sumber data mengikuti indikator: status!='PAID' atau is_paid=false.
     */
    public static function unpaidMinibarSubtotal(ReservationGuest $g): int
    {
        $q = MinibarReceipt::query()->where('reservation_guest_id', $g->id);

        if (DBSchema::hasColumn('minibar_receipts', 'status')) {
            $q->where('status', '!=', 'PAID');
        } elseif (DBSchema::hasColumn('minibar_receipts', 'is_paid')) {
            $q->where(function ($qq) {
                $qq->whereNull('is_paid')->orWhere('is_paid', false);
            });
        } else {
            // tidak ada indikator — anggap tidak ada due untuk mencegah double-collect
            return 0;
        }

        return (int) $q->sum('total_amount'); // asumsi total_amount = subtotal item (tanpa service/tax)
    }

    /**
     * Service nominal hanya untuk MINIBAR UNPAID.
     */
    public static function unpaidMinibarService(ReservationGuest $g): int
    {
        $svcPct = (float) ($g->reservation?->service_percent ?? ($g->reservation?->service?->percent ?? 0));
        $minibarSub = self::unpaidMinibarSubtotal($g);
        return (int) round(($minibarSub * $svcPct) / 100);
    }

    /**
     * Base utk kebutuhan BILLING: menghitung MINIBAR hanya yang UNPAID.
     * base = (rateAfter×n) + charge + extra + penalty + minibar_unpaid + service(minibar_unpaid)
     */
    public static function guestBaseForBilling(ReservationGuest $g): array
    {
        $in  = $g->actual_checkin ?: $g->expected_checkin;
        $out = $g->actual_checkout ?: Carbon::now('Asia/Makassar');
        $n   = self::nights($in, $out, 1);

        $rate      = (float) self::basicRate($g);
        $discPct   = (float) ($g->discount_percent ?? 0);
        $discAmt   = (int) round(($rate * $discPct) / 100);
        $rateAfter = max(0, $rate - $discAmt);

        $roomAfter = (int) ($rateAfter * $n);
        $chargeRp  = (int) ($g->charge ?? 0);
        $extraRp   = (int) ($g->extra_bed_total ?? ((int) ($g->extra_bed ?? 0) * self::EXTRA_BED_PRICE_DEFAULT));

        $pen = self::latePenalty(
            $g->expected_checkin ?: $g->reservation?->expected_arrival,
            $g->actual_checkin,
            $rate,
            ['tz' => 'Asia/Makassar'],
        );
        $penaltyRp = (int) ($pen['amount'] ?? 0);

        $minibarUnpaid = self::unpaidMinibarSubtotal($g);
        $serviceUnpaid = self::unpaidMinibarService($g);

        $base = $roomAfter + $chargeRp + $extraRp + $penaltyRp + $minibarUnpaid + $serviceUnpaid;

        return [
            'nights'                        => $n,
            'rate'                          => (int) $rate,
            'disc_percent'                  => $discPct,
            'disc_amount'                   => $discAmt,
            'rate_after'                    => (int) $rateAfter,
            'rate_after_times_nights'       => (int) ($rateAfter * $n),
            'charge'                        => $chargeRp,
            'extra'                         => $extraRp,
            'penalty'                       => $penaltyRp,
            'minibar_unpaid'                => (int) $minibarUnpaid,
            'service_minibar_unpaid'        => (int) $serviceUnpaid,
            'base'                          => (int) $base,
        ];
    }

    /**
     * Tax share utk BILLING: bagi rata pajak berdasarkan SUM base (billing) × tax% / jumlah guest.
     * (Mirip taxShareForReservation(), tapi pakai base "for billing".)
     */
    public static function taxShareForReservationBilling(ReservationGuest $current): array
    {
        $res = $current->reservation;
        if (! $res) {
            return ['tax_total' => 0, 'tax_per_guest' => 0, 'count' => 1, 'percent' => 0.0];
        }

        $guests = $res->reservationGuests ?? [];
        $count  = max(1, (int) count($guests));
        $pct    = (float) ($res->tax?->percent ?? 0);

        $sumBase = 0;
        foreach ($guests as $g) {
            $sumBase += (int) self::guestBaseForBilling($g)['base'];
        }

        $taxTotal    = (int) round(($sumBase * $pct) / 100);
        $taxPerGuest = (int) round($taxTotal / $count);

        return [
            'tax_total'     => $taxTotal,
            'tax_per_guest' => $taxPerGuest,
            'count'         => $count,
            'percent'       => $pct,
            'sum_base'      => (int) $sumBase,
        ];
    }

    // Semua MINIBAR (paid+unpaid) – tetap dipertahankan untuk kebutuhan display lama
    public static function minibarSubtotalAll(ReservationGuest $g): int
    {
        return (int) MinibarReceiptItem::query()
            ->whereHas('receipt', fn($q) => $q->where('reservation_guest_id', $g->id))
            ->sum('line_total');
    }

    /**
     * Harga setelah diskon % (dibulatkan ke integer rupiah).
     */
    public static function discountedPrice(float|int|null $base, float|int|null $pct): int
    {
        $b = max(0.0, (float) ($base ?? 0));
        $p = max(0.0, min(100.0, (float) ($pct ?? 0)));
        return (int) round($b - ($b * ($p / 100)));
    }

    /**
     * Hitung rate & deposit dari HARGA DASAR (bukan dari room_id).
     * - Complimentary  -> rate=0, deposit=0
     * - Non-complimentary -> deposit = 50% dari rate setelah diskon
     */
    public static function rateDepositFromPrice(float|int|null $price, float|int|null $discountPct, string $person): array
    {
        if (strtoupper(trim($person)) === 'COMPLIMENTARY') {
            return ['rate' => 0, 'deposit' => 0];
        }

        $rate = self::discountedPrice((float) ($price ?? 0), (float) ($discountPct ?? 0));
        $deposit = (int) round($rate * 0.5);

        return ['rate' => $rate, 'deposit' => $deposit];
    }

    /**
     * Hitung rate & deposit dari ROOM (ambil price dari DB).
     * - Jika room tidak ditemukan, dianggap 0.
     * - Ikut aturan complimentary & diskon seperti di atas.
     */
    public static function rateDepositFromRoom(?int $roomId, float|int|null $discountPct, string $person): array
    {
        $price = 0.0;
        if ($roomId) {
            $price = (float) (Room::whereKey($roomId)->value('price') ?? 0);
        }

        return self::rateDepositFromPrice($price, $discountPct, $person);
    }
}
