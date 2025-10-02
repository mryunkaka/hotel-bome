<?php

namespace App\Filament\Resources\Reservations\Pages;

use App\Models\Reservation;
use Filament\Actions\Action;
use Illuminate\Support\Carbon;
use App\Models\ReservationGuest;
use Filament\Actions\CreateAction;
use Illuminate\Support\Facades\DB;
use Filament\Forms\Components\Radio;
use Illuminate\Support\Facades\Auth;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use App\Filament\Resources\Walkins\WalkinResource;
use App\Filament\Resources\Walkins\Schemas\WalkinForm;
use App\Filament\Resources\Reservations\ReservationResource;

class ListReservations extends ListRecords
{
    protected static string $resource = ReservationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // === New Reservation (otomatis buat / reuse draft, lalu ke edit) ===
            Action::make('newReservation')
                ->label('Reservation')
                ->icon('heroicon-m-plus')
                ->color('primary')
                ->requiresConfirmation(false)
                ->action(function () {
                    $user    = Auth::user();
                    $hotelId = (int) (session('active_hotel_id') ?? 1);

                    // Reuse draft kosong hari ini milik user (mode RESERVATION)
                    $existing = Reservation::query()
                        ->where('hotel_id', $hotelId)
                        ->whereNull('guest_id')
                        ->whereNull('group_id')
                        ->whereNull('checkin_date')
                        ->whereNull('checkout_date')
                        ->whereDate('created_at', today())
                        ->where('created_by', $user->id)
                        ->latest('id')
                        ->first();

                    if ($existing) {
                        Notification::make()
                            ->title('Continue existing reservation')
                            ->body("No: {$existing->reservation_no} (waiting for guest)")
                            ->info()
                            ->send();

                        return redirect()->to(
                            ReservationResource::getUrl('edit', ['record' => $existing])
                        );
                    }

                    // Tidak ada draft → buat baru
                    $record = DB::transaction(function () use ($user, $hotelId) {
                        return Reservation::create([
                            'reservation_no'   => Reservation::generateReservationNo($hotelId, 'RESERVATION'),
                            'hotel_id'         => $hotelId,

                            // default inputan awal
                            'option'           => 'WALKIN',
                            'method'           => 'PERSONAL',
                            'status'           => 'CONFIRM',
                            'deposit_type'     => 'DP',
                            'deposit'          => 0,
                            'reserved_title'   => 'MR',
                            'reserved_by'      => $user->name ?? 'Guest',
                            'entry_date'       => now(),
                            'expected_arrival' => now()->setTime(13, 0),

                            'created_by'       => $user->id ?? null,
                            'updated_by'       => $user->id ?? null,
                        ]);
                    });

                    Notification::make()
                        ->title('Reservation created')
                        ->body("No: {$record->reservation_no}")
                        ->success()
                        ->send();

                    return redirect()->to(
                        ReservationResource::getUrl('edit', ['record' => $record])
                    );
                }),

            // === Walk-in (langsung ke halaman WalkinForm) ===
            Action::make('newWalkin')
                ->label('New Walk-in')
                ->icon('heroicon-m-plus')
                ->color('success')
                ->requiresConfirmation(false)
                ->action(function () {
                    $user    = Auth::user();
                    $hotelId = (int) (session('active_hotel_id') ?? ($user->hotel_id ?? 1));

                    // Cari draft walk-in kosong milik user hari ini
                    $existing = Reservation::query()
                        ->where('hotel_id', $hotelId)
                        ->where('option_reservation', 'WALKIN')
                        ->whereNull('guest_id')
                        ->whereNull('group_id')
                        ->whereNull('checkin_date')
                        ->whereNull('checkout_date')
                        ->whereDate('created_at', today())
                        ->where('created_by', $user->id)
                        ->latest('id')
                        ->first();

                    if ($existing) {
                        Notification::make()
                            ->title('Continue existing walk-in')
                            ->body("No: {$existing->reservation_no} (waiting for guest)")
                            ->info()
                            ->send();

                        return redirect()->to(
                            WalkinResource::getUrl('edit', ['record' => $existing])
                        );
                    }

                    // Tidak ada draft → buat baru + 1 ReservationGuest default
                    $record = DB::transaction(function () use ($user, $hotelId) {
                        $reservation = Reservation::create([
                            'hotel_id'           => $hotelId,
                            'reservation_no'     => Reservation::generateReservationNo($hotelId, 'WALKIN'),
                            'option_reservation' => 'WALKIN',

                            // Default awal — sejajar dengan ReservationForm
                            'option'             => 'WALKIN',
                            'method'             => 'PERSONAL',
                            'status'             => 'CONFIRM',
                            'deposit_room'       => 0,
                            'deposit_card'       => 0,
                            'entry_date'         => now(),

                            // default arrival: hari ini 13:00
                            'expected_arrival'   => now()->setTime(13, 0),

                            'created_by'         => $user->id ?? null,
                        ]);

                        // ⬇️ Buat 1 draft ReservationGuest ter-link ke reservation
                        ReservationGuest::create([
                            'hotel_id'         => $hotelId,
                            'reservation_id'   => $reservation->id,
                            // guest_id dibiarkan null dulu; akan dipilih di form
                            'pov'              => 'BUSINESS',
                            'person'           => 'PERSONAL ACCOUNT',
                            'charge_to'        => 'PERSONAL ACCOUNT',
                            'breakfast'        => 'Yes',
                            'male'             => 1,
                            'female'           => 0,
                            'children'         => 0,
                            // jumlah_orang akan otomatis dihitung via mutator (male+female+children)
                            'expected_checkin' => $reservation->expected_arrival,
                        ]);

                        return $reservation;
                    });

                    Notification::make()
                        ->title('Walk-in created')
                        ->body("No: {$record->reservation_no}")
                        ->success()
                        ->send();

                    return redirect()->to(
                        WalkinResource::getUrl('edit', ['record' => $record])
                    );
                }),
        ];
    }
}
