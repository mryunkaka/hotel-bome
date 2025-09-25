<?php

namespace App\Filament\Resources\ReservationGuests\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ReservationGuestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reservation.reservation_no')
                    ->label('Reservation No')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('actual_checkin')
                    ->label('Actual Checkin')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('expected_checkout')
                    ->label('Expected Checkout')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('room.room_no')
                    ->label('Room')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('guest.display_name')
                    ->label('Guest')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('jumlah_orang')
                    ->label('Pax')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('room_rate')
                    ->label('Room Rate')
                    ->money('idr', true)
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->modifyQueryUsing(
                fn(Builder $query) =>
                $query->whereNotNull('actual_checkin')
                    ->whereNull('actual_checkout')
                    ->latest('created_at')
            )
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
