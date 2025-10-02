<?php

namespace App\Filament\Resources\RoomBoards\Pages;

use App\Filament\Resources\RoomBoards\RoomBoardResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRoomBoard extends CreateRecord
{
    protected static string $resource = RoomBoardResource::class;

    // ⬇️ Override supaya tombol form tidak muncul
    protected function getFormActions(): array
    {
        return [];
    }
}
