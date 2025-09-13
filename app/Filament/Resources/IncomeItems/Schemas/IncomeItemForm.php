<?php

namespace App\Filament\Resources\IncomeItems\Schemas;

use Filament\Support\RawJs;
use Filament\Schemas\Schema;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Session;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\DateTimePicker;

class IncomeItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('hotel_id')
                    ->default(fn() => Session::get('active_hotel_id'))
                    ->dehydrated(true)
                    ->required(),
                Select::make('income_category_id')
                    ->relationship('incomeCategory', 'name')
                    ->preload()
                    ->searchable()
                    ->required(),
                TextInput::make('amount')
                    ->required()
                    ->prefix('Rp')
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->numeric(),
                TextInput::make('description'),
                DateTimePicker::make('date')
                    ->default('now')
                    ->required(),
            ]);
    }
}
