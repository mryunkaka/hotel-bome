<?php

namespace App\Filament\Resources\Rooms\Schemas;

use Filament\Schemas\Schema;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Illuminate\Support\Facades\Session;
use Filament\Forms\Components\TextInput;
use Filament\Support\RawJs;

class RoomForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('hotel_id')
                    ->default(fn() => Session::get('active_hotel_id'))
                    ->dehydrated(true)
                    ->required(),
                Select::make('type')
                    ->label('Type')
                    ->options([
                        'deluxe_twin'     => 'Deluxe Twin',
                        'deluxe_single'   => 'Deluxe Single',
                        'standard_twin'   => 'Standard Twin',
                        'standard_single' => 'Standard Single',
                        'superior_twin'   => 'Superior Twin',
                        'superior_single' => 'Superior Single',
                    ])
                    ->native(false)
                    ->required(),
                TextInput::make('room_no')
                    ->required(),
                TextInput::make('floor')
                    ->required()
                    ->numeric()
                    ->default(1),
                TextInput::make('price')
                    ->label('Price')
                    ->required()
                    ->prefix('Rp')
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->numeric(),
            ]);
    }
}
