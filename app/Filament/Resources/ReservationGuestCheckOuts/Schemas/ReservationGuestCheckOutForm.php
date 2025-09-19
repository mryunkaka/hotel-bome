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
use Filament\Forms\Components\Hidden;
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

                                // ❌ non-aktif kalau RG sudah checkout
                                ->disabled(fn(\App\Models\ReservationGuest $record): bool => filled($record->actual_checkout))

                                ->form([
                                    TextInput::make('adjustment_rp')
                                        ->label('Adjustment (±Rp) → ditempel ke kolom Service')
                                        ->numeric()
                                        ->required(),
                                    Textarea::make('reason')->label('Reason')->rows(2),
                                ])

                                ->action(function (array $data, \App\Models\ReservationGuest $record): void {
                                    // 🔒 server-side guard: stop jika sudah checkout
                                    if (filled($record->actual_checkout)) {
                                        \Filament\Notifications\Notification::make()
                                            ->title('Guest sudah checkout — tidak bisa melakukan Room Post & Correction.')
                                            ->warning()
                                            ->send();
                                        return;
                                    }

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

                                // Disable bila guest sudah checkout ATAU payment untuk RG ini sudah ada
                                ->disabled(function (\App\Models\ReservationGuest $record): bool {
                                    if (filled($record->actual_checkout)) {
                                        return true;
                                    }
                                    return \App\Models\Payment::where('reservation_guest_id', $record->id)->exists();
                                })

                                ->form(function (\App\Models\ReservationGuest $record) {
                                    return [
                                        Radio::make('checkout_mode')
                                            ->label('Checkout Mode')
                                            ->options([
                                                'guest' => 'Checkout selected guest',
                                                'reservation' => 'Checkout whole reservation',
                                            ])
                                            ->default('guest')
                                            ->inline()
                                            ->required(),

                                        Radio::make('deduct_deposit')
                                            ->label('Deduct reservation deposit?')
                                            ->options(['YES' => 'Yes', 'NO' => 'No'])
                                            ->default('NO')
                                            ->inline()
                                            ->required(),

                                        Hidden::make('guest_id')->default((string) $record->id),

                                        TextInput::make('amount')->label('Amount (IDR)')->numeric()->required(),

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
                                    // Guard: kalau sudah ada payment untuk RG ini, biarkan action() yang nolak (tombol sudah disabled juga)
                                    $ensureDue = function (\App\Models\ReservationGuest $g): int {
                                        $calc = \App\Filament\Resources\ReservationGuestCheckOuts\Schemas\ReservationGuestCheckOutForm::buildBreakdown($g);
                                        return max(0, (int) $calc['grand_total'] - (int) $calc['deposit']);
                                    };

                                    $mode          = $data['checkout_mode'] ?? 'guest';
                                    $deductDeposit = ($data['deduct_deposit'] ?? 'YES') === 'YES';
                                    $res           = $record->reservation;
                                    $resDeposit    = (int) ($res->deposit ?? 0);

                                    if (! isset($data['amount']) || ! $data['amount']) {
                                        if ($mode === 'guest') {
                                            $due = $ensureDue($record);
                                            $net = $deductDeposit ? max(0, $due - $resDeposit) : $due;
                                            $data['amount'] = $net;
                                        } else {
                                            $openGuests = $res
                                                ? $res->reservationGuests()->whereNull('actual_checkout')->get()
                                                : collect([$record])->whereNull('actual_checkout');

                                            $sumDue = 0;
                                            foreach ($openGuests as $g) {
                                                $sumDue += $ensureDue($g);
                                            }
                                            $net = $deductDeposit ? max(0, $sumDue - $resDeposit) : $sumDue;
                                            $data['amount'] = $net;
                                        }
                                    }

                                    $data['guest_id'] = (string) $record->id;
                                    return $data;
                                })

                                ->action(function (array $data, \App\Models\ReservationGuest $record): void {
                                    // GUARD 1: sudah checkout?
                                    if (filled($record->actual_checkout)) {
                                        \Filament\Notifications\Notification::make()
                                            ->title('Guest ini sudah checkout.')
                                            ->warning()
                                            ->send();
                                        return;
                                    }

                                    // GUARD 2: sudah ada payment untuk RG ini?
                                    $alreadyPaid = \App\Models\Payment::where('reservation_guest_id', $record->id)->exists();
                                    if ($alreadyPaid) {
                                        \Filament\Notifications\Notification::make()
                                            ->title('Payment untuk guest ini sudah ada — tidak boleh dobel.')
                                            ->warning()
                                            ->send();
                                        return;
                                    }

                                    $mode          = $data['checkout_mode'] ?? 'guest';
                                    $deductDeposit = ($data['deduct_deposit'] ?? 'YES') === 'YES';
                                    $res           = $record->reservation;
                                    $resDeposit    = (int) ($res->deposit ?? 0);

                                    $ensureDue = function (\App\Models\ReservationGuest $g): array {
                                        $calc = \App\Filament\Resources\ReservationGuestCheckOuts\Schemas\ReservationGuestCheckOutForm::buildBreakdown($g);
                                        $due  = max(0, (int) $calc['grand_total'] - (int) $calc['deposit']);
                                        return ['due' => $due, 'calc' => $calc];
                                    };

                                    // Buat payment: untuk refund, JANGAN isi reservation_guest_id agar tidak "double per RG"
                                    $makePayment = function (
                                        \App\Models\ReservationGuest $g,
                                        int $amount,
                                        int $depositUsed = 0,
                                        bool $isRefund = false,
                                        string $method = null,
                                        string $note = null
                                    ): void {
                                        \App\Models\Payment::create([
                                            'hotel_id'             => $g->hotel_id,
                                            'reservation_id'       => $g->reservation_id,
                                            'reservation_guest_id' => $isRefund ? null : $g->id, // <- refund tanpa RG id
                                            'amount'               => $amount,
                                            'method'               => $method ?: ($isRefund ? 'DEPOSIT_REFUND' : 'CASH'),
                                            'payment_date'         => now(),
                                            'note'                 => (string) ($note ?? ''),
                                            'created_by'           => \Illuminate\Support\Facades\Auth::id(),
                                            'deposit_used'         => $depositUsed,
                                            'is_deposit_refund'    => $isRefund,
                                        ]);
                                    };

                                    $checkoutGuest = function (\App\Models\ReservationGuest $g): void {
                                        $g->forceFill([
                                            'actual_checkout' => now(),
                                            'bill_closed_at'  => now(),
                                        ])->save();
                                    };

                                    if ($mode === 'guest') {
                                        // ===== Hanya RG ini =====
                                        $info = $ensureDue($record);
                                        $due  = $info['due'];

                                        $depositUsed    = $deductDeposit ? min($resDeposit, $due) : 0;
                                        $depositLeft    = $resDeposit - $depositUsed;
                                        $refundLeftover = $deductDeposit ? max(0, $depositLeft) : 0;

                                        $amountToCharge = (int) $data['amount'];

                                        // Payment utama untuk RG ini (AMAN: RG ini belum punya payment)
                                        $makePayment($record, $amountToCharge, $depositUsed, false, (string) $data['method'], (string) ($data['note'] ?? ''));

                                        // Refund sisa deposit (tanpa RG id)
                                        if ($deductDeposit && $refundLeftover > 0) {
                                            $makePayment($record, $refundLeftover, 0, true, 'DEPOSIT_REFUND', 'Refund leftover deposit at checkout');
                                        }

                                        if ($deductDeposit && $res) {
                                            $res->deposit = 0;
                                            $res->deposit_cleared_at = now();
                                            $res->save();
                                        }

                                        $checkoutGuest($record);
                                    } else {
                                        // ===== Checkout seluruh reservation =====
                                        $openGuests = $res
                                            ? $res->reservationGuests()->whereNull('actual_checkout')->get()
                                            : collect([$record])->whereNull('actual_checkout');

                                        // Hitung due & siapkan deposit
                                        $dues = [];
                                        foreach ($openGuests as $g) {
                                            $dues[$g->id] = $ensureDue($g)['due'];
                                        }
                                        $remainingDeposit = $deductDeposit ? $resDeposit : 0;

                                        foreach ($openGuests as $g) {
                                            // GUARD per-guest: kalau payment untuk RG ini sudah ada, SKIP guest itu
                                            if (\App\Models\Payment::where('reservation_guest_id', $g->id)->exists()) {
                                                continue;
                                            }

                                            $due        = (int) ($dues[$g->id] ?? 0);
                                            $useForThis = min($remainingDeposit, $due);
                                            $remainingDeposit -= $useForThis;

                                            $amountThisGuest = max(0, $due - $useForThis);

                                            $makePayment($g, $amountThisGuest, $useForThis, false, (string) $data['method'], (string) ($data['note'] ?? ''));
                                            $checkoutGuest($g);
                                        }

                                        // Refund sisa deposit (tanpa RG id)
                                        if ($deductDeposit && $remainingDeposit > 0) {
                                            $makePayment($record, (int) $remainingDeposit, 0, true, 'DEPOSIT_REFUND', 'Refund leftover deposit at reservation checkout');
                                            $remainingDeposit = 0;
                                        }

                                        if ($deductDeposit && $res) {
                                            $res->deposit = 0;
                                            $res->deposit_cleared_at = now();
                                            $res->save();
                                        }
                                    }

                                    // Tutup reservation jika semua sudah checkout
                                    $res = $record->reservation;
                                    if ($res && $res->reservationGuests()->whereNull('actual_checkout')->count() === 0 && ! $res->checkout_date) {
                                        $res->checkout_date = now();
                                        $res->save();
                                    }

                                    // TAMBAH (setelah sukses checkout)
                                    if ($record->room_id) {
                                        \App\Models\Room::whereKey($record->room_id)->update([
                                            'status' => \App\Models\Room::ST_VD, // keluar kamar → kotor
                                            'status_changed_at' => now(),
                                        ]);
                                    }

                                    \Filament\Notifications\Notification::make()
                                        ->title($mode === 'guest'
                                            ? 'Guest checked out & payment recorded.'
                                            : 'All guests checked out & payments recorded.')
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
