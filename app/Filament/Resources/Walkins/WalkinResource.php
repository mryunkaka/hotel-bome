<?php

namespace App\Filament\Resources\Walkins;

use BackedEnum;
use Filament\Tables\Table;
use App\Models\Reservation;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use App\Filament\Resources\Walkins\Pages\EditWalkin;
use App\Filament\Resources\Walkins\Pages\ListWalkins;
use App\Filament\Resources\Walkins\Pages\CreateWalkin;
use App\Filament\Resources\Walkins\Schemas\WalkinForm;
use App\Filament\Resources\Walkins\Tables\WalkinsTable;

class WalkinResource extends Resource
{
    // Tetap pakai model Reservation (bukan model baru)
    protected static ?string $model = Reservation::class;

    // Ikon sidebar
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel   = 'Walk-In';
    protected static ?string $modelLabel        = 'Walk-In';
    protected static ?string $pluralModelLabel  = 'Walk-In';

    protected static ?string $recordTitleAttribute = 'reservation_no';

    public static function form(Schema $schema): Schema
    {
        return WalkinForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        // Reuse kolom/toolbar dari ReservationsTable
        return WalkinsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false; // ⬅️ sembunyikan dari menu sidebar
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListWalkins::route('/'),
            'create' => CreateWalkin::route('/create'),
            'edit'   => EditWalkin::route('/{record}/edit'),
        ];
    }
}
