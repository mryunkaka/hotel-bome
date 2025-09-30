<?php

namespace App\Filament\Resources\MinibarItems;

use App\Filament\Resources\MinibarItems\Pages\CreateMinibarItem;
use App\Filament\Resources\MinibarItems\Pages\EditMinibarItem;
use App\Filament\Resources\MinibarItems\Pages\ListMinibarItems;
use App\Filament\Resources\MinibarItems\Schemas\MinibarItemForm;
use App\Filament\Resources\MinibarItems\Tables\MinibarItemsTable;
use App\Models\MinibarItem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class MinibarItemResource extends Resource
{
    protected static ?string $model = MinibarItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    public static function getNavigationGroup(): string
    {
        return 'Minibar';
    }

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'minibar';

    public static function form(Schema $schema): Schema
    {
        return MinibarItemForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MinibarItemsTable::configure($table);
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
            'index' => ListMinibarItems::route('/'),
            'create' => CreateMinibarItem::route('/create'),
            'edit' => EditMinibarItem::route('/{record}/edit'),
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
