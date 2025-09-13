<?php

namespace App\Filament\Resources\AccountLedgers\Pages;

use App\Filament\Resources\AccountLedgers\AccountLedgerResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Session;

class EditAccountLedger extends EditRecord
{
    protected static string $resource = AccountLedgerResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['hotel_id'] = Session::get('active_hotel_id'); // kunci ke konteks
        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
