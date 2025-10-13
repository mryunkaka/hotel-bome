<?php

namespace App\Filament\Widgets;

use App\Models\Booking;
use Filament\Tables;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class LatestBookings extends BaseWidget
{
    public function getHeading(): string|Htmlable|null
    {
        return 'Booking Terbaru';
    }

    public function getColumnSpan(): int|string|array
    {
        return 'full';
    }

    protected function getTableQuery(): Builder
    {
        $hid = (int) (session('active_hotel_id') ?? 0);

        return Booking::query()
            ->when($hid, fn(Builder $q) => $q->where('hotel_id', $hid))
            ->select(['id', 'hotel_id', 'room_id', 'guest_id', 'status', 'created_at'])
            ->with([
                'room:id,room_no',
                'guest:id,name',
            ])
            ->latest('created_at')
            ->limit(7);
    }

    protected function getTableColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('created_at')
                ->label('Tanggal')
                ->dateTime('d/m/Y H:i')
                ->sortable(),

            Tables\Columns\TextColumn::make('room.room_no')
                ->label('Room')
                ->toggleable(),

            Tables\Columns\TextColumn::make('guest.name')
                ->label('Guest')
                ->toggleable(),

            Tables\Columns\TextColumn::make('status')
                ->label('Status')
                ->badge()
                ->formatStateUsing(fn($state) => ucfirst(str_replace('_', ' ', (string) $state)))
                ->colors([
                    'info'    => 'booked',
                    'warning' => 'checked_in',
                    'success' => 'checked_out',
                    'danger'  => 'canceled',
                ]),
        ];
    }

    protected function isTablePaginationEnabled(): bool
    {
        return false;
    }
}
