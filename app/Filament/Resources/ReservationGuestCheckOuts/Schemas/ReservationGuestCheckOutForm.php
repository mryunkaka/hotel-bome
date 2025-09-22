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
                        ])->columns(1)->columnSpan(2),

                        // Room Post & Correction
                        SchemaActions::make([
                            Action::make('post_corr')
                                ->label('Room Post & Corr')
                                ->icon('heroicon-o-adjustments-horizontal')

                                // 🔒 Non-aktif jika SUDAH checkout ATAU payment utk RG ini SUDAH ada
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

                                ->form([
                                    \Filament\Forms\Components\TextInput::make('adjustment_rp')
                                        ->label('Adjustment (±Rp) → ditempel ke kolom Service')
                                        ->numeric()
                                        ->required(),
                                    \Filament\Forms\Components\Textarea::make('reason')->label('Reason')->rows(2),
                                ])

                                ->action(function (array $data, \App\Models\ReservationGuest $record): void {
                                    // 🛡️ Server-side guard (selaras dengan disabled)
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

                                    // ✅ Lanjut update service
                                    $record->service = (int) ($record->service ?? 0) + (int) $data['adjustment_rp'];
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
                                ->disabled(fn(\App\Models\ReservationGuest $record) => blank($record->actual_checkout))
                                ->tooltip(fn(\App\Models\ReservationGuest $record) => blank($record->actual_checkout)
                                    ? 'Only available after guest has checked out.' : null)
                                ->url(fn(\App\Models\ReservationGuest $record) => route('reservation-guests.bill', $record)) // <= tanpa ?html -> PDF inline
                                ->openUrlInNewTab(),
                        ])->columns(1)->columnSpan(2),

                        // Split Bill (buat reservasi baru dari RG ini)
                        SchemaActions::make([
                            Action::make('split_bill')
                                ->label('Split Bill (New Reservation)')
                                ->icon('heroicon-o-scissors')
                                ->color('warning')
                                // Terkunci bila PAYMENT utk RG ini sudah ada (konsisten dgn Payment & C/O)
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
                                ->form(function (\App\Models\ReservationGuest $record) {
                                    return [
                                        // Nomor reservasi baru (opsional) — kalau kosong akan di-generate dari Model
                                        \Filament\Forms\Components\TextInput::make('reservation_no')
                                            ->label('New Reservation No (optional)')
                                            ->maxLength(64)
                                            ->placeholder('Auto-generate from model if empty'),

                                        // Pindahkan pajak (id_tax) ke reservasi baru?
                                        \Filament\Forms\Components\Radio::make('move_tax')
                                            ->label('Move Tax setting (id_tax) to new reservation?')
                                            ->options(['YES' => 'Yes', 'NO' => 'No'])
                                            ->default('YES')
                                            ->inline()
                                            ->required(),

                                        // Pindahkan deposit?
                                        \Filament\Forms\Components\Radio::make('move_deposit')
                                            ->label('Move reservation deposit?')
                                            ->options([
                                                'NONE'    => 'No',
                                                'ALL'     => 'All',
                                                'PARTIAL' => 'Partial',
                                            ])
                                            ->default('NONE')
                                            ->inline()
                                            ->required(),

                                        // Jika PARTIAL, minta nominalnya
                                        \Filament\Forms\Components\TextInput::make('deposit_amount')
                                            ->label('Deposit to move (IDR)')
                                            ->numeric()
                                            ->minValue(0)
                                            ->mask(\Filament\Support\RawJs::make('$money($input)'))
                                            ->stripCharacters(',')
                                            ->visible(fn(\Filament\Schemas\Components\Utilities\Get $get) => $get('move_deposit') === 'PARTIAL'),

                                        // Copy remarks?
                                        \Filament\Forms\Components\Radio::make('copy_remarks')
                                            ->label('Copy remarks to new reservation?')
                                            ->options(['YES' => 'Yes', 'NO' => 'No'])
                                            ->default('YES')
                                            ->inline()
                                            ->required(),
                                    ];
                                })
                                ->action(function (array $data, \App\Models\ReservationGuest $record) {
                                    \Illuminate\Support\Facades\DB::transaction(function () use ($data, $record) {
                                        /** @var \App\Models\Reservation $old */
                                        $old = $record->reservation;
                                        if (! $old) {
                                            \Filament\Notifications\Notification::make()
                                                ->title('Reservation not found.')
                                                ->danger()
                                                ->send();
                                            return;
                                        }

                                        // === Gunakan generator di Model Reservation ===
                                        $base = trim((string) ($data['reservation_no'] ?? ''));

                                        if ($base === '') {
                                            // Auto-generate: pakai generator dari model (mengacu hotel aktif/rg)
                                            $base = \App\Models\Reservation::generateReservationNo($old->hotel_id);
                                        }

                                        // Jaga unikness: bila tabrakan, tambahkan suffix -1, -2, ...
                                        $no = $base;
                                        $i  = 1;
                                        while (\App\Models\Reservation::where('reservation_no', $no)->exists()) {
                                            $no = $base . '-' . $i++;
                                        }

                                        // Hitung deposit yg dipindah
                                        $oldDeposit = (int) ($old->deposit ?? 0);
                                        $moveMode   = $data['move_deposit'] ?? 'NONE';
                                        $moveAmt    = 0;

                                        if ($moveMode === 'ALL') {
                                            $moveAmt = $oldDeposit;
                                        } elseif ($moveMode === 'PARTIAL') {
                                            $req = (int) ($data['deposit_amount'] ?? 0);
                                            $moveAmt = max(0, min($oldDeposit, $req));
                                        }

                                        // Tentukan id_tax untuk reservasi baru
                                        $moveTax  = ($data['move_tax'] ?? 'YES') === 'YES';
                                        $newTaxId = $moveTax ? ($old->id_tax ?? null) : null;

                                        // Buat reservation baru (copy field penting)
                                        /** @var \App\Models\Reservation $new */
                                        $new = \App\Models\Reservation::create([
                                            'hotel_id'           => $old->hotel_id,
                                            'reservation_no'     => $no,
                                            'expected_arrival'   => $old->expected_arrival,
                                            'expected_departure' => $old->expected_departure,
                                            'method'             => $old->method,
                                            'status'             => $old->status,
                                            'deposit'            => $moveAmt,
                                            'reserved_by_type'   => $old->reserved_by_type,
                                            'guest_id'           => $old->guest_id,   // tetap sama
                                            'group_id'           => $old->group_id,   // tetap sama
                                            'entry_date'         => $old->entry_date,
                                            'created_by'         => \Illuminate\Support\Facades\Auth::id() ?: $old->created_by,
                                            'id_tax'             => $newTaxId,
                                            'remarks'            => (($data['copy_remarks'] ?? 'YES') === 'YES') ? ($old->remarks ?? null) : null,
                                        ]);

                                        // Kurangi deposit di reservasi lama
                                        if ($moveAmt > 0) {
                                            $old->deposit = max(0, $oldDeposit - $moveAmt);
                                            // deposit_cleared_at dibiarkan apa adanya
                                            $old->save();
                                        }

                                        // Pindahkan RG ini ke reservasi baru
                                        $record->reservation_id = $new->id;
                                        $record->save();

                                        \Filament\Notifications\Notification::make()
                                            ->title('Split Bill success.')
                                            ->body('Guest moved to new reservation: ' . $new->reservation_no)
                                            ->success()
                                            ->send();
                                    });

                                    // Refresh ke halaman RG yang sama (sekarang sudah di reservasi baru)
                                    return redirect()->to(
                                        \App\Filament\Resources\ReservationGuestCheckOuts\ReservationGuestCheckOutResource::getUrl('edit', [
                                            'record' => $record->getKey(),
                                        ])
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
                                    $hasDeposit = (int) ($record->reservation->deposit ?? 0) > 0;

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

                                        // ⬇️ HANYA tampil bila deposit > 0
                                        Radio::make('deduct_deposit')
                                            ->label('Deduct reservation deposit?')
                                            ->options(['YES' => 'Yes', 'NO' => 'No'])
                                            ->default('NO')                     // default aman
                                            ->inline()
                                            ->required()
                                            ->visible($hasDeposit),             // <— kunci tampil

                                        Hidden::make('guest_id')->default((string) $record->id),

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

                                ->action(function (array $data, \App\Models\ReservationGuest $record) {
                                    DB::transaction(function () use ($data, $record) {
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
                                            ?string $method = null,
                                            ?string $note = null
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

                                        $checkoutGuest = function (ReservationGuest $g): void {
                                            $g->forceFill([
                                                'actual_checkout' => now(),
                                                'bill_closed_at'  => now(),
                                            ])->save();

                                            if ($g->room_id) {
                                                \App\Models\Room::whereKey($g->room_id)->update([
                                                    'status'            => \App\Models\Room::ST_VD,    // Vacant Dirty setelah tamu keluar
                                                    'status_changed_at' => now(),
                                                ]);
                                            }
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
                                    });
                                    return redirect()->to(
                                        ReservationGuestCheckOutResource::getUrl('index')
                                    );
                                }),
                        ])->columns(1)->columnSpan(2),

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
