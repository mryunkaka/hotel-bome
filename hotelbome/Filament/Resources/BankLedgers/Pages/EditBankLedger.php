<?php

namespace App\Filament\Resources\BankLedgers\Pages;

use App\Filament\Resources\BankLedgers\BankLedgerResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditBankLedger extends EditRecord
{
    protected static string $resource = BankLedgerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
