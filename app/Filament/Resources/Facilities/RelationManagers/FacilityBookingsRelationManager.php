<?php
// ===== FILE: app/Filament/Resources/Facilities/RelationManagers/FacilityBookingsRelationManager.php

namespace App\Filament\Resources\Facilities\RelationManagers;

use App\Filament\Resources\FacilityBookings\Tables\FacilityBookingsTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class FacilityBookingsRelationManager extends RelationManager
{
    protected static string $relationship = 'bookings';
    protected static ?string $title = 'Bookings';

    public function table(Table $table): Table
    {
        // Reuse the dedicated table schema for consistency with the main resource
        return FacilityBookingsTable::configure($table)
            ->recordTitleAttribute('title');
    }
}
