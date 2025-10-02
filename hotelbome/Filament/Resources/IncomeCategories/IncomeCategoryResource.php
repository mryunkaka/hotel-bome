<?php

namespace App\Filament\Resources\IncomeCategories;

use BackedEnum;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use App\Models\IncomeCategory;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Session;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\IncomeCategories\Pages\EditIncomeCategory;
use App\Filament\Resources\IncomeCategories\Pages\CreateIncomeCategory;
use App\Filament\Resources\IncomeCategories\Pages\ListIncomeCategories;
use App\Filament\Resources\IncomeCategories\Schemas\IncomeCategoryForm;
use App\Filament\Resources\IncomeCategories\Tables\IncomeCategoriesTable;

class IncomeCategoryResource extends Resource
{
    protected static ?string $model = IncomeCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Kategori Pemasukan';

    public static function getNavigationGroup(): string
    {
        return 'Pemasukan';
    }

    public static function form(Schema $schema): Schema
    {
        return IncomeCategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return IncomeCategoriesTable::configure($table);
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
            'index' => ListIncomeCategories::route('/'),
            'create' => CreateIncomeCategory::route('/create'),
            'edit' => EditIncomeCategory::route('/{record}/edit'),
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
