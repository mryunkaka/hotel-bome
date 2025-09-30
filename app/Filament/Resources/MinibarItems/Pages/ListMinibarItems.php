<?php

namespace App\Filament\Resources\MinibarItems\Pages;

use App\Filament\Resources\MinibarItems\MinibarItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMinibarItems extends ListRecords
{
    protected static string $resource = MinibarItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
