<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReservationGuests\Schemas;

use App\Models\Room;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use App\Models\ReservationGuest;
use Illuminate\Support\Facades\DB;
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
                                        return $res
                                            ? $res->reservationGuests()->whereNull('actual_checkin')->exists()
                                            : false;
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

                            // === Row 2: tombol back di kiri
                            Actions::make([
                                Action::make('back_to_reservation')
                                    ->label('Back to Reservation')
                                    ->icon('heroicon-o-arrow-uturn-left')
                                    ->color('gray')
                                    ->button()
                                    ->url(fn(\App\Models\ReservationGuest $record) => url('/admin/reservations/' . $record->reservation_id . '/edit'))
                                    ->openUrlInNewTab(false),
                            ])
                                ->alignment('start')
                                ->extraAttributes(['class' => 'gap-3'])
                                ->columnSpanFull(),
                        ]),
                ])
                ->columnSpanFull(),
        ]);
    }
}
