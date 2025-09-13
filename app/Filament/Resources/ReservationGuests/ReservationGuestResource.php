<?php

namespace App\Filament\Resources\ReservationGuests;

use App\Filament\Resources\ReservationGuests\Pages\CreateReservationGuest;
use App\Filament\Resources\ReservationGuests\Pages\EditReservationGuest;
use App\Filament\Resources\ReservationGuests\Pages\ListReservationGuests;
use App\Filament\Resources\ReservationGuests\Schemas\ReservationGuestForm;
use App\Filament\Resources\ReservationGuests\Tables\ReservationGuestsTable;
use App\Models\ReservationGuest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ReservationGuestResource extends Resource
{
    protected static ?string $model = ReservationGuest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ReservationGuestForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ReservationGuestsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReservationGuests::route('/'),
            'create' => CreateReservationGuest::route('/create'),
            'edit' => EditReservationGuest::route('/{record}/edit'),
        ];
    }
}
