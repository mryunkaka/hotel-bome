<?php

namespace App\Filament\Resources\IncomeItems\Pages;

use App\Filament\Resources\IncomeItems\IncomeItemResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditIncomeItem extends EditRecord
{
    protected static string $resource = IncomeItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
