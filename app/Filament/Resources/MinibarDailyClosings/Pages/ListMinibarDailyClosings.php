<?php

namespace App\Filament\Resources\MinibarDailyClosings\Pages;

use App\Filament\Resources\MinibarDailyClosings\MinibarDailyClosingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMinibarDailyClosings extends ListRecords
{
    protected static string $resource = MinibarDailyClosingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
