<?php

namespace App\Filament\Resources\AccountLedgers\Pages;

use App\Filament\Resources\AccountLedgers\AccountLedgerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAccountLedgers extends ListRecords
{
    protected static string $resource = AccountLedgerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
