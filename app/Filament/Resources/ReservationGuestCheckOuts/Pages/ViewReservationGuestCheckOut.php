<?php

namespace App\Filament\Resources\ReservationGuestCheckOuts\Pages;

use App\Filament\Resources\ReservationGuestCheckOuts\ReservationGuestCheckOutResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewReservationGuestCheckOut extends ViewRecord
{
    protected static string $resource = ReservationGuestCheckOutResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
