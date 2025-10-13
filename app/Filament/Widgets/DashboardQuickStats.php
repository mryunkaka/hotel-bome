<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardQuickStats extends BaseWidget
{
    protected ?string $heading = 'Ringkasan';

    protected function getStats(): array
    {
        $hid = (int) (session('active_hotel_id') ?? 0);

        $count = function (string $table) use ($hid): int {
            if (! Schema::hasTable($table)) {
                return 0;
            }
            try {
                $q = DB::table($table);
                if ($hid && Schema::hasColumn($table, 'hotel_id')) {
                    $q->where('hotel_id', $hid);
                }
                if (Schema::hasColumn($table, 'deleted_at')) {
                    $q->whereNull('deleted_at');
                }
                return (int) $q->count();
            } catch (\Throwable) {
                return 0;
            }
        };

        $nf = fn(int $n) => number_format($n, 0, ',', '.');

        return [
            Stat::make('Jumlah User', $nf($count('users')))
                ->icon('heroicon-o-user-group')
                ->color('success'),

            Stat::make('Jumlah Kamar', $nf($count('rooms')))
                ->icon('heroicon-o-building-office-2')
                ->color('info'),

            Stat::make('Jumlah Tamu', $nf($count('guests')))
                ->icon('heroicon-o-user')
                ->color('purple'),

            Stat::make('Jumlah Bank', $nf($count('banks')))
                ->icon('heroicon-o-banknotes')
                ->color('warning'),

            // gunakan ledger_accounts (ada di proyek kamu)
            Stat::make('Ledger Accounts', $nf($count('ledger_accounts')))
                ->icon('heroicon-o-rectangle-stack')
                ->color('pink'),
        ];
    }
}
