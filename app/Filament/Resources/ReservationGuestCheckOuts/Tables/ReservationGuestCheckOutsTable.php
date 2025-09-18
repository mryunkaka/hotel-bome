<?php

namespace App\Filament\Resources\ReservationGuestCheckOuts\Tables;

use Filament\Tables\Table;
use Filament\Actions\Action;
use App\Models\ReservationGuest;
use App\Support\ReservationMath;
use Filament\Actions\EditAction;
use Filament\Tables\Filters\Filter;
use Illuminate\Support\Facades\Auth;
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
                    ->label('Actual Checkin')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('actual_checkout')
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
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
