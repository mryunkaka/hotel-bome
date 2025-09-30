<?php

namespace App\Filament\Resources\MinibarReceipts\Pages;

use App\Filament\Resources\MinibarReceipts\MinibarReceiptResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMinibarReceipts extends ListRecords
{
    protected static string $resource = MinibarReceiptResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
