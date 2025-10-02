<?php

namespace App\Filament\Resources\Guests;

use BackedEnum;
use App\Models\Guest;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Session;
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Resources\Guests\Pages\EditGuest;
use App\Filament\Resources\Guests\Pages\ListGuests;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\Guests\Pages\CreateGuest;
use App\Filament\Resources\Guests\Schemas\GuestForm;
use App\Filament\Resources\Guests\Tables\GuestsTable;

class GuestResource extends Resource
{
    protected static ?string $model = Guest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::UserGroup;

    public static function getNavigationGroup(): string
    {
        return 'Oprasional';
    }

    protected static ?string $navigationLabel = 'Tamu';

    protected static ?string $recordTitleAttribute = 'guest';

    public static function form(Schema $schema): Schema
    {
        return GuestForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GuestsTable::configure($table);
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
            'index' => ListGuests::route('/'),
            'create' => CreateGuest::route('/create'),
            'edit' => EditGuest::route('/{record}/edit'),
        ];
    }

    /**
     * Filter semua listing ke hotel konteks aktif.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);

        $hotelId = Session::get('active_hotel_id');

        return $hotelId
            ? $query->where('hotel_id', $hotelId)
            : $query->whereRaw('1 = 0'); // super admin belum pilih hotel → kosong
    }

    /**
     * Amankan akses record via URL (detail/edit/delete) ke hotel konteks aktif.
     */
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        $query = parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);

        $hotelId = Session::get('active_hotel_id');

        return $hotelId
            ? $query->where('hotel_id', $hotelId)
            : $query->whereRaw('1 = 0');
    }
}
