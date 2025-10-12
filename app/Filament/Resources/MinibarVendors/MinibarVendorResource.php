<?php

namespace App\Filament\Resources\MinibarVendors;

use BackedEnum;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use App\Models\MinibarVendor;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Traits\ForbidReceptionistResource;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\MinibarVendors\Pages\EditMinibarVendor;
use App\Filament\Resources\MinibarVendors\Pages\ListMinibarVendors;
use App\Filament\Resources\MinibarVendors\Pages\CreateMinibarVendor;
use App\Filament\Resources\MinibarVendors\Schemas\MinibarVendorForm;
use App\Filament\Resources\MinibarVendors\Tables\MinibarVendorsTable;

class MinibarVendorResource extends Resource
{
    use ForbidReceptionistResource;

    protected static ?string $model = MinibarVendor::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $recordTitleAttribute = 'minibarvendor';

    public static function getNavigationGroup(): string
    {
        return 'Minibar';
    }

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return MinibarVendorForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MinibarVendorsTable::configure($table);
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
            'index' => ListMinibarVendors::route('/'),
            'create' => CreateMinibarVendor::route('/create'),
            'edit' => EditMinibarVendor::route('/{record}/edit'),
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
