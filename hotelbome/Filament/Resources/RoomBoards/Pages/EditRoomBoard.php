<?php

namespace App\Filament\Resources\RoomBoards\Pages;

use App\Filament\Resources\RoomBoards\RoomBoardResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRoomBoard extends EditRecord
{
    protected static string $resource = RoomBoardResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
