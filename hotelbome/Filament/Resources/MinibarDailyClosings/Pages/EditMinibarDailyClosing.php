<?php

namespace App\Filament\Resources\MinibarDailyClosings\Pages;

use App\Filament\Resources\MinibarDailyClosings\MinibarDailyClosingResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMinibarDailyClosing extends EditRecord
{
    protected static string $resource = MinibarDailyClosingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
