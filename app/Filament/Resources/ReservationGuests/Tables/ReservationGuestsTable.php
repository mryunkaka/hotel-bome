<?php

namespace App\Filament\Resources\ReservationGuests\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Actions\Action as TableAction;

class ReservationGuestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reservation.reservation_no')
                    ->label('Kode Checkin')
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
                TableAction::make('print_checkin_single')
                    ->label('Print Check-in (Single)')
                    ->icon('heroicon-m-printer')
                    ->color('info')
                    ->url(fn($record) => route('reservation-guests.print', [
                        'guest' => $record->id,
                        'mode'  => 'single', // ⬅️ cetak hanya guest ini
                    ]))
                    ->openUrlInNewTab(),

                TableAction::make('print_checkin_all')
                    ->label('Print Check-in (All)')
                    ->icon('heroicon-o-printer')
                    ->color('success')
                    ->visible(fn($record) => $record->reservation?->reservationGuests()->count() > 1)
                    ->url(fn($record) => route('reservation-guests.print', [
                        'guest' => $record->id,
                        'mode'  => 'all', // ⬅️ cetak seluruh guest dalam reservasi ini
                    ]))
                    ->openUrlInNewTab(),
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
