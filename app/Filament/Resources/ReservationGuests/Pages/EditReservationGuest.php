<?php

namespace App\Filament\Resources\ReservationGuests\Pages;

use App\Filament\Resources\ReservationGuests\ReservationGuestResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditReservationGuest extends EditRecord
{
    protected static string $resource = ReservationGuestResource::class;

    protected function getFormActions(): array
    {
        return [];
    }
}
