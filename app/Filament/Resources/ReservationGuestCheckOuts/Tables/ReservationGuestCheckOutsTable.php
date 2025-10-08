<?php

namespace App\Filament\Resources\ReservationGuestCheckOuts\Tables;

use Filament\Tables\Table;
use Filament\Actions\EditAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;

class ReservationGuestCheckOutsTable
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
                    ->label('Actual Check-in')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('actual_checkout')
                    ->label('Actual Check-out')
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
                // tambahkan filter lain jika perlu
            ])
            ->recordActions([
                EditAction::make(),
            ])
            // hanya tampilkan yang SUDAH check-in DAN BELUM check-out
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
