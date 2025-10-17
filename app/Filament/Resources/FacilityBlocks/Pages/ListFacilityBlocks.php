<?php

namespace App\Filament\Resources\FacilityBlocks\Pages;

use App\Filament\Resources\FacilityBlocks\FacilityBlockResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFacilityBlocks extends ListRecords
{
    protected static string $resource = FacilityBlockResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
