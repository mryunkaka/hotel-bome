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
use Filament\Forms\Components\Radio;
use Illuminate\Support\Facades\Auth;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Actions as SchemaActions;

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
                    Grid::make(12)->schema([
                        // Guest Folio (preview)
                        SchemaActions::make([
                            Action::make('folio_pdf')
                                ->label('Guest Folio')
                                ->icon('heroicon-o-clipboard-document-list')
                                ->disabled(fn(\App\Models\ReservationGuest $record) => blank($record->actual_checkout))
                                ->tooltip(fn(\App\Models\ReservationGuest $record) => blank($record->actual_checkout)
                                    ? 'Only available after guest has checked out.' : null)
                                ->url(fn(\App\Models\ReservationGuest $record) => route('reservation-guests.folio', $record))
                                ->openUrlInNewTab(),
                        ])->columns(1)->columnSpan(3),

                        // Room Post & Correction
                        SchemaActions::make([
                            Action::make('post_corr')
                                ->label('Room Post & Corr')
                                ->icon('heroicon-o-adjustments-horizontal')
                                ->form([
                                    TextInput::make('adjustment_rp')
                                        ->label('Adjustment (±Rp) → ditempel ke kolom Service')
                                        ->numeric()
                                        ->required(),
                                    Textarea::make('reason')->label('Reason')->rows(2),
                                ])
                                ->action(function (array $data, ReservationGuest $record): void {
                                    $record->service = (int)($record->service ?? 0) + (int)$data['adjustment_rp'];
                                    $record->save();

                                    \Filament\Notifications\Notification::make()
                                        ->title('Service adjusted.')
                                        ->success()
                                        ->send();
                                }),
                        ])->columns(1)->columnSpan(3),

                        // Print Bill (PDF)
                        SchemaActions::make([
                            Action::make('print_bill')
                                ->label('Print Bill')
                                ->icon('heroicon-o-printer')
                                // disabled sampai tamu sudah checkout
                                ->disabled(fn(ReservationGuest $record) => blank($record->actual_checkout))
                                ->tooltip(fn(ReservationGuest $record) => blank($record->actual_checkout)
                                    ? 'Only available after guest has checked out.' : null)
                                ->action(function (ReservationGuest $record) {
                                    // hard guard kalau ada yang memaksa trigger
                                    if (blank($record->actual_checkout)) {
                                        \Filament\Notifications\Notification::make()
                                            ->title('Guest belum checkout.')
                                            ->danger()
                                            ->send();
                                        return;
                                    }

                                    $payload = self::buildBreakdown($record);
                                    $pdf = Pdf::loadView('prints.reservation_guests.bill', $payload);

                                    return response()->streamDownload(
                                        static fn() => print($pdf->output()),
                                        'Bill-' . $record->id . '.pdf'
                                    );
                                }),
                        ])->columns(1)->columnSpan(3),

                        // Payment & Check Out
                        SchemaActions::make([
                            Action::make('pay_and_checkout_adv')
                                ->label('Payment & C/O')
                                ->icon('heroicon-o-credit-card')
                                ->form(function (\App\Models\ReservationGuest $record) {
                                    $res = $record->reservation;
                                    $openGuests = $res
                                        ? $res->reservationGuests()->whereNull('actual_checkout')->with('guest:id,name')->get()
                                        : collect([$record])->whereNull('actual_checkout');

                                    // auto defaults
                                    $autoMode = $openGuests->count() <= 1 ? 'guest' : null;
                                    $autoGuestId = $openGuests->count() === 1 ? (string)$openGuests->first()->id : null;

                                    return [
                                        Radio::make('checkout_mode')
                                            ->label('Checkout Mode')
                                            ->options([
                                                'guest' => 'Checkout selected guest',
                                                'reservation' => 'Checkout whole reservation',
                                            ])
                                            ->default($autoMode)
                                            ->inline()
                                            ->required(),

                                        Select::make('guest_id')
                                            ->label('Guest')
                                            ->options(
                                                $openGuests->mapWithKeys(fn($g) => [$g->id => ($g->guest?->name ?? ('Guest #' . $g->id)) . ' — Room ' . ($g->room?->room_no ?? '-')])
                                            )
                                            ->searchable()
                                            ->default($autoGuestId)
                                            ->visible(fn(Get $get) => $get('checkout_mode') === 'guest')
                                            ->required(fn(Get $get) => $get('checkout_mode') === 'guest'),

                                        TextInput::make('amount')
                                            ->label('Amount (IDR)')
                                            ->numeric()
                                            ->required(),

                                        Select::make('method')
                                            ->label('Method')
                                            ->options([
                                                'CASH' => 'Cash',
                                                'CARD' => 'Card',
                                                'TRANSFER' => 'Transfer',
                                                'OTHER' => 'Other',
                                            ])
                                            ->required(),

                                        Textarea::make('note')->label('Note')->rows(2),
                                    ];
                                })
                                ->mutateFormDataUsing(function (array $data, \App\Models\ReservationGuest $record) {
                                    // Auto default amount:
                                    // - mode guest: pakai due dari guest terpilih / auto guest
                                    // - mode reservation: sum due dari semua guest yang belum checkout,
                                    //   minus deposit reservation (sesuai kebijakanmu).
                                    $res = $record->reservation;
                                    $openGuests = $res
                                        ? $res->reservationGuests()->whereNull('actual_checkout')->get()
                                        : collect([$record])->whereNull('actual_checkout');

                                    $mode = $data['checkout_mode'] ?? ($openGuests->count() <= 1 ? 'guest' : null);
                                    $guestId = $data['guest_id'] ?? ($openGuests->count() === 1 ? (string)$openGuests->first()->id : null);

                                    $ensureDue = function (\App\Models\ReservationGuest $g): int {
                                        $calc = \App\Filament\Resources\ReservationGuestCheckOuts\Schemas\ReservationGuestCheckOutForm::buildBreakdown($g);
                                        $due  = max(0, (int)$calc['grand_total'] - (int)$calc['deposit']);
                                        return (int)$due;
                                    };

                                    if (! isset($data['amount']) || ! $data['amount']) {
                                        if ($mode === 'guest' && $guestId) {
                                            $g = $openGuests->firstWhere('id', (int)$guestId) ?? $record;
                                            $data['amount'] = $ensureDue($g);
                                        } elseif ($mode === 'reservation' && $openGuests->count()) {
                                            $sumDue = 0;
                                            foreach ($openGuests as $g) {
                                                $sumDue += $ensureDue($g);
                                            }
                                            // Catatan: deposit sudah “terpakai” di per-guest breakdown (jika kamu masukkan di situ).
                                            // Kalau deposit maumu dibebankan 1x di level reservation, sesuaikan di sini.
                                            $data['amount'] = $sumDue;
                                        }
                                    }

                                    return $data;
                                })
                                ->action(function (array $data, \App\Models\ReservationGuest $record): void {
                                    $mode = $data['checkout_mode'] ?? 'guest';

                                    $doCheckout = function (\App\Models\ReservationGuest $g, array $payData): void {
                                        // Payment
                                        \App\Models\Payment::create([
                                            'hotel_id'             => $g->hotel_id,
                                            'reservation_id'       => $g->reservation_id,
                                            'reservation_guest_id' => $g->id,
                                            'amount'               => (int) $payData['amount'],
                                            'method'               => (string) $payData['method'],
                                            'payment_date'         => now(),
                                            'note'                 => (string) ($payData['note'] ?? ''),
                                            'created_by'           => \Illuminate\Support\Facades\Auth::id(),
                                        ]);

                                        // Checkout flags
                                        $g->forceFill([
                                            'actual_checkout' => now(),
                                            'bill_closed_at'  => now(),
                                        ])->save();
                                    };

                                    if ($mode === 'guest') {
                                        $target = null;
                                        if (!empty($data['guest_id'])) {
                                            $target = \App\Models\ReservationGuest::find((int)$data['guest_id']);
                                        }
                                        if (! $target) {
                                            $target = $record;
                                        }
                                        $doCheckout($target, $data);
                                    } else { // reservation
                                        $res = $record->reservation;
                                        $openGuests = $res
                                            ? $res->reservationGuests()->whereNull('actual_checkout')->get()
                                            : collect([$record])->whereNull('actual_checkout');

                                        // Bagi amount sama rata jika diisi global, atau hitung per-guest sendiri:
                                        // Di sini kita hitung ulang due per guest & bayar sebesar due-nya.
                                        foreach ($openGuests as $g) {
                                            $calc = \App\Filament\Resources\ReservationGuestCheckOuts\Schemas\ReservationGuestCheckOutForm::buildBreakdown($g);
                                            $due  = max(0, (int)$calc['grand_total'] - (int)$calc['deposit']);

                                            $payload = $data;
                                            $payload['amount'] = $due;

                                            $doCheckout($g, $payload);
                                        }
                                    }

                                    // close reservation checkout_date jika semua sudah C/O
                                    $res = $record->reservation;
                                    if ($res) {
                                        $remaining = $res->reservationGuests()->whereNull('actual_checkout')->count();
                                        if ($remaining === 0 && ! $res->checkout_date) {
                                            $res->checkout_date = now();
                                            $res->save();
                                        }
                                    }

                                    \Filament\Notifications\Notification::make()
                                        ->title($mode === 'guest' ? 'Guest checked out & payment recorded.' : 'All guests checked out & payments recorded.')
                                        ->success()
                                        ->send();
                                }),
                        ])->columns(1)->columnSpan(3),

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
