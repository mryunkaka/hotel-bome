<?php

namespace App\Filament\Resources\ReservationGuestCheckOuts;

use BackedEnum;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use App\Models\ReservationGuest;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use App\Filament\Resources\ReservationGuestCheckOuts\Pages\EditReservationGuestCheckOut;
use App\Filament\Resources\ReservationGuestCheckOuts\Pages\ViewReservationGuestCheckOut;
use App\Filament\Resources\ReservationGuestCheckOuts\Pages\ListReservationGuestCheckOuts;
use App\Filament\Resources\ReservationGuestCheckOuts\Pages\CreateReservationGuestCheckOut;
use App\Filament\Resources\ReservationGuestCheckOuts\Schemas\ReservationGuestCheckOutForm;
use App\Filament\Resources\ReservationGuestCheckOuts\Tables\ReservationGuestCheckOutsTable;
use App\Filament\Resources\ReservationGuestCheckOuts\Schemas\ReservationGuestCheckOutInfolist;

class ReservationGuestCheckOutResource extends Resource
{
    protected static ?string $model = ReservationGuest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $navigationLabel = 'Guest Check Out';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ReservationGuestCheckOutForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ReservationGuestCheckOutInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ReservationGuestCheckOutsTable::configure($table);
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
            'index' => ListReservationGuestCheckOuts::route('/'),
            'create' => CreateReservationGuestCheckOut::route('/create'),
            'view' => ViewReservationGuestCheckOut::route('/{record}'),
            'edit' => EditReservationGuestCheckOut::route('/{record}/edit'),
        ];
    }
}
