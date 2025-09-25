<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReservationGuests\Schemas;

use App\Models\Room;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use App\Models\ReservationGuest;
use Illuminate\Support\Facades\DB;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
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
                                    ->action(function (ReservationGuest $record, $livewire) {
                                        DB::transaction(function () use ($record) {
                                            if ($record->room_id) {
                                                Room::whereKey($record->room_id)->update([
                                                    'status'            => Room::ST_OCC,
                                                    'status_changed_at' => now(),
                                                ]);
                                            }

                                            if (blank($record->actual_checkin)) {
                                                $now = now();
                                                $record->forceFill(['actual_checkin' => $now])->save();

                                                // set checkin_date header bila kosong
                                                if ($record->reservation && blank($record->reservation->checkin_date)) {
                                                    $record->reservation->forceFill(['checkin_date' => $now])->save();
                                                }

                                                Notification::make()
                                                    ->title('Checked-in')
                                                    ->body('ReservationGuest #' . $record->id . ' pada ' . $now->format('d/m/Y H:i'))
                                                    ->success()
                                                    ->send();
                                            } else {
                                                Notification::make()
                                                    ->title('Sudah check-in')
                                                    ->body('ReservationGuest #' . $record->id . ' pada ' . Carbon::parse($record->actual_checkin)->format('d/m/Y H:i'))
                                                    ->info()
                                                    ->send();
                                            }
                                        });

                                        // ➜ redirect ke daftar Check-In
                                        $url = ReservationGuestResource::getUrl('index');
                                        if (method_exists($livewire, 'redirect')) {
                                            // SPA navigate (Filament v3)
                                            $livewire->redirect($url, navigate: true);
                                        } else {
                                            // fallback universal
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
                                        if (! $res) {
                                            return false;
                                        }

                                        return $res->reservationGuests()->count() > 1
                                            && $res->reservationGuests()->whereNull('actual_checkin')->exists();
                                    })
                                    ->requiresConfirmation()
                                    ->action(function (ReservationGuest $record, $livewire) {
                                        $res = $record->reservation;

                                        if (! $res) {
                                            Notification::make()
                                                ->title('Reservation tidak ditemukan.')
                                                ->warning()
                                                ->send();
                                            return;
                                        }

                                        DB::transaction(function () use ($res) {
                                            $now = now();

                                            // Update status semua kamar yang ikut check-in
                                            $res->reservationGuests()
                                                ->whereNull('actual_checkin')
                                                ->whereNotNull('room_id')
                                                ->pluck('room_id')
                                                ->unique()
                                                ->each(function ($roomId) {
                                                    Room::whereKey($roomId)->update([
                                                        'status'            => Room::ST_OCC,
                                                        'status_changed_at' => now(),
                                                    ]);
                                                });

                                            // Set actual_checkin massal
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

                                        // ➜ redirect ke daftar Check-In
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
