<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReservationGuests\Schemas;

use App\Models\Room;
use Filament\Support\RawJs;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use App\Models\ReservationGuest;
use Illuminate\Support\Facades\DB;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Placeholder;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Utilities\Get;
use App\Filament\Resources\ReservationGuests\ReservationGuestResource;

final class ReservationGuestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Registration View')
                ->collapsible()
                ->schema([
                    Grid::make()
                        ->schema([
                            ViewField::make('registration_preview')
                                ->view('filament.forms.components.registration-preview')
                                ->columnSpanFull(),

                            // === Row 1: 2 tombol utama, rapi & center
                            Actions::make([
                                Action::make('change_room_rate')
                                    ->label('Change Room / Rate')
                                    ->icon('heroicon-o-arrow-path')
                                    ->color('primary')
                                    ->button()
                                    ->visible(fn(ReservationGuest $record) => filled($record->id))
                                    ->modalHeading('Change Room / Rate')
                                    ->modalSubmitActionLabel('Apply Changes')
                                    ->schema([
                                        \Filament\Forms\Components\Select::make('rate_option')
                                            ->label('Option')
                                            ->native(false)
                                            ->options([
                                                'WALK-IN'    => 'WALK-IN',
                                                'GOVERNMENT' => 'GOVERNMENT',
                                                'CORPORATE'  => 'CORPORATE',
                                                'TRAVEL'     => 'TRAVEL',
                                                'OTA'        => 'OTA',
                                                'WEEKLY'     => 'WEEKLY',
                                                'MONTHLY'    => 'MONTHLY',
                                            ])
                                            ->default('WALK-IN')
                                            ->columnSpanFull(),

                                        Select::make('room_id_new')
                                            ->label('Move To Room')
                                            ->native(false)
                                            ->searchable()
                                            ->required()
                                            ->default(fn(ReservationGuest $record) => $record->room_id)
                                            ->options(function (ReservationGuest $record) {
                                                $hid = \Illuminate\Support\Facades\Session::get('active_hotel_id') ?? \Illuminate\Support\Facades\Auth::user()?->hotel_id;
                                                $currentRoomId = (int) $record->room_id;

                                                return \App\Models\Room::query()
                                                    ->where(function ($q) use ($hid, $currentRoomId) {
                                                        // hanya tampilkan room VCI & tidak sedang ditempati
                                                        $q->where('hotel_id', $hid)
                                                            ->where('status', \App\Models\Room::ST_VCI)
                                                            ->whereNotExists(function ($sub) use ($hid) {
                                                                $sub->from('reservation_guests as rg')
                                                                    ->whereColumn('rg.room_id', 'rooms.id')
                                                                    ->where('rg.hotel_id', $hid)
                                                                    ->whereNotNull('rg.actual_checkin')
                                                                    ->whereNull('rg.actual_checkout');
                                                            });

                                                        // tetap sertakan room sekarang (agar terlihat saat edit)
                                                        if ($currentRoomId > 0) {
                                                            $q->orWhere('id', $currentRoomId);
                                                        }
                                                    })
                                                    ->orderBy('room_no')
                                                    ->limit(200)
                                                    ->pluck('room_no', 'id')
                                                    ->toArray();
                                            })
                                            ->columnSpanFull(),

                                        \Filament\Forms\Components\TextInput::make('extra_bed')
                                            ->label('Extra Bed')
                                            ->numeric()
                                            ->minValue(0)
                                            ->default(fn(ReservationGuest $record) => (int) ($record->extra_bed ?? 0)),

                                        \Filament\Forms\Components\Select::make('breakfast')
                                            ->label('Breakfast')
                                            ->native(false)
                                            ->options(['Yes' => 'Yes', 'No' => 'No'])
                                            ->default(fn(ReservationGuest $record) => $record->breakfast ?? 'No'),

                                        \Filament\Forms\Components\TextInput::make('discount_percent')
                                            ->label('Discount (%)')
                                            ->numeric()
                                            ->step('0.01')
                                            ->minValue(0)
                                            ->maxValue(100)
                                            ->default(fn(ReservationGuest $record) => (float) ($record->discount_percent ?? 0)),
                                    ])
                                    ->action(function (array $data, ReservationGuest $record, $livewire) {
                                        $newRoomId = (int) ($data['room_id_new'] ?? 0);
                                        $oldRoomId = (int) ($record->room_id ?? 0);

                                        // Harga dasar room baru
                                        $basePrice = $newRoomId ? (int) \App\Models\Room::whereKey($newRoomId)->value('price') : null;
                                        if (! $newRoomId || $basePrice === null) {
                                            \Filament\Notifications\Notification::make()
                                                ->title('Gagal')
                                                ->body('Room atau harga tidak valid.')
                                                ->danger()
                                                ->send();
                                            return;
                                        }

                                        // (opsional) diskon otomatis per option
                                        $opt = (string) ($data['rate_option'] ?? 'WALK-IN');
                                        $discountByOption = [
                                            'WALK-IN'    => 0,
                                            'GOVERNMENT' => 5,
                                            'CORPORATE'  => 8,
                                            'TRAVEL'     => 5,
                                            'OTA'        => 0,
                                            'WEEKLY'     => 10,
                                            'MONTHLY'    => 20,
                                        ];
                                        $autoDisc  = $discountByOption[$opt] ?? 0;
                                        $discInput = isset($data['discount_percent']) ? (float) $data['discount_percent'] : null;
                                        $finalDisc = $discInput !== null ? max(0, min(100, $discInput)) : $autoDisc;

                                        $isCheckedIn = filled($record->actual_checkin); // sudah check-in?

                                        \Illuminate\Support\Facades\DB::transaction(function () use (
                                            $record,
                                            $newRoomId,
                                            $oldRoomId,
                                            $basePrice,
                                            $data,
                                            $finalDisc,
                                            $isCheckedIn
                                        ) {
                                            $now = now();

                                            // 1) Update RG: room & rate + input modal
                                            $record->forceFill([
                                                'room_id'          => $newRoomId,
                                                'room_rate'        => (int) $basePrice,
                                                'extra_bed'        => (int) ($data['extra_bed'] ?? 0),
                                                'breakfast'        => (string) ($data['breakfast'] ?? 'No'),
                                                'discount_percent' => (float) $finalDisc,
                                            ])->save();

                                            // 2) Ubah status ROOM
                                            if ($oldRoomId && $oldRoomId !== $newRoomId) {
                                                // Cek apakah masih ada tamu aktif lain di room lama
                                                $oldHasActiveOthers = \App\Models\ReservationGuest::query()
                                                    ->where('room_id', $oldRoomId)
                                                    ->where('id', '!=', $record->id)
                                                    ->whereNull('actual_checkout')
                                                    ->exists();

                                                if (! $oldHasActiveOthers) {
                                                    // Jika tamu sudah check-in → lama = VD; jika masih reservasi → lama = VCI
                                                    $oldStatus = $isCheckedIn ? \App\Models\Room::ST_VD : \App\Models\Room::ST_VCI;
                                                    \App\Models\Room::whereKey($oldRoomId)->update([
                                                        'status'            => $oldStatus,
                                                        'status_changed_at' => $now,
                                                    ]);
                                                }
                                            }

                                            // Room baru: jika sudah check-in → OCC; jika masih reservasi → RS
                                            $newStatus = $isCheckedIn ? \App\Models\Room::ST_OCC : \App\Models\Room::ST_RS;
                                            \App\Models\Room::whereKey($newRoomId)->update([
                                                'status'            => $newStatus,
                                                'status_changed_at' => $now,
                                            ]);
                                        });

                                        \Filament\Notifications\Notification::make()
                                            ->title('Berhasil')
                                            ->body("Room & rate diperbarui. Status kamar juga disinkronkan.")
                                            ->success()
                                            ->send();

                                        // Refresh form agar nilai baru tampil
                                        if (method_exists($livewire, 'refreshForm')) {
                                            $livewire->refreshForm();
                                        }
                                    }),

                                Action::make('check_in_now')
                                    ->label('Check In Guest')
                                    ->icon('heroicon-o-key')
                                    ->color('danger')
                                    ->button()
                                    ->visible(fn(\App\Models\ReservationGuest $record) => blank($record->actual_checkin))
                                    // tidak ada schema/form -> tidak muncul modal
                                    ->action(function (\App\Models\ReservationGuest $record, \Livewire\Component $livewire) {
                                        DB::transaction(function () use ($record) {
                                            $reservation = $record->reservation()->lockForUpdate()->first();
                                            if (! $reservation) {
                                                throw new \RuntimeException('Reservation not found for this guest.');
                                            }

                                            if ($record->room_id) {
                                                Room::whereKey($record->room_id)->update([
                                                    'status'            => Room::ST_OCC,
                                                    'status_changed_at' => now(),
                                                ]);
                                            }

                                            if (blank($record->actual_checkin)) {
                                                $now = now();

                                                ReservationGuest::whereKey($record->getKey())
                                                    ->update(['actual_checkin' => $now]);

                                                if (blank($reservation->checkin_date)) {
                                                    $reservation->forceFill(['checkin_date' => $now])->saveQuietly();
                                                }

                                                Notification::make()
                                                    ->title('Checked-in')
                                                    ->body('ReservationGuest #' . $record->id . ' pada ' . $now->format('d/m/Y H:i'))
                                                    ->success()
                                                    ->send();
                                            } else {
                                                Notification::make()
                                                    ->title('Sudah check-in')
                                                    ->body('ReservationGuest #' . $record->id . ' pada ' . \Carbon\Carbon::parse($record->actual_checkin)->format('d/m/Y H:i'))
                                                    ->info()
                                                    ->send();
                                            }
                                        });

                                        // ⇩⇩ Tampilkan halaman print dalam MODE SINGLE
                                        $printUrl = route('reservation-guests.print', [
                                            'guest' => $record->getKey(),
                                            'mode'  => 'single',
                                        ]);
                                        $livewire->js("window.open('{$printUrl}', '_blank', 'noopener,noreferrer');");

                                        // === Redirect logic sesuai permintaan ===
                                        $reservationId = (int) ($record->reservation_id ?? 0);

                                        // Cek apakah MASIH ADA RG dalam reservation ini yang belum check-in (null/0/'0000-00-00 00:00:00')
                                        $stillHasNotCheckedIn = \App\Models\ReservationGuest::query()
                                            ->where('reservation_id', (int) $record->reservation_id)
                                            ->whereNull('actual_checkin')
                                            ->exists();

                                        if ($stillHasNotCheckedIn) {
                                            // Arahkan ke halaman edit reservation (contoh URL yang Anda minta)
                                            $url = "https://hotel-bome.test/admin/reservations/{$reservationId}/edit";
                                        } else {
                                            // Jika semua RG sudah punya actual_checkin → ke index Reservation Guests
                                            $url = "https://hotel-bome.test/admin/reservation-guests";
                                            // Atau gunakan resource route:
                                            // $url = \App\Filament\Resources\ReservationGuests\ReservationGuestResource::getUrl('index');
                                        }

                                        if (method_exists($livewire, 'redirect')) {
                                            $livewire->redirect($url, navigate: true);
                                        } else {
                                            $livewire->js("window.location.href = '{$url}';");
                                        }
                                    }),

                                Action::make('check_in_all')
                                    ->label('Check In All Guests')
                                    ->icon('heroicon-o-users')
                                    ->color('success')
                                    ->button()
                                    ->visible(function (\App\Models\ReservationGuest $record): bool {
                                        $res = $record->reservation;
                                        if (! $res) return false;

                                        // Tampilkan tombol hanya jika jumlah RG > 1 dan
                                        // yang BELUM check-in minimal 2
                                        $total = $res->reservationGuests()->count();
                                        $unchecked = $res->reservationGuests()->whereNull('actual_checkin')->count();

                                        return $total > 1 && $unchecked > 1;
                                    })
                                    // ⛔️ Tidak ada confirmation dan tidak ada modal/schema
                                    ->action(function (\App\Models\ReservationGuest $record, \Livewire\Component $livewire) {

                                        $res = $record->reservation;
                                        if (! $res) {
                                            \Filament\Notifications\Notification::make()
                                                ->title('Reservation tidak ditemukan.')
                                                ->warning()
                                                ->send();
                                            return;
                                        }

                                        \Illuminate\Support\Facades\DB::transaction(function () use ($res) {
                                            $now = now();

                                            // Target: semua RG yang BELUM check-in
                                            $targets = $res->reservationGuests()
                                                ->whereNull('actual_checkin')
                                                ->get(['id', 'room_id']);

                                            // Jika target kurang dari 2, tidak perlu proses massal
                                            if ($targets->count() < 2) {
                                                \Filament\Notifications\Notification::make()
                                                    ->title('Tidak ada tamu yang perlu check-in massal.')
                                                    ->info()
                                                    ->send();
                                                return;
                                            }

                                            // 1) Set status kamar OCC untuk RG target yang punya room_id
                                            $roomIds = $targets->pluck('room_id')->filter()->unique()->values()->all();
                                            if (! empty($roomIds)) {
                                                \App\Models\Room::whereIn('id', $roomIds)->update([
                                                    'status'            => \App\Models\Room::ST_OCC,
                                                    'status_changed_at' => $now,
                                                ]);
                                            }

                                            // 2) Set actual_checkin (langsung via query -> tanpa events/validator)
                                            $ids = $targets->pluck('id')->all();
                                            \App\Models\ReservationGuest::whereIn('id', $ids)->update([
                                                'actual_checkin' => $now,
                                            ]);

                                            // 3) Pastikan header checkin_date terisi
                                            if (blank($res->checkin_date)) {
                                                $res->forceFill(['checkin_date' => $now])->saveQuietly();
                                            }

                                            \Filament\Notifications\Notification::make()
                                                ->title('Check-in massal selesai')
                                                ->body($targets->count() . ' guest berhasil di-check-in.')
                                                ->success()
                                                ->send();
                                        });

                                        // ➜ Buka print mode=ALL (tampilkan semua RG di reservation)
                                        $printUrl = route('reservation-guests.print', [
                                            'guest' => $record->getKey(),
                                            'mode'  => 'all',
                                        ]);
                                        $livewire->js("window.open('{$printUrl}', '_blank', 'noopener,noreferrer');");

                                        // ➜ Kembali ke index
                                        $url = \App\Filament\Resources\ReservationGuests\ReservationGuestResource::getUrl('index');
                                        if (method_exists($livewire, 'redirect')) {
                                            $livewire->redirect($url, navigate: true);
                                        } else {
                                            $livewire->js("window.location.href = '{$url}';");
                                        }
                                    }),
                            ])
                                ->alignment('center')                 // center semua tombol di grup
                                ->extraAttributes(['class' => 'gap-3 flex-wrap']) // spasi antar tombol & wrap di layar kecil
                                ->columnSpanFull(),
                        ]),
                ])
                ->columnSpanFull(),
        ]);
    }
}
