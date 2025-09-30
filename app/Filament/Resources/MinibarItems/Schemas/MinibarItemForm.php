<?php

namespace App\Filament\Resources\MinibarItems\Schemas;

use App\Models\MinibarItem;
use Filament\Support\RawJs;
use Filament\Schemas\Schema;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Illuminate\Support\Facades\Session;
use Filament\Forms\Components\TextInput;

class MinibarItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Hidden::make('hotel_id')
                ->default(fn() => Session::get('active_hotel_id'))
                ->required(),

            // SKU dihapus dari form → di-generate otomatis di model

            TextInput::make('name')
                ->label('Item Name')
                ->required(),

            Select::make('category')
                ->label('Category')
                ->options(MinibarItem::categoryOptions())
                ->searchable()
                ->preload()
                ->required(),

            Select::make('unit')
                ->label('Unit')
                ->options(MinibarItem::unitOptions())
                ->searchable()
                ->preload()
                ->required()
                ->default('pcs'),

            TextInput::make('default_cost_price')
                ->label('Default Cost Price')
                ->mask(RawJs::make('$money($input)'))
                ->stripCharacters(',')
                ->numeric()
                ->required()
                ->default(0.00),

            TextInput::make('default_sale_price')
                ->label('Default Sale Price')
                ->mask(RawJs::make('$money($input)'))
                ->stripCharacters(',')
                ->numeric()
                ->required()
                ->default(0.00),

            TextInput::make('current_stock')
                ->label('Current Stock')
                ->numeric()
                ->default(0)
                ->disabled(), // rekomendasi: stok dikelola dari ledger/stock movements

            TextInput::make('reorder_level')
                ->label('Reorder Level')
                ->numeric()
                ->required()
                ->default(0),

            Toggle::make('is_active')
                ->label('Active')
                ->default(true)
                ->required(),
        ]);
    }
}
