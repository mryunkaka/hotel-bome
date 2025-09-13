<?php

namespace App\Filament\Resources\AccountLedgers\Schemas;

use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Session;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\DatePicker;

class AccountLedgerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            // hotel_id tidak boleh dipilih manual
            Hidden::make('hotel_id')
                ->default(fn() => Session::get('active_hotel_id'))
                ->dehydrated(true)
                ->required(),

            TextInput::make('debit')
                ->numeric()
                ->default(0.0),

            TextInput::make('credit')
                ->numeric()
                ->default(0.0),

            DatePicker::make('date')
                ->default(today())
                ->required(),

            TextInput::make('method')
                ->maxLength(50),

            TextInput::make('description')
                ->maxLength(255),
        ]);
    }
}
