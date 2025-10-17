<?php

namespace App\Filament\Resources\ReservationGuests\Pages;

use App\Filament\Resources\ReservationGuests\ReservationGuestResource;
use App\Filament\Resources\Walkins\WalkinResource;
use App\Models\Reservation;
use App\Models\ReservationGuest;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ListReservationGuests extends ListRecords
{
    protected static string $resource = ReservationGuestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newWalkin')
                ->label('New Walk-in')
                ->icon('heroicon-m-plus')
                ->color('success')
                ->requiresConfirmation(false)
                ->action(function () {
                    $user    = Auth::user();
                    $hotelId = (int) (session('active_hotel_id') ?? ($user->hotel_id ?? 1));

                    // 1) Lanjutkan draft walk-in (guest_id NULL) milik user & hotel ini
                    $existing = Reservation::query()
                        ->where('hotel_id', $hotelId)
                        ->where('option_reservation', 'walkin')   // lowercase sesuai migration
                        ->whereNull('guest_id')                   // masih draft
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

                    // 2) Tidak ada draft → buat baru
                    $reservation = DB::transaction(function () use ($user, $hotelId) {
                        $no = Reservation::generateReservationNo($hotelId, 'WALKIN');

                        // guard nomor dobel bila klik cepat
                        if (Reservation::where('reservation_no', $no)->exists()) {
                            $no = Reservation::generateReservationNo($hotelId, 'WALKIN');
                        }

                        // ⚠️ HAPUS kolom deposit_room / deposit_card di sini (deposit kini di RG)
                        $res = Reservation::create([
                            'hotel_id'           => $hotelId,
                            'reservation_no'     => $no,
                            'option_reservation' => 'walkin',
                            'option'             => 'WALKIN',
                            'method'             => 'PERSONAL',
                            'status'             => 'CONFIRM',
                            'entry_date'         => now(),
                            'expected_arrival'   => now()->setTime(13, 0),
                            'created_by'         => $user->id ?? null,
                        ]);

                        // Buat 1 ReservationGuest default (guest_id masih null = draft)
                        ReservationGuest::create([
                            'hotel_id'         => $hotelId,
                            'reservation_id'   => $res->id,
                            'pov'              => 'BUSINESS',
                            'person'           => 'PERSONAL ACCOUNT',
                            'charge_to'        => 'PERSONAL ACCOUNT',
                            'breakfast'        => 'Yes',
                            'male'             => 1,
                            'female'           => 0,
                            'children'         => 0,
                            'expected_checkin' => $res->expected_arrival,
                            // deposit_room / deposit_card ada di tabel RG—biarkan 0 dulu
                        ]);

                        return $res;
                    });

                    Notification::make()
                        ->title('Walk-in created')
                        ->body("No: {$reservation->reservation_no}")
                        ->success()
                        ->send();

                    return redirect()->to(
                        WalkinResource::getUrl('edit', ['record' => $reservation])
                    );
                }),
        ];
    }
}
