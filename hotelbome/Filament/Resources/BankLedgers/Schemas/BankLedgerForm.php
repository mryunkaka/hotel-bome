<?php

namespace App\Filament\Resources\BankLedgers\Schemas;

use Filament\Support\RawJs;
use Filament\Schemas\Schema;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Session;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\DatePicker;

class BankLedgerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('hotel_id')
                    ->default(fn() => Session::get('active_hotel_id'))
                    ->dehydrated(true)
                    ->required(),
                Select::make('bank_id')
                    ->relationship('bank', 'name')
                    ->required(),
                TextInput::make('deposit')
                    ->required()
                    ->numeric()
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->default(0.0),
                TextInput::make('withdraw')
                    ->required()
                    ->numeric()
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->default(0.0),
                DatePicker::make('date')
                    ->default(now())
                    ->required(),
                TextInput::make('description'),
            ]);
    }
}
