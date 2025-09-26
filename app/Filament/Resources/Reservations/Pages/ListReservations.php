<?php

namespace App\Filament\Resources\Reservations\Pages;

use App\Models\Reservation;
use Filament\Actions\Action;
use Illuminate\Support\Carbon;
use Filament\Actions\CreateAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use App\Filament\Resources\Reservations\ReservationResource;

class ListReservations extends ListRecords
{
    protected static string $resource = ReservationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Tombol cepat: langsung create + redirect ke edit
            Action::make('newReservation')
                ->label('New Reservation')
                ->icon('heroicon-m-plus')
                ->color('primary')
                ->requiresConfirmation(false)
                ->action(function () {
                    $user    = Auth::user();
                    $hotelId = (int) (session('active_hotel_id') ?? 1);

                    // ini yang ditambahkan: cek apakah sudah ada reservation "kosong" (tanpa guest) yang bisa dipakai ulang
                    $existing = Reservation::query()
                        ->where('hotel_id', $hotelId)
                        ->whereNull('guest_id')          // belum diisi tamu
                        ->whereNull('group_id')          // bukan group juga
                        // opsional pengaman: belum check-in/checkout dan masih "fresh"
                        ->whereNull('checkin_date')
                        ->whereNull('checkout_date')
                        ->whereDate('created_at', today())        // ⬅️ hanya yang dibuat hari ini
                        ->where('created_by', $user->id)
                        ->latest('id')
                        ->first();

                    if ($existing) {
                        // Tidak membuat nomor baru—langsung pakai reservation tersebut
                        Notification::make()
                            ->title('Continue existing reservation')
                            ->body("No: {$existing->reservation_no} (waiting for guest)")
                            ->info()
                            ->send();

                        return redirect()->to(
                            ReservationResource::getUrl('edit', ['record' => $existing])
                        );
                    }

                    // Tidak ada draft/entry tanpa guest → buat baru
                    $record = DB::transaction(function () use ($user, $hotelId) {
                        return Reservation::create([
                            'reservation_no'   => Reservation::generateReservationNo($hotelId),
                            'hotel_id'         => $hotelId,

                            // ====== default yang umum dipakai, silakan sesuaikan ======
                            'option'           => 'WALKIN',     // WALKIN / ONLINE / PHONE, dll.
                            'method'           => 'PERSONAL',   // PERSONAL / COMPANY
                            'status'           => 'CONFIRM',    // DRAFT / CONFIRM / HOLD, dll.
                            'deposit_type'     => 'DP',         // DP / NONE / LAINNYA
                            'deposit'          => 0,            // default 0
                            'reserved_title'   => 'MR',         // MR/MRS/MS
                            'reserved_by'      => $user->name ?? 'Guest',
                            'entry_date'       => Carbon::now(),
                            'expected_arrival' => now()->setTime(13, 0),
                            // ====== audit fields jika ada di tabel ======
                            'created_by'       => $user->id ?? null,
                            'updated_by'       => $user->id ?? null,
                        ]);
                    });

                    Notification::make()
                        ->title('Reservation created')
                        ->body("No: {$record->reservation_no}")
                        ->success()
                        ->send();

                    // Redirect langsung ke halaman edit
                    return redirect()->to(
                        ReservationResource::getUrl('edit', ['record' => $record])
                    );
                }),
        ];
    }
}
