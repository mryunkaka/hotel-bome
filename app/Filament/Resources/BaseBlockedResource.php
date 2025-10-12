<?php

namespace App\Filament\Resources;

use Filament\Resources\Resource;
use App\Filament\Traits\ForbidReceptionistResource;

abstract class BaseBlockedResource extends Resource
{
    use ForbidReceptionistResource;
}
