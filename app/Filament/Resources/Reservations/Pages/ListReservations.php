<?php

namespace App\Filament\Resources\Reservations\Pages;

use App\Models\Reservation;
use App\Models\ReservationGuest;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log; // ⬅️ penting
use App\Filament\Resources\Reservations\ReservationResource;
use App\Filament\Resources\Walkins\WalkinResource;

class ListReservations extends ListRecords
{
    protected static string $resource = ReservationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // App\Filament\Resources\Reservations\Pages\ListReservations.php

            // ... use Log; dll tetap

            Action::make('reservation')
                ->label('Reservation')
                ->icon('heroicon-m-plus')
                ->color('primary')
                ->requiresConfirmation(false)
                ->action(function () {
                    $user    = Auth::user();
                    $hotelId = (int) (session('active_hotel_id') ?? ($user->hotel_id ?? 1));

                    // HANYA cari draft yang guest_id-nya MASIH NULL
                    $existing = \App\Models\Reservation::query()
                        ->where('hotel_id', $hotelId)
                        ->where('option_reservation', 'reservation') // lowercase sesuai migration
                        ->whereNull('guest_id')                      // ⬅️ kunci utama permintaanmu
                        ->whereNull('checkin_date')
                        ->whereNull('checkout_date')
                        ->whereDate('created_at', today())
                        ->where('created_by', $user->id)
                        ->latest('id')
                        ->first();

                    if ($existing) {
                        \Filament\Notifications\Notification::make()
                            ->title('Continue existing reservation')
                            ->body("No: {$existing->reservation_no} (waiting for guest)")
                            ->info()->send();

                        return redirect()->to(
                            \App\Filament\Resources\Reservations\ReservationResource::getUrl('edit', ['record' => $existing])
                        );
                    }

                    // Tidak ada draft dgn guest_id null → buat BARU
                    $record = \Illuminate\Support\Facades\DB::transaction(function () use ($user, $hotelId) {
                        return \App\Models\Reservation::create([
                            'reservation_no'     => \App\Models\Reservation::generateReservationNo($hotelId, 'RESERVATION'),
                            'hotel_id'           => $hotelId,
                            'option_reservation' => 'reservation', // lowercase
                            'option'             => 'WALKIN',
                            'method'             => 'PERSONAL',
                            'status'             => 'CONFIRM',
                            'deposit_type'       => 'DP',
                            'deposit'            => 0,
                            'reserved_title'     => 'MR',
                            'reserved_by'        => $user->name ?? 'Guest',
                            'entry_date'         => now(),
                            'expected_arrival'   => now()->setTime(13, 0),
                            'created_by'         => $user->id ?? null,
                            'updated_by'         => $user->id ?? null,
                        ]);
                    });

                    \Filament\Notifications\Notification::make()
                        ->title('Reservation created')
                        ->body("No: {$record->reservation_no}")
                        ->success()->send();

                    return redirect()->to(
                        \App\Filament\Resources\Reservations\ReservationResource::getUrl('edit', ['record' => $record])
                    );
                }),
        ];
    }
}
