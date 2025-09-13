<?php

namespace App\Filament\Widgets;

use App\Models\Booking;
use Filament\Tables;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class LatestBookings extends BaseWidget
{
    /** Heading widget (hindari properti static agar kompatibel v4) */
    public function getHeading(): string|Htmlable|null
    {
        return 'Booking Terbaru';
    }

    /** Lebar widget pada grid dashboard */
    public function getColumnSpan(): int|string|array
    {
        return 'full';
    }

    /** Query data tabel */
    protected function getTableQuery(): Builder
    {
        $hid = (int) (session('active_hotel_id') ?? 0);

        return Booking::query()
            ->when($hid, fn(Builder $q) => $q->where('hotel_id', $hid))
            ->with(['room:id,room_no', 'guest:id,name'])
            ->latest('created_at')
            ->limit(7);
    }

    /** Definisi kolom tabel */
    protected function getTableColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('created_at')
                ->label('Tanggal')
                ->dateTime('d/m/Y H:i')
                ->sortable(),

            Tables\Columns\TextColumn::make('room.room_no')
                ->label('Room'),

            Tables\Columns\TextColumn::make('guest.name')
                ->label('Guest'),

            Tables\Columns\TextColumn::make('status')
                ->label('Status')
                ->badge()
                ->colors([
                    'success' => 'checked_out',
                    'warning' => 'checked_in',
                    'info'    => 'booked',
                    'danger'  => 'canceled',
                ]),
        ];
    }

    /** Nonaktifkan pagination (karena sudah di-limit 7) */
    protected function isTablePaginationEnabled(): bool
    {
        return false;
    }
}
