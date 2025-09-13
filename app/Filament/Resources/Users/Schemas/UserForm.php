<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Relasi hotel
                Select::make('hotel_id')
                    ->relationship('hotel', 'name')
                    ->required(),

                // Nama
                TextInput::make('name')
                    ->required(),

                // Email
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required(),

                // Password (hash otomatis)
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->dehydrateStateUsing(fn($state) => filled($state) ? Hash::make($state) : null)
                    ->dehydrated(fn($state) => filled($state)) // hanya simpan kalau ada input
                    ->required(fn(string $context): bool => $context === 'create'), // wajib saat create, opsional saat edit

                // Roles (Spatie)
                Select::make('roles')
                    ->relationship('roles', 'name')
                    ->multiple()
                    ->preload()
                    ->searchable()
                    ->label('Roles'),
            ]);
    }
}
