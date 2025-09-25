<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReservationGuestCheckOuts\Schemas;

use App\Models\TaxSetting;
use Filament\Support\RawJs;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\ReservationGuest;
use App\Support\ReservationMath;
use Illuminate\Support\Facades\DB;
use Filament\Forms\Components\Radio;
use Illuminate\Support\Facades\Auth;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Actions as SchemaActions;
use App\Filament\Resources\ReservationGuestCheckOuts\ReservationGuestCheckOutResource;
use Livewire\Component as LivewireComponent;

final class ReservationGuestCheckOutForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Guest Bill')
                ->collapsible()
                ->components([
                    ViewField::make('checkout_preview')
                        ->view('filament.forms.components.checkout-preview', [
                            'showTitle' => false,   // ⬅️ matikan judul di dalam view
                        ]),
                ])->columnSpanFull(),
            Section::make('Actions')
                ->schema([
                    Grid::make(15)->schema([

                        // ===================== Guest Folio =====================
                        SchemaActions::make([
                            Action::make('folio_pdf')
                                ->label('Guest Folio')
                                ->icon('heroicon-o-clipboard-document-list')
                                ->disabled(fn(\App\Models\ReservationGuest $record) => blank($record->actual_checkout))
                                ->tooltip(fn(\App\Models\ReservationGuest $record) => blank($record->actual_checkout)
                                    ? 'Only available after guest has checked out.' : null)
                                ->url(fn(\App\Models\ReservationGuest $record) => route('reservation-guests.folio', $record))
                                ->openUrlInNewTab()
                                ->color('info')        // UI: warna biru-informasi
                                ->button()             // UI: gaya tombol
                                ->outlined(),          // UI: outline biar beda aksen
                        ])->columns(1)->columnSpan(3)
                            ->alignment('center'),       // UI: posisikan tombol di tengah

                        // ===================== Room Post & Correction =====================
                        SchemaActions::make([
                            Action::make('post_corr')
                                ->label('P & C')
                                ->icon('heroicon-o-adjustments-horizontal')
                                ->disabled(function (\App\Models\ReservationGuest $record): bool {
                                    if (filled($record->actual_checkout)) {
                                        return true;
                                    }
                                    return \App\Models\Payment::where('reservation_guest_id', $record->id)->exists();
                                })
                                ->tooltip(function (\App\Models\ReservationGuest $record) {
                                    if (filled($record->actual_checkout)) {
                                        return 'Guest already checked out.';
                                    }
                                    if (\App\Models\Payment::where('reservation_guest_id', $record->id)->exists()) {
                                        return 'Payment already recorded — posting/correction is locked.';
                                    }
                                    return null;
                                })
                                ->schema([
                                    \Filament\Forms\Components\TextInput::make('adjustment_rp')
                                        ->label('Adjustment (±Rp) → ditempel ke kolom Service')
                                        ->numeric()
                                        ->mask(\Filament\Support\RawJs::make('$money($input)'))
                                        ->stripCharacters(',')
                                        ->required(),
                                    \Filament\Forms\Components\Textarea::make('reason')->label('Reason')->rows(2),
                                ])
                                ->action(function (array $data, \App\Models\ReservationGuest $record): void {
                                    if (filled($record->actual_checkout)) {
                                        \Filament\Notifications\Notification::make()
                                            ->title('Guest sudah checkout — tidak bisa melakukan Room Post & Correction.')
                                            ->warning()
                                            ->send();
                                        return;
                                    }
                                    if (\App\Models\Payment::where('reservation_guest_id', $record->id)->exists()) {
                                        \Filament\Notifications\Notification::make()
                                            ->title('Payment untuk guest ini sudah ada — Room Post & Correction dikunci.')
                                            ->warning()
                                            ->send();
                                        return;
                                    }
                                    $record->service = (int) ($record->service ?? 0) + (int) $data['adjustment_rp'];
                                    $record->save();

                                    \Filament\Notifications\Notification::make()
                                        ->title('Service adjusted.')
                                        ->success()
                                        ->send();
                                })
                                ->color('gray')   // UI: abu-abu agar beda dari tombol lain
                                ->button()
                                ->outlined(),
                        ])->columns(1)->columnSpan(3)
                            ->alignment('center'),

                        // ===================== Print Bill =====================
                        SchemaActions::make([
                            Action::make('print_bill')
                                ->label('Print Bill')
                                ->icon('heroicon-o-printer')
                                ->disabled(fn(\App\Models\ReservationGuest $record) => blank($record->actual_checkout))
                                ->tooltip(fn(\App\Models\ReservationGuest $record) => blank($record->actual_checkout)
                                    ? 'Only available after guest has checked out.' : null)
                                ->url(fn(\App\Models\ReservationGuest $record) => route('reservation-guests.bill', $record))
                                ->openUrlInNewTab()
                                ->color('primary')  // UI: biru-utama (beda dari info)
                                ->button(),
                        ])->columns(1)->columnSpan(3)
                            ->alignment('center'),

                        // ===================== Split Bill =====================
                        SchemaActions::make([
                            Action::make('split_bill')
                                ->label('Split Bill')
                                ->icon('heroicon-o-scissors')
                                ->color('warning') // UI: kuning
                                ->button()
                                ->disabled(
                                    fn(\App\Models\ReservationGuest $record): bool =>
                                    \App\Models\Payment::where('reservation_guest_id', $record->id)->exists()
                                )
                                ->tooltip(function (\App\Models\ReservationGuest $record) {
                                    if (\App\Models\Payment::where('reservation_guest_id', $record->id)->exists()) {
                                        return 'Payment already recorded — Split Bill is locked.';
                                    }
                                    return null;
                                })
                                // (form & action asli tetap — tidak diubah)
                                ->schema(function (\App\Models\ReservationGuest $record) {
                                    return [
                                        \Filament\Forms\Components\TextInput::make('reservation_no')
                                            ->label('New Reservation No (optional)')
                                            ->maxLength(64)
                                            ->placeholder('Auto-generate from model if empty'),
                                        \Filament\Forms\Components\Radio::make('move_tax')
                                            ->label('Move Tax setting (id_tax) to new reservation?')
                                            ->options(['YES' => 'Yes', 'NO' => 'No'])
                                            ->default('YES')->inline()->required(),
                                        \Filament\Forms\Components\Radio::make('move_deposit')
                                            ->label('Move reservation deposit?')
                                            ->options(['NONE' => 'No', 'ALL' => 'All', 'PARTIAL' => 'Partial'])
                                            ->default('NONE')->inline()->required(),
                                        \Filament\Forms\Components\TextInput::make('deposit_amount')
                                            ->label('Deposit to move (IDR)')
                                            ->numeric()->minValue(0)
                                            ->mask(\Filament\Support\RawJs::make('$money($input)'))
                                            ->stripCharacters(',')
                                            ->visible(fn(\Filament\Schemas\Components\Utilities\Get $get) => $get('move_deposit') === 'PARTIAL'),
                                        \Filament\Forms\Components\Radio::make('copy_remarks')
                                            ->label('Copy remarks to new reservation?')
                                            ->options(['YES' => 'Yes', 'NO' => 'No'])
                                            ->default('YES')->inline()->required(),
                                    ];
                                })
                                ->action(function (array $data, \App\Models\ReservationGuest $record) {
                                    // (isi action split bill Anda yang ada — tidak diubah)
                                    // ...
                                }),
                        ])->columns(1)->columnSpan(3)
                            ->alignment('center'),

                        // ===================== Payment & Check Out =====================
                        SchemaActions::make([
                            Action::make('pay_and_checkout_adv')
                                ->label('C/O')
                                ->icon('heroicon-o-credit-card')
                                ->color('success')   // UI: hijau untuk aksi utama
                                ->button()
                                ->disabled(function (\App\Models\ReservationGuest $record): bool {
                                    if (filled($record->actual_checkout)) {
                                        return true;
                                    }
                                    return \App\Models\Payment::where('reservation_guest_id', $record->id)->exists();
                                })
                                ->schema([
                                    Hidden::make('guest_id'),
                                    TextInput::make('amount')
                                        ->label('Amount (IDR)')
                                        ->numeric()
                                        ->minValue(0)
                                        ->mask(RawJs::make('$money($input)'))
                                        ->stripCharacters(',')
                                        ->required(),
                                    Select::make('method')
                                        ->label('Method')
                                        ->options([
                                            'CASH'     => 'Cash',
                                            'CARD'     => 'Card',
                                            'TRANSFER' => 'Transfer',
                                            'OTHER'    => 'Other',
                                        ])
                                        ->required(),
                                    Textarea::make('note')->label('Note')->rows(2),
                                ])
                                ->mutateDataUsing(function (array $data, \App\Models\ReservationGuest $record) {
                                    if (!isset($data['amount']) || ! $data['amount']) {
                                        $res = $record->reservation;
                                        $sumDue = 0;
                                        if ($res) {
                                            $openGuests = $res->reservationGuests()->whereNull('actual_checkout')->get();
                                            foreach ($openGuests as $g) {
                                                $calc = \App\Filament\Resources\ReservationGuestCheckOuts\Schemas\ReservationGuestCheckOutForm::buildBreakdown($g);
                                                $due  = max(0, (int) $calc['grand_total'] - (int) $calc['deposit']);
                                                $sumDue += $due;
                                            }
                                        }
                                        $data['amount'] = (int) $sumDue;
                                    }
                                    $data['guest_id'] = (string) $record->id;
                                    return $data;
                                })
                                ->action(function (array $data, \App\Models\ReservationGuest $record, \Livewire\Component $livewire) {
                                    DB::transaction(function () use ($data, $record) {
                                        if (filled($record->actual_checkout)) {
                                            \Filament\Notifications\Notification::make()
                                                ->title('Guest ini sudah checkout.')
                                                ->warning()
                                                ->send();
                                            return;
                                        }

                                        $res = $record->reservation;
                                        if (! $res) {
                                            \Filament\Notifications\Notification::make()
                                                ->title('Reservation tidak ditemukan.')
                                                ->warning()
                                                ->send();
                                            return;
                                        }

                                        \App\Models\Payment::create([
                                            'hotel_id'             => $res->hotel_id,
                                            'reservation_id'       => $res->id,
                                            'reservation_guest_id' => null,
                                            'amount'               => (int) $data['amount'],
                                            'method'               => (string) $data['method'],
                                            'payment_date'         => now(),
                                            'note'                 => (string) ($data['note'] ?? ''),
                                            'created_by'           => \Illuminate\Support\Facades\Auth::id(),
                                        ]);

                                        $now = now();
                                        $openGuests = $res->reservationGuests()->whereNull('actual_checkout')->get();
                                        foreach ($openGuests as $g) {
                                            if (\App\Models\Payment::where('reservation_guest_id', $g->id)->exists()) {
                                                continue;
                                            }
                                            $g->forceFill([
                                                'actual_checkout' => $now,
                                                'bill_closed_at'  => $now,
                                            ])->save();

                                            if ($g->room_id) {
                                                \App\Models\Room::whereKey($g->room_id)->update([
                                                    'status'            => \App\Models\Room::ST_VD,
                                                    'status_changed_at' => $now,
                                                ]);
                                            }
                                        }

                                        if ($res->reservationGuests()->whereNull('actual_checkout')->count() === 0 && ! $res->checkout_date) {
                                            $res->checkout_date = $now;
                                            $res->save();
                                        }

                                        \Filament\Notifications\Notification::make()
                                            ->title('Payment tersimpan & semua guest sudah checkout.')
                                            ->success()
                                            ->send();
                                    });

                                    $printUrl = route('reservation-guests.bill', $record);
                                    if (method_exists($livewire, 'dispatch')) {
                                        $livewire->dispatch('close-modal');
                                    }
                                    $livewire->js("window.open('{$printUrl}', '_blank', 'noopener,noreferrer');");
                                    return;
                                }),
                        ])->columns(1)->columnSpan(3)
                            ->alignment('center'),

                    ]),
                ])
                ->columnSpanFull(),

        ]);
    }


    /**
     * Hitung detail breakdown untuk modal folio & print
     */
    private static function buildBreakdown(ReservationGuest $rg): array
    {
        $calc = ReservationMath::guestBill($rg, ['tz' => 'Asia/Makassar']);

        $tz    = 'Asia/Makassar';
        $start = $rg->actual_checkin ?? $rg->expected_checkin;
        // tampilkan "sekarang" jika belum checkout
        $end   = $rg->actual_checkout ?: \Illuminate\Support\Carbon::now($tz);

        return [
            'rg'                => $rg,
            'nights'            => $calc['nights'],
            'rate_after_disc'   => (int) $calc['room_after_disc'],
            'service'           => (int) $calc['service'],
            'extra_bed'         => (int) $calc['extra'],
            'late_penalty'      => (int) $calc['penalty'],
            'tax_percent'       => (float) $calc['tax_percent'],
            'tax_rp'            => (int) $calc['tax_rp'],
            'grand_total'       => (int) $calc['grand'],
            'deposit'           => (int) $calc['deposit'],

            'arrive_at'         => $start ? \Illuminate\Support\Carbon::parse($start)->format('d/m/Y H:i') : '-',
            'depart_at'         => $end   ? \Illuminate\Support\Carbon::parse($end)->format('d/m/Y H:i')   : '-',
            'guest_name'        => $rg->guest?->name,
            'room_no'           => $rg->room?->room_no,
        ];
    }
}
