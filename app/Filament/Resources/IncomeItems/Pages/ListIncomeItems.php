<?php

namespace App\Filament\Resources\IncomeItems\Pages;

use App\Filament\Resources\IncomeItems\IncomeItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListIncomeItems extends ListRecords
{
    protected static string $resource = IncomeItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
