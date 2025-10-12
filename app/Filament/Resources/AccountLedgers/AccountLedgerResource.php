<?php

namespace App\Filament\Resources\AccountLedgers;

use BackedEnum;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use App\Models\AccountLedger;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Session;
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Traits\ForbidReceptionistResource;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\AccountLedgers\Pages\EditAccountLedger;
use App\Filament\Resources\AccountLedgers\Pages\ListAccountLedgers;
use App\Filament\Resources\AccountLedgers\Pages\CreateAccountLedger;
use App\Filament\Resources\AccountLedgers\Schemas\AccountLedgerForm;
use App\Filament\Resources\AccountLedgers\Tables\AccountLedgersTable;

class AccountLedgerResource extends Resource
{
    use ForbidReceptionistResource;

    protected static ?string $model = AccountLedger::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::DocumentText;

    protected static ?string $navigationLabel = 'Buku Besar Akun';

    public static function getNavigationGroup(): string
    {
        return 'Oprasional';
    }

    protected static ?string $recordTitleAttribute = 'id';

    public static function form(Schema $schema): Schema
    {
        return AccountLedgerForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AccountLedgersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListAccountLedgers::route('/'),
            'create' => CreateAccountLedger::route('/create'),
            'edit'   => EditAccountLedger::route('/{record}/edit'),
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
