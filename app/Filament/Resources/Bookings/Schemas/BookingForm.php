<?php

namespace App\Filament\Resources\Bookings\Schemas;

use App\Models\Room;
use App\Models\Booking;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Illuminate\Support\Facades\Session;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\DateTimePicker;
use Filament\Schemas\Components\Utilities\Get;

class BookingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('hotel_id')
                    ->default(fn() => Session::get('active_hotel_id'))
                    ->dehydrated(true)
                    ->required(),

                // ❗ Room hanya yang AVAILABLE pada rentang tanggal
                Select::make('room_id')
                    ->label('Room')
                    ->searchable()
                    ->native(false)
                    ->live() // re-render options saat tanggal berubah
                    ->options(function (Get $get, ?Booking $record) {
                        $hid   = (int) (Session::get('active_hotel_id') ?? 0);
                        $start = $get('check_in_at') ? Carbon::parse($get('check_in_at')) : null;
                        $end   = $get('check_out_at') ? Carbon::parse($get('check_out_at')) : null;

                        // Jika end kosong, anggap 1 hari setelah start (atau atur sesuai kebijakan)
                        if ($start && ! $end) {
                            $end = (clone $start)->addDay();
                        }

                        return Room::query()
                            ->where('hotel_id', $hid)
                            ->whereDoesntHave('bookings', function ($q) use ($start, $end, $record) {
                                // abaikan booking ini sendiri saat edit
                                if ($record?->id) {
                                    $q->where('id', '!=', $record->id);
                                }

                                // booking yang masih aktif (bukan checked_out)
                                $q->whereNotIn('status', ['checked_out']);

                                // logika overlap:
                                // [existing_start, existing_end) OVERLAPS [start, end)
                                if ($start && $end) {
                                    $q->where(function ($w) use ($start, $end) {
                                        $w->where('check_in_at', '<', $end)
                                            ->where(function ($w2) use ($start) {
                                                $w2->whereNull('check_out_at')
                                                    ->orWhere('check_out_at', '>', $start);
                                            });
                                    });
                                } elseif ($start) {
                                    // jika hanya start, exclude yang end > start
                                    $q->where(function ($w) use ($start) {
                                        $w->whereNull('check_out_at')
                                            ->orWhere('check_out_at', '>', $start);
                                    });
                                }
                            })
                            ->orderBy('room_no')
                            ->pluck('room_no', 'id')
                            ->toArray();
                    })
                    ->required(),

                Select::make('guest_id')
                    ->relationship('guest', 'name')
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->required(),

                DateTimePicker::make('check_in_at')
                    ->default(now())
                    ->required()
                    ->live(), // trigger refresh options room

                DateTimePicker::make('check_out_at')
                    ->live(), // trigger refresh options room

                TextInput::make('status')
                    ->required()
                    ->default('booked'),

                Textarea::make('notes')
                    ->columnSpanFull(),
            ]);
    }
}
