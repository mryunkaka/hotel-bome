<?php

namespace App\Filament\Resources\Reservations\Tables;

use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Actions\{Action, BulkActionGroup, DeleteBulkAction, ForceDeleteBulkAction, RestoreBulkAction};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str; // 👈 add this
use App\Filament\Resources\Reservations\ReservationResource;
use App\Filament\Resources\Walkins\WalkinResource;

class ReservationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                $query->with(['guest', 'group', 'creator'])
                    ->latest('created_at')
                    ->whereHas('reservationGuests', fn($q) => $q->whereNull('actual_checkin'));
            })

            // Route per row
            ->recordUrl(function ($record) {
                $isWalkin =
                    ($record->option_reservation === 'WALKIN') ||
                    Str::contains((string) $record->reservation_no, 'WALK'); // fallback for legacy rows

                return $isWalkin
                    ? WalkinResource::getUrl('edit', ['record' => $record])
                    : ReservationResource::getUrl('edit', ['record' => $record]);
            })

            ->columns([
                TextColumn::make('reservation_no')
                    ->label('No Register')
                    ->searchable()
                    ->url(function ($record) {
                        $isWalkin =
                            ($record->option_reservation === 'WALKIN') ||
                            Str::contains((string) $record->reservation_no, 'WALK');

                        return $isWalkin
                            ? WalkinResource::getUrl('edit', ['record' => $record])
                            : ReservationResource::getUrl('edit', ['record' => $record]);
                    }),

                TextColumn::make('party')
                    ->label('Guest / Group')
                    ->getStateUsing(function ($record) {
                        if ($record?->group_id && $record?->group) return $record->group->name;
                        if ($record?->guest_id && $record?->guest) {
                            return $record->guest->display_name
                                ?? trim(((is_object($record->guest->salutation) && method_exists($record->guest->salutation, 'value'))
                                    ? $record->guest->salutation->value
                                    : $record->guest->salutation) . ' ' . $record->guest->name);
                        }
                        return $record->reserved_by ?? '-';
                    })
                    ->badge()
                    ->colors([
                        'info'    => fn($state, $record = null) => (bool) ($record?->group_id),
                        'success' => fn($state, $record = null) => (bool) ($record?->guest_id && ! $record?->group_id),
                    ])
                    ->sortable()
                    ->searchable(),

                TextColumn::make('expected_arrival')->label('Arrival')->searchable(),
                TextColumn::make('expected_departure')->label('Departure')->searchable(),
                TextColumn::make('deposit')->numeric()->searchable(),
                TextColumn::make('creator.name')->label('Entry By')->default('-')->searchable()->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('option_reservation')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn($state) => $state === 'WALKIN' ? 'WALK-IN' : 'RESERVATION')
                    ->colors([
                        'warning' => fn($state) => $state === 'WALKIN',
                        'gray'    => fn($state) => $state !== 'WALKIN',
                    ])
                    ->toggleable(isToggledHiddenByDefault: true),
            ])

            ->filters([TrashedFilter::make()])

            ->recordActions([
                Action::make('editReservation')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil')
                    ->url(fn($record) => ReservationResource::getUrl('edit', ['record' => $record]))
                    ->visible(fn($record) => $record->option_reservation !== 'WALKIN'),

                Action::make('editWalkin')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil')
                    ->color('success')
                    ->url(fn($record) => WalkinResource::getUrl('edit', ['record' => $record]))
                    ->visible(fn($record) => $record->option_reservation === 'WALKIN'),
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
