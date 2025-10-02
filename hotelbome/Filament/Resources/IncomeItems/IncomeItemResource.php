<?php

namespace App\Filament\Resources\IncomeItems;

use BackedEnum;
use App\Models\IncomeItem;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Session;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\IncomeItems\Pages\EditIncomeItem;
use App\Filament\Resources\IncomeItems\Pages\ListIncomeItems;
use App\Filament\Resources\IncomeItems\Pages\CreateIncomeItem;
use App\Filament\Resources\IncomeItems\Schemas\IncomeItemForm;
use App\Filament\Resources\IncomeItems\Tables\IncomeItemsTable;

class IncomeItemResource extends Resource
{
    protected static ?string $model = IncomeItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ListBullet;

    protected static ?string $recordTitleAttribute = 'name';
    protected static ?string $navigationLabel = 'Item Pemasukan';

    public static function getNavigationGroup(): string
    {
        return 'Pemasukan';
    }

    public static function form(Schema $schema): Schema
    {
        return IncomeItemForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return IncomeItemsTable::configure($table);
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
            'index' => ListIncomeItems::route('/'),
            'create' => CreateIncomeItem::route('/create'),
            'edit' => EditIncomeItem::route('/{record}/edit'),
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
