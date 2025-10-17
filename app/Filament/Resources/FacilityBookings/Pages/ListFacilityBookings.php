<?php

namespace App\Filament\Resources\FacilityBookings\Pages;

use App\Filament\Resources\FacilityBookings\FacilityBookingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFacilityBookings extends ListRecords
{
    protected static string $resource = FacilityBookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
