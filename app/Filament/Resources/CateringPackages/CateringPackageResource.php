<?php

namespace App\Filament\Resources\CateringPackages;

use App\Filament\Resources\CateringPackages\Pages\CreateCateringPackage;
use App\Filament\Resources\CateringPackages\Pages\EditCateringPackage;
use App\Filament\Resources\CateringPackages\Pages\ListCateringPackages;
use App\Filament\Resources\CateringPackages\Schemas\CateringPackageForm;
use App\Filament\Resources\CateringPackages\Tables\CateringPackagesTable;
use App\Models\CateringPackage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CateringPackageResource extends Resource
{
    protected static ?string $model = CateringPackage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'catering';

    public static function form(Schema $schema): Schema
    {
        return CateringPackageForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CateringPackagesTable::configure($table);
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
            'index' => ListCateringPackages::route('/'),
            'create' => CreateCateringPackage::route('/create'),
            'edit' => EditCateringPackage::route('/{record}/edit'),
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
