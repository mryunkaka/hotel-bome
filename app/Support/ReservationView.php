<?php

namespace App\Support;

use App\Models\ReservationGuest;
use App\Models\Room;
use App\Models\TaxSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

final class ReservationView
{
    /* =========================
     * Formatter
     * ========================= */
    public static function fmtMoney($v): string
    {
        return 'Rp ' . number_format((float) $v, 0, ',', '.');
    }

    public static function fmtDate($dt, bool $withTime = true, string $tz = 'Asia/Singapore'): string
    {
        if (!$dt) return '';
        try {
            return Carbon::parse($dt)->timezone($tz)->format($withTime ? 'd/m/Y H:i' : 'd/m/Y');
        } catch (\Throwable $e) {
            return (string) $dt;
        }
    }

    /** Jika string sudah format `dd/mm/yyyy[ HH:MM]` kembalikan apa adanya, selain itu formatkan. */
    public static function displayDateFlexible($raw, $fallback = null, bool $withTime = true, string $tz = 'Asia/Singapore'): string
    {
        if (is_string($raw) && preg_match('/^\d{2}\/\d{2}\/\d{4}(?:\s+\d{2}:\d{2})?$/', $raw)) {
            return $raw;
        }
        return self::fmtDate($raw ?: $fallback, $withTime, $tz);
    }

    /* =========================
     * Build rows dari items
     * ========================= */
    public static function buildRowsFromItems(iterable $items, $ps = null): array
    {
        $rows = [];
        foreach ($items as $it) {
            $roomNo   = '-';
            $category = null;
            $guestNm  = null;
            $expArr   = null;
            $expDept  = null;

            $itemName = (string)($it['item_name'] ?? '');
            if (stripos($itemName, 'Room ') === 0) {
                $roomNo = trim(substr($itemName, 5));
            } elseif ($itemName !== '') {
                $roomNo = $itemName;
            }

            $desc = (string)($it['description'] ?? '');
            foreach (['Category:' => 'category', 'Guest:' => 'guest', 'EXP ARR:' => 'arr', 'EXP DEPT:' => 'dept'] as $needle => $key) {
                $pos = stripos($desc, $needle);
                if ($pos !== false) {
                    $seg = trim(substr($desc, $pos + strlen($needle)));
                    $seg = trim(explode('·', $seg)[0] ?? $seg);
                    if ($key === 'category') $category = $seg;
                    elseif ($key === 'guest')    $guestNm  = $seg;
                    elseif ($key === 'arr')      $expArr   = $seg;
                    elseif ($key === 'dept')     $expDept  = $seg;
                }
            }

            $rows[] = [
                'room_no'          => $roomNo ?: '-',
                'category'         => $category ?: '-',
                'rate'             => (float)($it['unit_price'] ?? 0),
                'discount_percent' => (float)($it['discount_percent'] ?? 0),
                'tax_percent'      => isset($it['tax_percent']) ? (float)$it['tax_percent'] : 0,
                'id_tax'           => $it['id_tax'] ?? null,
                'extra_bed'        => (int)($it['extra_bed'] ?? 0),
                'service'          => (int)($it['service'] ?? 0),
                'ps'               => $ps ?? null,
                'guest'            => $guestNm ?: '-',
                'exp_arr'          => $expArr ?: '-',
                'exp_dept'         => $expDept ?: '-',
            ];
        }
        return $rows;
    }

