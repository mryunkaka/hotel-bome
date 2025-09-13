<?php

namespace App\Filament\Resources\AccountLedgers\Pages;

use Illuminate\Support\Facades\Session;
use Filament\Resources\Pages\CreateRecord;
use App\Filament\Resources\AccountLedgers\AccountLedgerResource;

class CreateAccountLedger extends CreateRecord
{
    protected static string $resource = AccountLedgerResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['hotel_id'] = Session::get('active_hotel_id'); // kunci ke konteks
        return $data;
    }
}
