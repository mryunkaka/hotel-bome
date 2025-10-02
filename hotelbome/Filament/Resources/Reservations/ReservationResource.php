<?php

namespace App\Filament\Resources\Reservations;

use BackedEnum;
use Filament\Tables\Table;
use App\Models\Reservation;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\Reservations\Pages\WalkinForm;
use App\Filament\Resources\Reservations\Pages\EditReservation;
use App\Filament\Resources\Reservations\Pages\ListReservations;
use App\Filament\Resources\Reservations\Pages\CreateReservation;
use App\Filament\Resources\Reservations\Schemas\ReservationForm;
use App\Filament\Resources\Reservations\Tables\ReservationsTable;

class ReservationResource extends Resource
{
    protected static ?string $model = Reservation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHomeModern;

    protected static ?string $navigationLabel = 'Reservation List';
    protected static ?string $modelLabel      = 'Reservation List';
    protected static ?string $pluralModelLabel = 'Reservation List';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ReservationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ReservationsTable::configure($table);
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
            'index' => ListReservations::route('/'),
            'create' => CreateReservation::route('/create'),
            'edit' => EditReservation::route('/{record}/edit'),
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
