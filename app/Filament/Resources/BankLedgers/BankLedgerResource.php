<?php

namespace App\Filament\Resources\BankLedgers;

use BackedEnum;
use App\Models\BankLedger;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Session;
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Traits\ForbidReceptionistResource;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\BankLedgers\Pages\EditBankLedger;
use App\Filament\Resources\BankLedgers\Pages\ListBankLedgers;
use App\Filament\Resources\BankLedgers\Pages\CreateBankLedger;
use App\Filament\Resources\BankLedgers\Schemas\BankLedgerForm;
use App\Filament\Resources\BankLedgers\Tables\BankLedgersTable;

class BankLedgerResource extends Resource
{
    use ForbidReceptionistResource;

    protected static ?string $model = BankLedger::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::RectangleStack;

    protected static ?string $navigationLabel = 'Buku Besar Bank';

    public static function getNavigationGroup(): string
    {
        return 'Oprasional';
    }

    protected static ?string $recordTitleAttribute = 'bankledger';

    public static function form(Schema $schema): Schema
    {
        return BankLedgerForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BankLedgersTable::configure($table);
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
            'index' => ListBankLedgers::route('/'),
            'create' => CreateBankLedger::route('/create'),
            'edit' => EditBankLedger::route('/{record}/edit'),
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
