<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReservationGuests\Schemas;

use Filament\Actions\Action;
use Filament\Schemas\Schema;
use App\Models\ReservationGuest;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;

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
                                    ->visible(fn(\App\Models\ReservationGuest $record) => blank($record->actual_checkin))
                                    ->requiresConfirmation()
                                    ->action(function (\App\Models\ReservationGuest $record, $livewire) {
                                        if ($record->room_id) {
                                            \App\Models\Room::whereKey($record->room_id)->update([
                                                'status' => \App\Models\Room::ST_OCC,
                                                'status_changed_at' => now(),
                                            ]);
                                        }
                                        if (blank($record->actual_checkin)) {
                                            $record->forceFill(['actual_checkin' => now()])->save();
                                            \Filament\Notifications\Notification::make()
                                                ->title('Checked-in')
                                                ->body('ReservationGuest #' . $record->id . ' pada ' . now()->format('d/m/Y H:i'))
                                                ->success()
                                                ->send();
                                        } else {
                                            \Filament\Notifications\Notification::make()
                                                ->title('Sudah check-in')
                                                ->body('ReservationGuest #' . $record->id . ' pada ' . \Illuminate\Support\Carbon::parse($record->actual_checkin)->format('d/m/Y H:i'))
                                                ->info()
                                                ->send();
                                        }

                                        // ⟶ refresh halaman supaya tombol hilang
                                        $livewire->js(<<<'JS'
                                            setTimeout(() => { window.location.reload(); }, 500);
                                        JS);
                                    }),

                                Action::make('check_in_all')
                                    ->label('Check In All Guests')
                                    ->icon('heroicon-o-users')
                                    ->color('success')
                                    ->button()
                                    ->visible(function (\App\Models\ReservationGuest $record): bool {
                                        $res = $record->reservation;
                                        return $res
                                            ? $res->reservationGuests()->whereNull('actual_checkin')->exists()
                                            : false;
                                    })
                                    ->requiresConfirmation()
                                    ->action(function (\App\Models\ReservationGuest $record, $livewire) {
                                        $res = $record->reservation;
                                        if (! $res) {
                                            \Filament\Notifications\Notification::make()
                                                ->title('Reservation tidak ditemukan.')
                                                ->warning()
                                                ->send();
                                            return;
                                        }

                                        if ($record->room_id) {
                                            \App\Models\Room::whereKey($record->room_id)->update([
                                                'status' => \App\Models\Room::ST_OCC,
                                                'status_changed_at' => now(),
                                            ]);
                                        }

                                        $now = now();
                                        $affected = \App\Models\ReservationGuest::query()
                                            ->where('reservation_id', $res->id)
                                            ->whereNull('actual_checkin')
                                            ->update(['actual_checkin' => $now]);

                                        if ($affected > 0 && blank($res->checkin_date)) {
                                            $res->checkin_date = $now;
                                            $res->save();
                                        }

                                        \Filament\Notifications\Notification::make()
                                            ->title('Check-in massal selesai')
                                            ->body($affected . ' guest berhasil di-check-in.')
                                            ->success()
                                            ->send();

                                        // ⟶ refresh halaman supaya tombol hilang
                                        $livewire->js(<<<'JS'
                                            setTimeout(() => { window.location.reload(); }, 700);
                                        JS);
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
