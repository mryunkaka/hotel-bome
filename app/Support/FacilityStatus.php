<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Facility;
use App\Models\FacilityBlock;
use App\Models\FacilityBooking;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

/**
 * Helper status fasilitas untuk board.
 *
 * Definisi:
 * - IN_USE:   Ada booking aktif (CONFIRM/PAID) yang overlap dengan "now".
 * - INSPECTION: Ada FacilityBlock aktif sekarang dengan reason bertema inspeksi (inspect/inspeksi/VCI/QC).
 * - DIRTY:    Booking terakhir berakhir <= window menit yang lalu, TIDAK sedang in-use,
 *             dan TIDAK ada block aktif yang bertema cleaning/inspection.
 * - BLOCKED:  Ada FacilityBlock aktif sekarang bertema non-cleaning & non-inspection (OOO/maintenance/etc).
 * - READY:    Sisa fasilitas yang tidak termasuk kategori-kategori di atas.
 */
final class FacilityStatus
{
    /** Kata kunci untuk reason "inspection" (lowercase). */
    private const INSPECTION_KEYWORDS = ['inspeksi', 'inspection', 'inspect', 'vci', 'qc'];

    /** Kata kunci untuk reason "cleaning" (lowercase). */
    private const CLEANING_KEYWORDS = ['clean', 'bersih', 'housekeeping', 'cleaning'];

    /**
     * Ambil hotel id aktif dari session / user.
     */
    public static function hotelId(): ?int
    {
        return Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;
    }

    /**
     * Now di timezone app (Carbon).
     */
    public static function now(): Carbon
    {
        return Carbon::now(config('app.timezone'));
    }

    /**
     * Query dasar Facilities by hotel.
     */
    public static function facilitiesQuery(): Builder
    {
        return Facility::query()
            ->when(self::hotelId(), fn($q) => $q->where('hotel_id', self::hotelId()));
    }

    /**
     * Semua fasilitas (id, name, hotel_id).
     */
    public static function allFacilities(): Collection
    {
        return self::facilitiesQuery()
            ->orderBy('name')
            ->get(['id', 'name', 'hotel_id']);
    }

