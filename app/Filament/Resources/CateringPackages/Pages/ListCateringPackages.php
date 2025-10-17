<?php

namespace App\Filament\Resources\CateringPackages\Pages;

use App\Filament\Resources\CateringPackages\CateringPackageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCateringPackages extends ListRecords
{
    protected static string $resource = CateringPackageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
