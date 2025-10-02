<?php

namespace App\Filament\Resources\BankLedgers\Pages;

use App\Filament\Resources\BankLedgers\BankLedgerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBankLedgers extends ListRecords
{
    protected static string $resource = BankLedgerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
