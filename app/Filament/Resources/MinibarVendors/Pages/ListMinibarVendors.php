<?php

namespace App\Filament\Resources\MinibarVendors\Pages;

use App\Filament\Resources\MinibarVendors\MinibarVendorResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMinibarVendors extends ListRecords
{
    protected static string $resource = MinibarVendorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
