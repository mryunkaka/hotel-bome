<?php

namespace App\Filament\Resources\MinibarVendors\Schemas;

use Filament\Schemas\Schema;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Illuminate\Support\Facades\Session;
use Filament\Forms\Components\TextInput;

class MinibarVendorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Hidden::make('hotel_id')
                ->default(fn() => Session::get('active_hotel_id'))
                ->required(),

            TextInput::make('name')
                ->label('Vendor Name')
                ->required(),

            TextInput::make('contact_person')
                ->label('Contact Person'),

            TextInput::make('phone')
                ->tel()
                ->label('Phone Number'),

            TextInput::make('email')
                ->label('Email Address')
                ->email(),

            TextInput::make('address')
                ->label('Address'),

            Textarea::make('notes')
                ->label('Notes')
                ->columnSpanFull(),
        ]);
    }
}
