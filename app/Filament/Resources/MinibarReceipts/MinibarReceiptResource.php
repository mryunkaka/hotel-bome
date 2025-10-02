<?php

namespace App\Filament\Resources\MinibarReceipts;

use App\Filament\Resources\MinibarReceipts\Pages\CreateMinibarReceipt;
use App\Filament\Resources\MinibarReceipts\Pages\EditMinibarReceipt;
use App\Filament\Resources\MinibarReceipts\Pages\ListMinibarReceipts;
use App\Filament\Resources\MinibarReceipts\Schemas\MinibarReceiptForm;
use App\Filament\Resources\MinibarReceipts\Tables\MinibarReceiptsTable;
use App\Models\MinibarReceipt;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class MinibarReceiptResource extends Resource
{
    protected static ?string $model = MinibarReceipt::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $navigationLabel = 'Minibar';

    public static function form(Schema $schema): Schema
    {
        return MinibarReceiptForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MinibarReceiptsTable::configure($table);
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
            'index' => ListMinibarReceipts::route('/'),
            'create' => CreateMinibarReceipt::route('/create'),
            'edit' => EditMinibarReceipt::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
