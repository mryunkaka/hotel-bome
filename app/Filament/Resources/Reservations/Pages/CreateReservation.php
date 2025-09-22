<?php

namespace App\Filament\Resources\Reservations\Pages;

use Illuminate\Support\Facades\Auth;
use Filament\Resources\Pages\CreateRecord;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Filament\Resources\Reservations\Schemas\ReservationForm;

class CreateReservation extends CreateRecord
{
    protected static string $resource = ReservationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (($data['reserved_by_type'] ?? 'GUEST') === 'GUEST') {
            $data['group_id'] = null;
        } else {
            $data['guest_id'] = null;
        }
        return $data;
    }
}
