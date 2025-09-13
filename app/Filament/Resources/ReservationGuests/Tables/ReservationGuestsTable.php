<?php

namespace App\Filament\Resources\ReservationGuests\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ReservationGuestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('hotel.name')
                    ->searchable(),
                TextColumn::make('reservation.id')
                    ->searchable(),
                TextColumn::make('guest.name')
                    ->searchable(),
                TextColumn::make('room.id')
                    ->searchable(),
                TextColumn::make('person')
                    ->searchable(),
                TextColumn::make('jumlah_orang')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('male')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('female')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('children')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('charge_to')
                    ->searchable(),
                TextColumn::make('room_rate')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('expected_checkin')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('expected_checkout')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('actual_checkin')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('actual_checkout')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
