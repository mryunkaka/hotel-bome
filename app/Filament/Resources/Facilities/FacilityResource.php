<?php
// ===== FILE: app/Filament/Resources/Facilities/FacilityResource.php


namespace App\Filament\Resources\Facilities;


use App\Filament\Resources\Facilities\Pages\CreateFacility;
use App\Filament\Resources\Facilities\Pages\EditFacility;
use App\Filament\Resources\Facilities\Pages\ListFacilities;
use App\Filament\Resources\Facilities\RelationManagers\FacilityBookingsRelationManager;
use App\Filament\Resources\Facilities\Schemas\FacilityForm;
use App\Filament\Resources\Facilities\Tables\FacilitiesTable;
use App\Models\Facility;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon; // v4 icon helper
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use BackedEnum;

class FacilityResource extends Resource
{
    protected static ?string $model = Facility::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::BuildingOffice2;

    protected static ?string $recordTitleAttribute = 'Facilities';
    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): string
    {
        return 'Facility';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Facilities';
    }

    public static function form(Schema $schema): Schema
    {
        return FacilityForm::configure($schema);
    }


    public static function table(Table $table): Table
    {
        return FacilitiesTable::configure($table);
    }


    public static function getRelations(): array
    {
        return [
            // FacilityBookingsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFacilities::route('/'),
            'create' => CreateFacility::route('/create'),
            'edit' => EditFacility::route('/{record}/edit'),
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
