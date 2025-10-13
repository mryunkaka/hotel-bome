<?php

namespace App\Filament\Widgets;

use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardOccupancyStats extends BaseWidget
{
    protected ?string $heading = 'Okupansi Kamar';
    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $hid = (int) (session('active_hotel_id') ?? 0);
        $today = Carbon::today();

        $roomsTotal = $this->count('rooms', $hid);

        // Occupied = booking aktif hari ini (bukan canceled) & belum soft-deleted
        $occupied = 0;
        if (Schema::hasTable('bookings')) {
            $occupied = DB::table('bookings')
                ->when($hid, fn($q) => $q->where('hotel_id', $hid))
                ->when(Schema::hasColumn('bookings', 'deleted_at'), fn($q) => $q->whereNull('deleted_at'))
                ->whereNotIn('status', ['canceled'])
                // aktif hari ini: check_in_at <= today < check_out_at
                ->whereDate('check_in_at', '<=', $today)
                ->whereDate('check_out_at', '>', $today)
                ->distinct('room_id')
                ->count('room_id');
        }

        $available = max(0, $roomsTotal - $occupied);

        $nf = fn($n) => number_format((int) $n, 0, ',', '.');

        return [
            Stat::make('Total Kamar', $nf($roomsTotal)),
            Stat::make('Terisi (aktif hari ini)', $nf($occupied))->color('warning'),
            Stat::make('Kosong', $nf($available))->color('success'),
        ];
    }

    private function count(string $table, int $hotelId): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        $q = DB::table($table);
        if ($hotelId && Schema::hasColumn($table, 'hotel_id')) {
            $q->where('hotel_id', $hotelId);
        }
        if (Schema::hasColumn($table, 'deleted_at')) {
            $q->whereNull('deleted_at');
        }

        return (int) $q->count();
    }
}