    /**
     * Map id => name (untuk tampilan).
     *
     * @return array<int, string>
     */
    public static function namesMap(): array
    {
        return self::facilitiesQuery()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * ID fasilitas yang sedang dipakai (booking aktif, blocking).
     *
     * @return int[]
     */
    public static function idsInUse(): array
    {
        $now = self::now()->format('Y-m-d H:i:s');

        return FacilityBooking::query()
            ->when(self::hotelId(), fn($q) => $q->where('hotel_id', self::hotelId()))
            ->where(function ($q) {
                $q->whereIn('status', [
                    FacilityBooking::STATUS_CONFIRM,
                    FacilityBooking::STATUS_PAID,
                ])->orWhere('is_blocked', true);
            })
            ->where('start_at', '<', $now)
            ->where('end_at', '>', $now)
            ->pluck('facility_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Semua block aktif sekarang (reason ikut dibawa).
     */
    private static function activeBlocksNow(): Collection
    {
        $now = self::now()->format('Y-m-d H:i:s');

        return FacilityBlock::query()
            ->when(self::hotelId(), fn($q) => $q->where('hotel_id', self::hotelId()))
            ->where('start_at', '<', $now)
            ->where('end_at', '>', $now)
            ->get(['facility_id', 'reason']);
    }

    /**
     * ID fasilitas yang INSPECTION aktif (block aktif dengan reason inspection).
     *
     * @return int[]
     */
    public static function idsInspection(): array
    {
        return self::activeBlocksNow()
            ->filter(fn($row) => self::reasonHas($row->reason, self::INSPECTION_KEYWORDS))
            ->pluck('facility_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * ID fasilitas yang CLEANING aktif (block aktif dengan reason cleaning).
     *
     * @return int[]
     */
    public static function idsCleaning(): array
    {
        return self::activeBlocksNow()
            ->filter(fn($row) => self::reasonHas($row->reason, self::CLEANING_KEYWORDS))
            ->pluck('facility_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * ID fasilitas yang BLOCKED "berat" (OOO/maintenance) — block aktif yang bukan cleaning/inspection.
     *
     * @return int[]
     */
    public static function idsBlockedHeavy(): array
    {
        return self::activeBlocksNow()
            ->reject(fn($row) => self::reasonHas($row->reason, array_merge(self::INSPECTION_KEYWORDS, self::CLEANING_KEYWORDS)))
            ->pluck('facility_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * ID fasilitas yang dianggap "dirty" (belum dibersihkan) secara heuristik.
     *
     * @param  int  $windowMinutes  durasi dari booking berakhir yang masih dianggap "kotor"
     * @return int[]
     */
    public static function idsDirty(int $windowMinutes = 60): array
    {
        $hid = self::hotelId();
        $now = self::now();
        $since = $now->copy()->subMinutes($windowMinutes)->format('Y-m-d H:i:s');
        $nowStr = $now->format('Y-m-d H:i:s');

        // Baru selesai booking di window waktu
        $recentEnded = FacilityBooking::query()
            ->when($hid, fn($q) => $q->where('hotel_id', $hid))
            ->where('end_at', '>', $since)
            ->where('end_at', '<=', $nowStr)
            ->whereIn('status', [
                FacilityBooking::STATUS_CONFIRM,
                FacilityBooking::STATUS_PAID,
                FacilityBooking::STATUS_COMPLETED,
            ])
            ->pluck('facility_id')
            ->unique()
            ->values()
            ->all();

        if (empty($recentEnded)) {
            return [];
        }

        $inUse      = self::idsInUse();
        $inspection = self::idsInspection();
        $cleaning   = self::idsCleaning();

        // Dirty = baru selesai, bukan in-use, bukan sedang dibersihkan/inspeksi
        return array_values(array_diff($recentEnded, $inUse, $cleaning, $inspection));
    }

    /**
     * Kelompokkan facility IDs ke buckets: in_use, dirty, inspection, blocked, ready.
     *
     * @return array{
     *   in_use:int[], dirty:int[], inspection:int[], blocked:int[], ready:int[]
     * }
     */
    public static function buckets(int $dirtyWindowMinutes = 60): array
    {
        $allIds = self::facilitiesQuery()->pluck('id')->all();

        $inUse      = self::idsInUse();
        $inspection = self::idsInspection();
        $dirty      = self::idsDirty($dirtyWindowMinutes);
        $blocked    = self::idsBlockedHeavy();

        $nonReady = array_unique(array_merge($inUse, $inspection, $dirty, $blocked));
        $ready    = array_values(array_diff($allIds, $nonReady));

        // supaya urutan rapi & unik
        $norm = fn(array $ids) => array_values(array_unique($ids));

        return [
            'in_use'     => $norm($inUse),
            'dirty'      => $norm($dirty),
            'inspection' => $norm($inspection),
            'blocked'    => $norm($blocked),
            'ready'      => $norm($ready),
        ];
    }

    /**
     * Statistik count per bucket.
     *
     * @return array{in_use:int, dirty:int, inspection:int, blocked:int, ready:int, total:int}
     */
    public static function stats(int $dirtyWindowMinutes = 60): array
    {
        $b = self::buckets($dirtyWindowMinutes);
        $total = self::facilitiesQuery()->count();

        return [
            'in_use'     => count($b['in_use']),
            'dirty'      => count($b['dirty']),
            'inspection' => count($b['inspection']),
            'blocked'    => count($b['blocked']),
            'ready'      => count($b['ready']),
            'total'      => $total,
        ];
    }

    /**
     * Status tunggal untuk satu facility id.
     * Urutan prioritas: in_use > inspection > dirty > blocked > ready
     *
     * @return 'in_use'|'inspection'|'dirty'|'blocked'|'ready'
     */
    public static function statusFor(int $facilityId, int $dirtyWindowMinutes = 60): string
    {
        $b = self::buckets($dirtyWindowMinutes);

        if (in_array($facilityId, $b['in_use'], true))     return 'in_use';
        if (in_array($facilityId, $b['inspection'], true)) return 'inspection';
        if (in_array($facilityId, $b['dirty'], true))      return 'dirty';
        if (in_array($facilityId, $b['blocked'], true))    return 'blocked';

        return 'ready';
    }

    /* ===================== Utilities ===================== */

    private static function reasonHas(?string $reason, array $keywords): bool
    {
        $r = Str::lower((string) ($reason ?? ''));
        return Str::contains($r, $keywords);
    }
}
