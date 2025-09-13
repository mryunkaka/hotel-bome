<?php

namespace App\Filament\Resources\ReservationGuests\Pages;

use App\Filament\Resources\ReservationGuests\ReservationGuestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListReservationGuests extends ListRecords
{
    protected static string $resource = ReservationGuestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
