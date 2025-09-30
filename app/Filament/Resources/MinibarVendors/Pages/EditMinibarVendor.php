<?php

namespace App\Filament\Resources\MinibarVendors\Pages;

use App\Filament\Resources\MinibarVendors\MinibarVendorResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditMinibarVendor extends EditRecord
{
    protected static string $resource = MinibarVendorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
