<?php

namespace App\Filament\Resources\FacilityBlocks\Pages;

use App\Filament\Resources\FacilityBlocks\FacilityBlockResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFacilityBlock extends EditRecord
{
    protected static string $resource = FacilityBlockResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
