<?php

namespace App\Filament\Resources\RoomBoards;

use App\Filament\Resources\RoomBoards\Pages\CreateRoomBoard;
use App\Filament\Resources\RoomBoards\Pages\EditRoomBoard;
use App\Filament\Resources\RoomBoards\Pages\ListRoomBoards;
use App\Filament\Resources\RoomBoards\Schemas\RoomBoardForm;
use App\Filament\Resources\RoomBoards\Tables\RoomBoardsTable;
use App\Models\Room;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class RoomBoardResource extends Resource
{
    protected static ?string $model = Room::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $recordTitleAttribute = 'room';

    protected static ?string $navigationLabel = 'Room Board';

    public static function form(Schema $schema): Schema
    {
        return RoomBoardForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RoomBoardsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListRoomBoards::route('/'),
            'create' => CreateRoomBoard::route('/create'),
            'edit'   => EditRoomBoard::route('/{record}/edit'),
        ];
    }

    // ⬇️ Ini yang mengarahkan menu langsung ke /create
    public static function getNavigationUrl(): string
    {
        return static::getUrl('create');
    }
}
