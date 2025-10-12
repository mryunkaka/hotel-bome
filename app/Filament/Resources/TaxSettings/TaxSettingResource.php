<?php

namespace App\Filament\Resources\TaxSettings;

use BackedEnum;
use App\Models\TaxSetting;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Session;
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Traits\ForbidReceptionistResource;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\TaxSettings\Pages\EditTaxSetting;
use App\Filament\Resources\TaxSettings\Pages\ListTaxSettings;
use App\Filament\Resources\TaxSettings\Pages\CreateTaxSetting;
use App\Filament\Resources\TaxSettings\Schemas\TaxSettingForm;
use App\Filament\Resources\TaxSettings\Tables\TaxSettingsTable;

class TaxSettingResource extends Resource
{
    use ForbidReceptionistResource;

    protected static ?string $model = TaxSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::AdjustmentsHorizontal;

    protected static ?string $navigationLabel = 'Pajak';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): string
    {
        return 'Atur Pajak';
    }

    public static function form(Schema $schema): Schema
    {
        return TaxSettingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TaxSettingsTable::configure($table);
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
            'index' => ListTaxSettings::route('/'),
            'create' => CreateTaxSetting::route('/create'),
            'edit' => EditTaxSetting::route('/{record}/edit'),
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
