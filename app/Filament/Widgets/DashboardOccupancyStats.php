<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardOccupancyStats extends BaseWidget
{
    // ⛔ HAPUS "static"
    protected ?string $heading = 'Okupansi Kamar';
    // opsional: atur lebar widget (juga non-static di v4)
    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $hid = (int) (session('active_hotel_id') ?? 0);

        $roomsTotal = $this->count('rooms', $hid);

        // kamar terisi = DISTINCT room_id pada bookings yg belum checked_out
        $occupied = DB::table('bookings')
            ->when($hid, fn($q) => $q->where('hotel_id', $hid))
            ->whereNull('deleted_at')
            ->where('status', '!=', 'checked_out')
            ->distinct('room_id')
            ->count('room_id');

        $available = max(0, $roomsTotal - $occupied);

        return [
            Stat::make('Total Kamar', number_format($roomsTotal)),
            Stat::make('Terisi', number_format($occupied)),
            Stat::make('Kosong', number_format($available)),
        ];
    }

    private function count(string $table, int $hotelId): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        $q = DB::table($table);

        if (Schema::hasColumn($table, 'hotel_id')) {
            $q->where('hotel_id', $hotelId);
        }

        if (Schema::hasColumn($table, 'deleted_at')) {
            $q->whereNull('deleted_at');
        }

        return (int) $q->count();
    }
}
