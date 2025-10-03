<?php

namespace App\Filament\Resources\RoomDailyClosings\Pages;

use App\Filament\Resources\RoomDailyClosings\RoomDailyClosingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRoomDailyClosings extends ListRecords
{
    protected static string $resource = RoomDailyClosingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
