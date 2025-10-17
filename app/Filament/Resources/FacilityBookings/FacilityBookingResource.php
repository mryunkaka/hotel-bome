<?php

namespace App\Filament\Resources\FacilityBookings;

use BackedEnum;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use App\Models\FacilityBooking;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\FacilityBookings\Pages\EditFacilityBooking;
use App\Filament\Resources\FacilityBookings\Pages\ListFacilityBookings;
use App\Filament\Resources\FacilityBookings\Pages\CreateFacilityBooking;
use App\Filament\Resources\FacilityBookings\Schemas\FacilityBookingForm;
use App\Filament\Resources\FacilityBookings\Tables\FacilityBookingsTable;
use App\Filament\Resources\FacilityBookings\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\FacilityBookings\RelationManagers\CateringItemsRelationManager;

class FacilityBookingResource extends Resource
{
    protected static ?string $model = FacilityBooking::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?string $recordTitleAttribute = 'facilitybooking';

    public static function form(Schema $schema): Schema
    {
        return FacilityBookingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FacilityBookingsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            // CateringItemsRelationManager::class,
            // PaymentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFacilityBookings::route('/'),
            'create' => CreateFacilityBooking::route('/create'),
            'edit' => EditFacilityBooking::route('/{record}/edit'),
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
