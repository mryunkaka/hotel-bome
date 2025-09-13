<?php

namespace App\Filament\Resources\IncomeCategories\Pages;

use App\Filament\Resources\IncomeCategories\IncomeCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListIncomeCategories extends ListRecords
{
    protected static string $resource = IncomeCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
