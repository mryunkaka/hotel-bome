<?php

namespace App\Filament\Resources\RoomBoards\Pages;

use App\Filament\Resources\RoomBoards\RoomBoardResource;
use App\Filament\Resources\RoomBoards\Schemas\RoomBoardForm;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Schema;

class ListRoomBoards extends ListRecords
{
    protected static string $resource = RoomBoardResource::class;

    // ✅ index tanpa tabel
    protected function hasTable(): bool
    {
        return false;
    }

    // ✅ isikan Schema (ViewField) ke halaman List
    public static function schema(Schema $schema): Schema
    {
        return RoomBoardForm::configure($schema);
    }

    // (opsional) hilangkan tombol Create di header
    protected function getHeaderActions(): array
    {
        return [];
    }
}