    /* =========================
     * Pastikan tax lookup ada
     * ========================= */
    public static function ensureTaxLookup(array $rows, ?array $taxLookup): array
    {
        if (is_array($taxLookup)) return $taxLookup;

        $ids = [];
        foreach ($rows as $rr) {
            if (is_array($rr) && !empty($rr['id_tax'])) $ids[] = (int) $rr['id_tax'];
            if (is_object($rr) && !empty($rr->id_tax))  $ids[] = (int) $rr->id_tax;
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) return [];

        try {
            return TaxSetting::query()
                ->whereIn('id', $ids)
                ->pluck('percent', 'id')
                ->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /* =========================
     * Enrich rows dari ReservationGuest
     * ========================= */
    public static function enrichRows(array $rows, $hotel = null): array
    {
        $activeHotelId = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id ?? ($hotel->id ?? null);

        try {
            $roomIdByNo = $activeHotelId
                ? Room::where('hotel_id', $activeHotelId)->pluck('id', 'room_no')->toArray()
                : [];
        } catch (\Throwable $e) {
            $roomIdByNo = [];
        }

        $taxLookup = [];

        $enrich = function ($row) use (&$taxLookup, $roomIdByNo, $activeHotelId) {
            $r = is_array($row) ? $row : (array) $row;

            // ===== Nights auto-calc jika belum ada =====
            $in  = $r['actual_in']  ?? $r['expected_in']  ?? $r['expected_checkin']  ?? null;
            $out = $r['actual_out'] ?? $r['expected_out'] ?? $r['expected_checkout'] ?? null;

            if (!isset($r['nights']) || (int)$r['nights'] <= 0) {
                if ($in && $out) {
                    try {
                        $r['nights'] = max(
                            1,
                            (int) Carbon::parse($in)->startOfDay()->diffInDays(
                                Carbon::parse($out)->startOfDay()
                            )
                        );
                    } catch (\Throwable $e) {
                        $r['nights'] = 1;
                    }
                } else {
                    $r['nights'] = 1;
                }
            }

            // ===== (sisanya tetap seperti versi kamu) =====
            $needDisc    = !array_key_exists('discount_percent', $r);
            $needTaxId   = !array_key_exists('id_tax', $r) && !array_key_exists('tax_percent', $r) && !array_key_exists('tax', $r);
            $needExtra   = !array_key_exists('extra_bed', $r);
            $needService = !array_key_exists('service', $r);
            $needBase    = !array_key_exists('rate', $r) && !array_key_exists('unit_price', $r) && !array_key_exists('room_rate', $r);

            if (!($needDisc || $needTaxId || $needExtra || $needService || $needBase)) {
                return $r;
            }

            $roomNo = trim((string) ($r['room_no'] ?? ''));
            $roomId = $roomIdByNo[$roomNo] ?? null;

            $arrRaw  = $r['exp_arr'] ?? null;
            $arrDate = null;
            if ($arrRaw) {
                try {
                    $arrDate = Carbon::parse($arrRaw)->toDateString();
                } catch (\Throwable $e) {
                    if (preg_match('~^(\d{2})/(\d{2})/(\d{4})~', (string)$arrRaw, $m)) {
                        $arrDate = "{$m[3]}-{$m[2]}-{$m[1]}";
                    }
                }
            }

            try {
                $rg = ReservationGuest::query()
                    ->with('tax')
                    ->when($activeHotelId, fn($qq) => $qq->where('hotel_id', $activeHotelId))
                    ->when($roomId,       fn($qq) => $qq->where('room_id', $roomId))
                    ->when($arrDate,      fn($qq) => $qq->whereDate('expected_checkin', $arrDate))
                    ->latest('id')
                    ->first();

                if ($rg) {
                    if ($needBase    && $rg->room_rate !== null)        $r['room_rate']        = (float) $rg->room_rate;
                    if ($needDisc    && $rg->discount_percent !== null) $r['discount_percent'] = (float) $rg->discount_percent;
                    if ($needExtra   && $rg->extra_bed !== null)        $r['extra_bed']        = (int)   $rg->extra_bed;
                    if ($needService && $rg->service !== null)          $r['service']          = (int)   $rg->service;

                    if ($needTaxId) {
                        if ($rg->id_tax) {
                            $r['id_tax'] = (int) $rg->id_tax;
                            if ($rg->tax && !isset($taxLookup[$rg->id_tax])) {
                                $taxLookup[$rg->id_tax] = (float) $rg->tax->percent;
                            }
                        } elseif ($rg->tax) {
                            $r['tax_percent'] = (float) $rg->tax->percent;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // ignore
            }

            return $r;
        };

        foreach ($rows as &$row) {
            $row = $enrich($row);
        }
        unset($row);

        return [$rows, $taxLookup];
    }

    /* =========================
     * Prepare all data for print
     * ========================= */
    public static function prepareForPrint(array $ctx): array
    {
        // 1) rows
        $rows  = [];
        if (!empty($ctx['rows']) && is_iterable($ctx['rows'])) {
            // normalize to array
            foreach ($ctx['rows'] as $r) $rows[] = is_array($r) ? $r : (array) $r;
        } elseif (!empty($ctx['items']) && is_iterable($ctx['items'])) {
            $rows = self::buildRowsFromItems($ctx['items'], $ctx['ps'] ?? null);
        }

        // 2) tax lookup (controller override > derive), dukung snake & camel
        $taxLookupInput = $ctx['tax_lookup'] ?? ($ctx['taxLookup'] ?? null);
        $taxLookup = self::ensureTaxLookup($rows, $taxLookupInput);

        // 3) enrich dari reservation_guests
        [$rowsEnriched, $extraTaxMap] = self::enrichRows($rows, $ctx['hotel'] ?? null);
        // gabungkan tax map dari enrich (kalau ada)
        $taxLookup = $extraTaxMap ? ($taxLookup + $extraTaxMap) : $taxLookup;

        // 4) header summaries & deposits (dukung snake & camel + legacy)
        $depositRoom = isset($ctx['deposit_room']) ? (float)$ctx['deposit_room']
            : (isset($ctx['depositRoom']) ? (float)$ctx['depositRoom']
                : (isset($ctx['deposit']) ? (float)$ctx['deposit'] : (float)($ctx['paid_total'] ?? ($ctx['paidTotal'] ?? 0))));

        $depositCard = isset($ctx['deposit_card']) ? (float)$ctx['deposit_card']
            : (isset($ctx['depositCard']) ? (float)$ctx['depositCard'] : 0.0);

        $reservedTitle = $ctx['reserved_title'] ?? ($ctx['reservedTitle'] ?? null);
        $reservedBy    = $ctx['reserved_by']    ?? ($ctx['reservedBy']    ?? (($ctx['billTo']['name'] ?? null) ?? null));
        $reservedFull  = trim(($reservedTitle ? ($reservedTitle . ' ') : '') . ($reservedBy ?? ''));

        $clerkName = $ctx['clerkName'] ?? ($ctx['clerk'] ?? null);
        $hotel     = $ctx['hotel'] ?? null;

        $hotelRight = array_filter([
            $hotel?->address,
            ($hotel?->phone    ? 'Phone  ' . $hotel->phone    : null),
            ($hotel?->whatsapp ? 'WhatsApp ' . $hotel->whatsapp : null),
            ($hotel?->city     ? $hotel->city : null),
        ]);

        // NOTE: depositVal dipertahankan untuk kompatibilitas lama (isi = Room Deposit)
        return [
            'rows'          => $rowsEnriched,
            'taxLookup'     => $taxLookup,
            'depositVal'    => $depositRoom,

            // keluarkan keduanya agar view bebas pakai camel/snake:
            'depositRoom'   => $depositRoom,
            'deposit_room'  => $depositRoom,
            'depositCard'   => $depositCard,
            'deposit_card'  => $depositCard,

            'reservedFull'  => $reservedFull,
            'clerkName'     => $clerkName,
            'hotelRight'     => $hotelRight,
        ];
    }
}
