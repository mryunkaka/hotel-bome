<?php

namespace App\Filament\Resources\IncomeItems\Pages;

use App\Filament\Resources\IncomeItems\IncomeItemResource;
use Filament\Resources\Pages\CreateRecord;

class CreateIncomeItem extends CreateRecord
{
    protected static string $resource = IncomeItemResource::class;
}
