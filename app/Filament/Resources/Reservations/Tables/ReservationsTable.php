<?php

namespace App\Filament\Resources\Reservations\Tables;

use Filament\Tables\Table;
use Filament\Support\RawJs;
use Filament\Actions\EditAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Actions\ForceDeleteBulkAction;

class ReservationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                $query->with(['guest', 'group', 'creator'])
                    ->latest('created_at');
            })
            ->columns([
                TextColumn::make('reservation_no')
                    ->searchable(),
                TextColumn::make('party')   // field virtual
                    ->label('Guest / Group')
                    ->getStateUsing(function ($record) {
                        if ($record->group_id && $record->group) {
                            return $record->group->name;                 // tampilkan nama group
                        }
                        if ($record->guest_id && $record->guest) {
                            // pakai accessor jika ada, fallback ke salutation + name
                            return $record->guest->display_name
                                ?? trim(((is_object($record->guest->salutation) && method_exists($record->guest->salutation, 'value'))
                                    ? $record->guest->salutation->value
                                    : $record->guest->salutation) . ' ' . $record->guest->name);
                        }
                        return $record->reserved_by ?? '-';              // fallback lama
                    })
                    ->badge()                                            // opsional: style badge
                    ->colors([                                           // warna beda untuk guest vs group
                        'info'  => fn($record) => (bool) $record->group_id,
                        'success' => fn($record) => (bool) $record->guest_id && ! $record->group_id,
                    ])
                    ->sortable()
                    // searchable custom untuk kolom virtual
                    ->searchable(),
                TextColumn::make('expected_arrival')
                    ->searchable()
                    ->label('Arrival'),
                TextColumn::make('expected_departure')
                    ->searchable()
                    ->label('Departure'),
                TextColumn::make('deposit')
                    ->numeric()
                    ->searchable(),
                TextColumn::make('creator.name')
                    ->label('Entry By')
                    ->default('-')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
