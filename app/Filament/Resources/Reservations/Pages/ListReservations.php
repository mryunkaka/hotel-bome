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

            Action::make('newWalkin')
                ->label('New Walk-in')
                ->icon('heroicon-m-plus')
                ->color('success')
                ->requiresConfirmation(false)
                ->action(function () {
                    $user    = Auth::user();
                    $hotelId = (int) (session('active_hotel_id') ?? ($user->hotel_id ?? 1));

                    Log::info('[Walkin Button] CLICK', [
                        'user_id'  => $user?->id,
                        'hotel_id' => $hotelId,
                        'today'    => now()->toDateString(),
                    ]);

                    // 1) COBA LANJUTKAN DRAFT walk-in (guest_id NULL)
                    $existing = Reservation::query()
                        ->where('hotel_id', $hotelId)
                        ->where('option_reservation', 'walkin')     // ⬅️ lowercase sesuai migration
                        ->whereNull('guest_id')                     // ⬅️ hanya draft (belum pilih tamu)
                        ->whereNull('checkin_date')
                        ->whereNull('checkout_date')
                        ->whereDate('created_at', today())
                        ->where('created_by', $user->id)
                        // ->whereHas('reservationGuests')          // opsional: aktifkan jika mau pastikan punya RG
                        ->latest('id')
                        ->first();

                    Log::info('[Walkin Button] SEARCH DRAFT', [
                        'found'         => (bool) $existing,
                        'existing_id'   => $existing?->id,
                        'existing_no'   => $existing?->reservation_no,
                        'guest_id_null' => $existing?->guest_id === null,
                    ]);

                    if ($existing) {
                        Notification::make()
                            ->title('Continue existing walk-in')
                            ->body("No: {$existing->reservation_no} (waiting for guest)")
                            ->info()
                            ->send();

                        Log::info('[Walkin Button] CONTINUE EXISTING', [
                            'reservation_id' => $existing->id,
                            'reservation_no' => $existing->reservation_no,
                        ]);

                        return redirect()->to(
                            WalkinResource::getUrl('edit', ['record' => $existing])
                        );
                    }

                    // 2) TIDAK ADA DRAFT → BUAT BARU
                    $record = DB::transaction(function () use ($user, $hotelId) {
                        $no = Reservation::generateReservationNo($hotelId, 'WALKIN');

                        // Guard opsional: hindari nomor dobel kalau user klik cepat dua kali
                        if (Reservation::where('reservation_no', $no)->exists()) {
                            $no = Reservation::generateReservationNo($hotelId, 'WALKIN');
                        }

                        $reservation = Reservation::create([
                            'hotel_id'           => $hotelId,
                            'reservation_no'     => $no,
                            'option_reservation' => 'walkin',     // ⬅️ lowercase
                            'option'             => 'WALKIN',
                            'method'             => 'PERSONAL',
                            'status'             => 'CONFIRM',
                            'deposit_room'       => 0,
                            'deposit_card'       => 0,
                            'entry_date'         => now(),
                            'expected_arrival'   => now()->setTime(13, 0),
                            'created_by'         => $user->id ?? null,
                        ]);

                        // Buat 1 RG default (guest_id masih null = tetap dianggap draft)
                        ReservationGuest::create([
                            'hotel_id'         => $hotelId,
                            'reservation_id'   => $reservation->id,
                            'pov'              => 'BUSINESS',
                            'person'           => 'PERSONAL ACCOUNT',
                            'charge_to'        => 'PERSONAL ACCOUNT',
                            'breakfast'        => 'Yes',
                            'male'             => 1,
                            'female'           => 0,
                            'children'         => 0,
                            'expected_checkin' => $reservation->expected_arrival,
                        ]);

                        Log::info('[Walkin Button] CREATED NEW', [
                            'reservation_id' => $reservation->id,
                            'reservation_no' => $reservation->reservation_no,
                            'hotel_id'       => $hotelId,
                            'created_by'     => $user?->id,
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
