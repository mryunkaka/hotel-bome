<?php

namespace App\Filament\Resources\Reservations\Pages;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Filament\Resources\Pages\CreateRecord;
use App\Filament\Resources\Reservations\ReservationResource;

class CreateReservation extends CreateRecord
{
    protected static string $resource = ReservationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Pastikan hotel_id tersedia
        $data['hotel_id'] = $data['hotel_id'] ?? Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;

        // Set created_by
        $data['created_by'] = $data['created_by'] ?? Auth::id();

        // Logic reserved_by_type
        if (($data['reserved_by_type'] ?? 'GUEST') === 'GUEST') {
            $data['group_id'] = null;
        } else {
            $data['guest_id'] = null;
        }

        // Hitung expected_departure dari arrival + nights jika diperlukan
        if (!empty($data['expected_arrival']) && !empty($data['nights'])) {
            $data['expected_departure'] = \Illuminate\Support\Carbon::parse($data['expected_arrival'])
                ->startOfDay()
                ->addDays(max(1, (int) $data['nights']))
                ->setTime(12, 0);
        }

        // Buang field UI-only
        unset(
            $data['nights'],
            $data['reserved_guest_id'],
            $data['reserved_by'],
            $data['reserved_number'],
            $data['reserved_title']
        );

        return $data;
    }
}
