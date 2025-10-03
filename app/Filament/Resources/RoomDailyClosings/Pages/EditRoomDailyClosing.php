<?php

namespace App\Filament\Resources\RoomDailyClosings\Pages;

use App\Filament\Resources\RoomDailyClosings\RoomDailyClosingResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditRoomDailyClosing extends EditRecord
{
    protected static string $resource = RoomDailyClosingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
