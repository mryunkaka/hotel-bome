<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReservationGuests\Schemas;

use Filament\Actions\Action;
use Filament\Schemas\Schema;
use App\Models\ReservationGuest;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
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
                            Action::make('check_in_now')
                                ->label('Check In')
                                ->icon('heroicon-o-key')
                                ->color('success')
                                ->visible(fn(ReservationGuest $record) => blank($record->actual_checkin))
                                ->requiresConfirmation()
                                ->action(function (ReservationGuest $record, $livewire) {
                                    if (blank($record->actual_checkin)) {
                                        $record->forceFill(['actual_checkin' => now()])->save();

                                        Notification::make()
                                            ->title('Checked-in')
                                            ->body('ReservationGuest #' . $record->id . ' pada ' . now()->format('d/m/Y H:i'))
                                            ->success()
                                            ->send();
                                    } else {
                                        Notification::make()
                                            ->title('Sudah check-in')
                                            ->body('ReservationGuest #' . $record->id . ' pada ' . \Illuminate\Support\Carbon::parse($record->actual_checkin)->format('d/m/Y H:i'))
                                            ->info()
                                            ->send();
                                    }

                                    // (opsional) buka print di tab baru
                                    // $livewire->js('window.open("'.route('reservation-guests.print',['guest'=>$record->id]).'", "_blank", "noopener")');
                                }),
                        ]),
                ])->columnSpanFull(),
        ]);
    }
}
