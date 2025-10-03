<?php

namespace App\Filament\Resources\RoomDailyClosings;

use App\Filament\Resources\RoomDailyClosings\Pages\CreateRoomDailyClosing;
use App\Filament\Resources\RoomDailyClosings\Pages\EditRoomDailyClosing;
use App\Filament\Resources\RoomDailyClosings\Pages\ListRoomDailyClosings;
use App\Filament\Resources\RoomDailyClosings\Schemas\RoomDailyClosingForm;
use App\Filament\Resources\RoomDailyClosings\Tables\RoomDailyClosingsTable;
use App\Models\RoomDailyClosing;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class RoomDailyClosingResource extends Resource
{
    protected static ?string $model = RoomDailyClosing::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'room';

    public static function form(Schema $schema): Schema
    {
        return RoomDailyClosingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RoomDailyClosingsTable::configure($table);
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
            'index' => ListRoomDailyClosings::route('/'),
            'create' => CreateRoomDailyClosing::route('/create'),
            'edit' => EditRoomDailyClosing::route('/{record}/edit'),
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
