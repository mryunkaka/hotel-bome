<?php

namespace App\Filament\Resources\TaxSettings\Schemas;

use Filament\Schemas\Schema;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Illuminate\Support\Facades\Session;
use Filament\Forms\Components\TextInput;

class TaxSettingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('hotel_id')
                    ->default(fn() => Session::get('active_hotel_id'))
                    ->dehydrated(true)
                    ->required(),
                TextInput::make('name')
                    ->required(),
                TextInput::make('percent')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                Toggle::make('is_active')
                    ->required(),
            ]);
    }
}
