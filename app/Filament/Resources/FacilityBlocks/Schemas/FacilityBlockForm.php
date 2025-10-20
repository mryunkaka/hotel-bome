<?php

namespace App\Filament\Resources\FacilityBlocks\Schemas;

use App\Models\Facility;
use Filament\Schemas\Schema;
use App\Models\FacilityBlock;
use Illuminate\Support\Carbon;
use App\Models\FacilityBooking;
use App\Support\FacilityStatus;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Section;

class FacilityBlockForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Facility Board')
                ->components([
                    ViewField::make('facility_board_html')
                        ->view('facility_blocks.board', [
                            'showTitle' => false,
                        ])
                        ->viewData([
                            'facilities'   => FacilityStatus::allFacilities(),
                            'nameMap'      => FacilityStatus::namesMap(),
                            'buckets'      => FacilityStatus::buckets(60),
                            'bookedIds'   => self::getBookedIds(),
                            'blockedIds'  => self::getBlockedIds(),
                            'stats'        => FacilityStatus::stats(60),
                            'total'       => self::getTotalFacilities(),
                            'calendarEvents' => self::getCalendarEvents(),
                        ]),
                ])
                ->columnSpanFull(),
        ]);
    }

    /* ========== Helpers & data providers ========== */

    private static function hid(): ?int
    {
        return Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;
    }

    private static function now(): string
    {
        return Carbon::now(config('app.timezone'))->format('Y-m-d H:i:s');
    }

    private static function getFacilities()
    {
        $hid = self::hid();

        return Facility::query()
            ->when($hid, fn($q) => $q->where('hotel_id', $hid))
            ->orderBy('name')
            ->get(['id', 'name', 'hotel_id']);
    }

    private static function getTotalFacilities(): int
    {
        $hid = self::hid();

        return Facility::query()
            ->when($hid, fn($q) => $q->where('hotel_id', $hid))
            ->count();
    }

    private static function getBookedIds(): array
    {
        $hid = self::hid();
        $now = self::now();

        return FacilityBooking::query()
            ->when($hid, fn($q) => $q->where('hotel_id', $hid))
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
            ->all();
    }

    private static function getBlockedIds(): array
    {
        $hid = self::hid();
        $now = self::now();

        return FacilityBlock::query()
            ->when($hid, fn($q) => $q->where('hotel_id', $hid))
            ->where('start_at', '<', $now)
            ->where('end_at', '>', $now)
            ->pluck('facility_id')
            ->unique()
            ->all();
    }

    private static function getStats(): array
    {
        $total   = self::getTotalFacilities();
        $booked  = self::getBookedIds();
        $blocked = self::getBlockedIds();

        $blockedCount = count($blocked);
        $bookedCount  = count(array_diff($booked, $blocked)); // jangan double-count yg blocked
        $available    = max(0, $total - ($blockedCount + $bookedCount));

        return [
            'booked'    => $bookedCount,
            'blocked'   => $blockedCount,
            'available' => $available,
        ];
    }
    /**
     * Event untuk FullCalendar:
     * - booking CONFIRM/PAID (guest & title bila ada)
     * - facility blocks aktif & mendatang (status = BLOCKED)
     *
     * @return array<int, array<string,mixed>>
     */
    private static function getCalendarEvents(): array
    {
        $hid = self::hid();

        // Ambil rentang +-60 hari dari hari ini supaya kalender tidak terlalu berat
        $startRange = Carbon::now(config('app.timezone'))->copy()->subDays(60);
        $endRange   = Carbon::now(config('app.timezone'))->copy()->addDays(60);

        // BOOKING events
        // BOOKING events
        $bookings = FacilityBooking::query()
            ->when($hid, fn($q) => $q->where('hotel_id', $hid))
            ->where(function ($q) use ($startRange, $endRange) {
                $q->whereBetween('start_at', [$startRange, $endRange])
                    ->orWhereBetween('end_at', [$startRange, $endRange]);
            })
            ->with([
                'facility:id,name',
                // butuh salutation supaya accessor display_name bisa jalan
                'guest:id,name,salutation',
            ])
            // PENTING: pilih juga guest_id agar eager load bisa “mengait”
            ->get(['id', 'facility_id', 'guest_id', 'start_at', 'end_at', 'status', 'title'])
            ->map(function (FacilityBooking $b) {
                $facilityName = $b->facility?->name ?: ('#' . $b->facility_id);

                // pakai display_name kalau ada, fallback ke name
                $guestName = $b->guest?->display_name ?? $b->guest?->name ?? null;

                $title = trim($b->title ?: (
                    $facilityName . ($guestName ? ' — ' . $guestName : ' — Booking')
                ));

                return [
                    'id'            => (int) $b->id,
                    'title'         => $title,
                    'start'         => optional($b->start_at)->format('Y-m-d\TH:i:s'),
                    'end'           => optional($b->end_at)->format('Y-m-d\TH:i:s'),
                    'facility_id'   => (int) $b->facility_id,
                    'facility_name' => $facilityName,
                    'guest_name'    => $guestName,              // ← sekarang terisi
                    'status'        => $b->status,
                ];
            });

        // BLOCK events
        $blocks = FacilityBlock::query()
            ->when($hid, fn($q) => $q->where('hotel_id', $hid))
            ->where(function ($q) use ($startRange, $endRange) {
                $q->whereBetween('start_at', [$startRange, $endRange])
                    ->orWhereBetween('end_at', [$startRange, $endRange]);
            })
            ->with(['facility:id,name'])
            ->get(['id', 'facility_id', 'start_at', 'end_at', 'reason'])
            ->map(function (FacilityBlock $blk) {
                $facilityName = $blk->facility?->name ?: ('#' . $blk->facility_id);
                $status = strtoupper($blk->reason) === 'INSPECTION' ? 'INSPECTION' : 'BLOCKED';
                return [
                    'id'            => (int) ($blk->id * 100000), // beda range id agar unik
                    'title'         => ($status === 'INSPECTION' ? 'INSPECTION' : 'BLOCK') . ' — ' . $facilityName,
                    'start'         => optional($blk->start_at)->format('Y-m-d\TH:i:s'),
                    'end'           => optional($blk->end_at)->format('Y-m-d\TH:i:s'),
                    'facility_id'   => (int) $blk->facility_id,
                    'facility_name' => $facilityName,
                    'guest_name'    => null,
                    'status'        => $status,
                ];
            });

        // gabungkan & kembalikan array numerik
        return $bookings->concat($blocks)->values()->all();
    }
}
