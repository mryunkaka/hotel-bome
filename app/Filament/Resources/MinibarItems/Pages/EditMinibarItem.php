<?php

namespace App\Filament\Resources\MinibarItems\Pages;

use App\Filament\Resources\MinibarItems\MinibarItemResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditMinibarItem extends EditRecord
{
    protected static string $resource = MinibarItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
