<?php

namespace App\Filament\Resources\FacilityBookings\Pages;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use App\Filament\Resources\FacilityBookings\FacilityBookingResource;

class CreateFacilityBooking extends CreateRecord
{
    protected static string $resource = FacilityBookingResource::class;
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['hotel_id'] = $data['hotel_id']
            ?? Session::get('active_hotel_id')
            ?? Auth::user()?->hotel_id;

        return $data;
    }
    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        try {
            $record = static::getModel()::create($data);
            Notification::make()->title('Booking created')->success()->send();
            return $record;
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Create failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
            throw $e;
        }
    }
}
