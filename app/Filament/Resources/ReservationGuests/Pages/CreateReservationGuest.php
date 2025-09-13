<?php

namespace App\Filament\Resources\ReservationGuests\Pages;

use App\Filament\Resources\ReservationGuests\ReservationGuestResource;
use Filament\Resources\Pages\CreateRecord;

class CreateReservationGuest extends CreateRecord
{
    protected static string $resource = ReservationGuestResource::class;
}
