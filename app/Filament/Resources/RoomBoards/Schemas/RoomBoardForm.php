<?php

namespace App\Filament\Resources\RoomBoards\Schemas;

use App\Models\Room;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\ViewField;

class RoomBoardForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Room Board')
                ->components([
                    ViewField::make('room_board_html')
                        ->view('filament.app.resources.rooms.partials.room-board', [
                            'showTitle' => false,
                        ])
                        ->viewData([
                            'rooms' => self::getRooms(),
                            'stats' => self::getStats(),
                            'total' => Room::count(),
                        ]),
                ])
                ->columnSpanFull(),
        ]);
    }

    private static function getRooms()
    {
        return Room::query()
            ->orderBy('room_no')
            ->get(['id', 'room_no', 'type', 'status', 'floor', 'price']);
    }

    private static function getStats(): array
    {
        return [
            'occupied'      => Room::where('status', Room::ST_OCC)->count(),
            'exp_dep'       => Room::where('status', Room::ST_ED)->count(),
            'vacant_clean'  => Room::where('status', Room::ST_VC)->count(),
            'inspection'    => Room::where('status', Room::ST_VCI)->count(), // VCI
            'vacant_dirty'  => Room::where('status', Room::ST_VD)->count(),
            'house_use'     => Room::where('status', Room::ST_HU)->count(),
            'oo'            => Room::where('status', Room::ST_OOO)->count(),
            'long_stay'     => 0, // tak ada di model — biarkan 0
        ];
    }
}
