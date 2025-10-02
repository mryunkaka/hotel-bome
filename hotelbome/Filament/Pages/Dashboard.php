<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Widgets\WidgetConfiguration;
use App\Filament\Widgets\DashboardQuickStats;
use App\Filament\Widgets\DashboardOccupancyStats;
use App\Filament\Widgets\LatestBookings;

class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Dashboard';

    /** @return array<class-string<\Filament\Widgets\Widget>|WidgetConfiguration> */
    public function getHeaderWidgets(): array
    {
        return [
            DashboardQuickStats::class,
            DashboardOccupancyStats::class,
        ];
    }

    /** @return array<class-string<\Filament\Widgets\Widget>|WidgetConfiguration> */
    public function getWidgets(): array
    {
        return [
            LatestBookings::class,
        ];
    }

    /** @return int|array<string, ?int> */
    public function getColumns(): int|array
    {
        return [
            'sm' => 1,
            'md' => 2,
            'xl' => 3,
        ];
    }
}
