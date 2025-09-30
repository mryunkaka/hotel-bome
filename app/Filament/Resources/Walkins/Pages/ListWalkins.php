<?php

namespace App\Filament\Resources\Walkins\Pages;

use Illuminate\Database\Eloquent\Builder;
use App\Filament\Resources\Walkins\WalkinResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions\Action;
use App\Models\Reservation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Filament\Notifications\Notification;

class ListWalkins extends ListRecords
{
    protected static string $resource = WalkinResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * Hanya tampilkan reservation dengan option_reservation = WALKIN
     */
    protected function getTableQuery(): Builder
    {
        return parent::getTableQuery()
            ->where('option_reservation', 'WALKIN');
    }
}
