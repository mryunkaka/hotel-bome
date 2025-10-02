<?php

namespace App\Filament\Resources\Banks;

use App\Filament\Resources\Banks\Pages\CreateBank;
use App\Filament\Resources\Banks\Pages\EditBank;
use App\Filament\Resources\Banks\Pages\ListBanks;
use App\Filament\Resources\Banks\Schemas\BankForm;
use App\Filament\Resources\Banks\Tables\BanksTable;
use App\Models\Bank;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Session;

class BankResource extends Resource
{
    protected static ?string $model = Bank::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Banknotes;
    protected static ?string $navigationLabel = 'Bank';
    public static function getNavigationGroup(): string
    {
        return 'Oprasional';
    }

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return BankForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BanksTable::configure($table);
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
            'index' => ListBanks::route('/'),
            'create' => CreateBank::route('/create'),
            'edit' => EditBank::route('/{record}/edit'),
        ];
    }

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
