<?php

namespace App\Filament\Resources\ReservationGuestCheckOuts\Pages;

use App\Filament\Resources\ReservationGuestCheckOuts\ReservationGuestCheckOutResource;
use Filament\Resources\Pages\EditRecord;

class EditReservationGuestCheckOut extends EditRecord
{
    protected static string $resource = ReservationGuestCheckOutResource::class;

    protected function getFormActions(): array
    {
        return []; // actions ditangani di Schema (schema-level actions)
    }
}
