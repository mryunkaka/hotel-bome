<?php

namespace App\Filament\Resources\CateringPackages\Pages;

use App\Filament\Resources\CateringPackages\CateringPackageResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditCateringPackage extends EditRecord
{
    protected static string $resource = CateringPackageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
