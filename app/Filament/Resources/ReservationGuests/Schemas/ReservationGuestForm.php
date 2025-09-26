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
                                    ->visible(fn(ReservationGuest $record) => blank($record->actual_checkin))
                                    ->requiresConfirmation()
                                    // ===== Modal (gunakan ->form & Placeholder) =====
                                    ->schema(function (ReservationGuest $record) {
                                        $reservation = $record->reservation;
                                        $depositRoom = (int) ($reservation?->deposit_room ?? 0);
                                        $depositCard = (int) ($reservation?->deposit_card ?? 0);

                                        return [
                                            Placeholder::make('__dp_info')
                                                ->label('Current Deposit')
                                                ->content(
                                                    'Room Deposit (DP): Rp ' . number_format($depositRoom, 0, ',', '.') .
                                                        ' | Deposit Card: Rp ' . number_format($depositCard, 0, ',', '.')
                                                ),

                                            Radio::make('room_deposit_action')
                                                ->label('Room Deposit Action')
                                                ->options([
                                                    'convert'  => 'Masukkan DP menjadi Deposit Card (disarankan)',
                                                    'withdraw' => 'Tarik/Refund DP (jangan masukkan menjadi Deposit Card)',
                                                ])
                                                ->default('convert')
                                                ->inline()
                                                ->live(),

                                            TextInput::make('deposit_card_input')
                                                ->label('Deposit Card (saat Check-in)')
                                                ->helperText('Isi nominal jaminan kamar jika DP ditarik/refund.')
                                                ->numeric()
                                                ->minValue(0)
                                                ->mask(RawJs::make('$money($input)'))
                                                ->stripCharacters(',')
                                                ->default($depositCard)
                                                ->visible(fn(Get $get) => $get('room_deposit_action') === 'withdraw'),
                                        ];
                                    })
                                    // ===== Aksi =====
                                    ->action(function (ReservationGuest $record, $livewire, array $data = []) {
                                        \Illuminate\Support\Facades\DB::transaction(function () use ($record, $data) {
                                            $reservation = $record->reservation()->lockForUpdate()->first(); // kunci baris utk konsistensi
                                            if (!$reservation) {
                                                throw new \RuntimeException('Reservation not found for this guest.');
                                            }

                                            // ====== Kelola status kamar ======
                                            if ($record->room_id) {
                                                Room::whereKey($record->room_id)->update([
                                                    'status'            => Room::ST_OCC,
                                                    'status_changed_at' => now(),
                                                ]);
                                            }

                                            // ====== Kelola deposit berdasarkan pilihan modal ======
                                            $action     = $data['room_deposit_action'] ?? 'convert';
                                            $dpRoom     = (int) ($reservation->deposit_room ?? 0);
                                            $dpCardNow  = (int) ($reservation->deposit_card ?? 0);

                                            if ($action === 'convert') {
                                                // Konversi DP Reservasi -> Deposit Card
                                                $reservation->forceFill([
                                                    'deposit_card' => $dpCardNow + $dpRoom,
                                                    'deposit_room' => 0,
                                                ])->save();
                                            } else { // withdraw
                                                $newCard = (int) ($data['deposit_card_input'] ?? 0);
                                                // DP ditarik/refund; deposit_room jadi 0, kartu diisi dari input
                                                $reservation->forceFill([
                                                    'deposit_card' => $newCard,
                                                    'deposit_room' => 0,
                                                ])->save();
                                            }

                                            // ====== Set check-in time ======
                                            if (blank($record->actual_checkin)) {
                                                $now = now();
                                                $record->forceFill(['actual_checkin' => $now])->save();

                                                // set checkin_date header bila kosong
                                                if ($reservation && blank($reservation->checkin_date)) {
                                                    $reservation->forceFill(['checkin_date' => $now])->save();
                                                }

                                                \Filament\Notifications\Notification::make()
                                                    ->title('Checked-in')
                                                    ->body('ReservationGuest #' . $record->id . ' pada ' . $now->format('d/m/Y H:i'))
                                                    ->success()
                                                    ->send();
                                            } else {
                                                \Filament\Notifications\Notification::make()
                                                    ->title('Sudah check-in')
                                                    ->body('ReservationGuest #' . $record->id . ' pada ' . \Carbon\Carbon::parse($record->actual_checkin)->format('d/m/Y H:i'))
                                                    ->info()
                                                    ->send();
                                            }
                                        });

                                        // ➜ buka tab baru ke halaman print tamu terpilih
                                        $printUrl = route('reservation-guests.print', ['guest' => $record->getKey()]);
                                        $livewire->js("window.open('{$printUrl}', '_blank', 'noopener,noreferrer');");

                                        // ➜ redirect ke daftar Check-In (tetap)
                                        $url = ReservationGuestResource::getUrl('index');
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
                                    ->visible(function (ReservationGuest $record): bool {
                                        $res = $record->reservation;
                                        if (!$res) return false;
                                        return $res->reservationGuests()->count() > 1
                                            && $res->reservationGuests()->whereNull('actual_checkin')->exists();
                                    })
                                    ->requiresConfirmation()
                                    // ===== Modal (pakai ->form & Placeholder) =====
                                    ->schema(function (ReservationGuest $record) {
                                        $res = $record->reservation;
                                        $depositRoom = (int) ($res?->deposit_room ?? 0);
                                        $depositCard = (int) ($res?->deposit_card ?? 0);

                                        return [
                                            Placeholder::make('__dp_info')
                                                ->label('Current Deposit')
                                                ->content(
                                                    'Room Deposit (DP): Rp ' . number_format($depositRoom, 0, ',', '.') .
                                                        ' | Deposit Card: Rp ' . number_format($depositCard, 0, ',', '.')
                                                )
                                                ->columnSpanFull(),

                                            Radio::make('room_deposit_action')
                                                ->label('Room Deposit Action')
                                                ->options([
                                                    'convert'  => 'Masukkan DP menjadi Deposit Card (disarankan)',
                                                    'withdraw' => 'Tarik/Refund DP (jangan masukkan menjadi Deposit Card)',
                                                ])
                                                ->default('convert')
                                                ->inline()
                                                ->live(),

                                            TextInput::make('deposit_card_input')
                                                ->label('Deposit Card (saat Check-in)')
                                                ->helperText('Isi nominal jaminan kamar jika DP ditarik/refund.')
                                                ->numeric()
                                                ->minValue(0)
                                                ->mask(RawJs::make('$money($input)'))
                                                ->stripCharacters(',')
                                                ->default($depositCard)
                                                ->visible(fn(Get $get) => $get('room_deposit_action') === 'withdraw'),
                                        ];
                                    })
                                    // ===== Aksi check-in massal =====
                                    ->action(function (ReservationGuest $record, $livewire, array $data = []) {
                                        $res = $record->reservation;

                                        if (! $res) {
                                            Notification::make()->title('Reservation tidak ditemukan.')->warning()->send();
                                            return;
                                        }

                                        DB::transaction(function () use ($res, $data) {
                                            $now = now();

                                            // Kunci reservation untuk konsistensi deposit
                                            $res->lockForUpdate();

                                            // ====== Kelola deposit berdasarkan pilihan modal (sekali per reservation) ======
                                            $action    = $data['room_deposit_action'] ?? 'convert';
                                            $dpRoom    = (int) ($res->deposit_room ?? 0);
                                            $dpCardNow = (int) ($res->deposit_card ?? 0);

                                            if ($action === 'convert') {
                                                // Konversi DP Reservasi -> Deposit Card
                                                $res->forceFill([
                                                    'deposit_card' => $dpCardNow + $dpRoom,
                                                    'deposit_room' => 0,
                                                ])->save();
                                            } else { // withdraw
                                                $newCard = (int) ($data['deposit_card_input'] ?? 0);
                                                // DP ditarik/refund; deposit_room jadi 0, deposit_card dari input
                                                $res->forceFill([
                                                    'deposit_card' => $newCard,
                                                    'deposit_room' => 0,
                                                ])->save();
                                            }

                                            // ====== Update status kamar (yang akan di-check-in) ======
                                            $roomIds = $res->reservationGuests()
                                                ->whereNull('actual_checkin')
                                                ->whereNotNull('room_id')
                                                ->pluck('room_id')
                                                ->unique()
                                                ->all();

                                            if (!empty($roomIds)) {
                                                Room::whereIn('id', $roomIds)->update([
                                                    'status'            => Room::ST_OCC,
                                                    'status_changed_at' => $now,
                                                ]);
                                            }

                                            // ====== Set actual_checkin massal ======
                                            $affected = $res->reservationGuests()
                                                ->whereNull('actual_checkin')
                                                ->update(['actual_checkin' => $now]);

                                            if ($affected > 0 && blank($res->checkin_date)) {
                                                $res->forceFill(['checkin_date' => $now])->save();
                                            }

                                            Notification::make()
                                                ->title('Check-in massal selesai')
                                                ->body($affected . ' guest berhasil di-check-in.')
                                                ->success()
                                                ->send();
                                        });
                                        // ➜ buka tab baru ke halaman print tamu terpilih
                                        $printUrl = route('reservation-guests.print', ['guest' => $record->getKey()]);
                                        $livewire->js("window.open('{$printUrl}', '_blank', 'noopener,noreferrer');");

                                        // ➜ redirect ke daftar Check-In (tetap)
                                        $url = ReservationGuestResource::getUrl('index');
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
