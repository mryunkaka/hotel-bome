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
                                    ? 'Hanya tersedia setelah semua tamu telah check out.' : null)
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
                                        return 'Tamu sudah check out.';
                                    }
                                    if (\App\Models\Payment::where('reservation_guest_id', $record->id)->exists()) {
                                        return 'Payment already recorded — posting/correction is locked.';
                                    }
                                    return null;
                                })
                                ->schema([
                                    TextInput::make('adjustment_rp')
                                        ->label('Adjustment (±Rp) → ditempel ke kolom Charge')
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
                                    $record->charge = (int) ($record->charge ?? 0) + (int) $data['adjustment_rp'];
                                    $record->save();

                                    \Filament\Notifications\Notification::make()
                                        ->title('Charge adjusted.')
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
                                    ? 'Hanya tersedia setelah semua tamu telah check out.' : null)
                                ->url(fn(\App\Models\ReservationGuest $record) => route('reservation-guests.bill', $record) . '?mode=all')
                                ->openUrlInNewTab()
                                ->color('primary')
                                ->button(),
                        ])->columns(1)->columnSpan(3)
                            ->alignment('center'),

                        // ===================== Split Bill =====================
                        SchemaActions::make([
                            Action::make('split_bill')
                                ->label('Split Bill')
                                ->icon('heroicon-o-scissors')
                                ->color('warning')
                                ->button()
                                ->action(function (array $data, \App\Models\ReservationGuest $record, \Livewire\Component $livewire) {
                                    // 1) Update actual_checkout bila masih null (sekalian checkout_date header)
                                    if (blank($record->actual_checkout)) {
                                        $now = now();

                                        $record->forceFill([
                                            'actual_checkout' => $now,
                                        ])->save();

                                        if ($record->reservation && blank($record->reservation->checkout_date)) {
                                            $record->reservation->forceFill([
                                                'checkout_date' => $now,
                                            ])->save();
                                        }
                                    }

                                    // 2) Hitung amount & actual_amount (SUBTOTAL + pajak yang dibagi rata)
                                    $res = $record->reservation;
                                    if ($res) {
                                        // Ambil breakdown untuk tamu ini
                                        $calc = \App\Filament\Resources\ReservationGuestCheckOuts\Schemas\ReservationGuestCheckOutForm::buildBreakdown($record);

                                        // Grand total & pajak menurut breakdown
                                        $grand      = (int) ($calc['grand_total'] ?? $calc['grand'] ?? 0);
                                        $taxTotalRp = (int) ($calc['tax_rp']      ?? 0);
                                        $taxPct     = (float)($calc['tax_percent'] ?? 0.0);

                                        // SUBTOTAL = grand total tanpa pajak
                                        $subTotal = max(0, $grand - $taxTotalRp);

                                        // Jumlah tamu dalam reservation (minimal 1)
                                        $participants = max(1, (int) $res->reservationGuests()->count());

                                        // Pajak per orang = (persentase pajak total) / (jumlah peserta)
                                        // Contoh: 12% dan 3 orang => 4% per orang
                                        $taxPerPersonPct = $taxPct / $participants;

                                        // Nilai pajak untuk tamu ini
                                        $taxPerPersonRp = (int) round($subTotal * ($taxPerPersonPct / 100));

                                        // Amount yang dibayar tamu ini (SUBTOTAL + pajak bagiannya)
                                        $actualAmount = (int) $subTotal + $taxPerPersonRp;

                                        // 3) Buat / update payment marker SPLIT untuk RG ini dgn nilai amount & actual_amount
                                        \App\Models\Payment::updateOrCreate(
                                            [
                                                // kunci unik marker split bill untuk RG ini
                                                'reservation_guest_id' => $record->id,
                                                'method'               => 'SPLIT',
                                            ],
                                            [
                                                'hotel_id'       => $res->hotel_id,
                                                'reservation_id' => $res->id,
                                                'amount'         => $actualAmount,
                                                'actual_amount'  => $actualAmount,
                                                'payment_date'   => now(),
                                                'notes'          => 'Auto entry (Split Bill)',
                                                'created_by'     => \Illuminate\Support\Facades\Auth::id(),
                                            ]
                                        );
                                    }

                                    // 4) Buka Bill (mode=single) di tab baru via JS
                                    $url = route('reservation-guests.bill', $record) . '?mode=single';
                                    $livewire->js("window.open('{$url}', '_blank', 'noopener,noreferrer')");
                                })
                                ->tooltip('Pisahkan tagihan untuk tamu ini'),
                        ])->columns(1)->columnSpan(3)->alignment('center'),

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
                                    Hidden::make('reservation_guest_id')
                                        ->default(fn(\App\Models\ReservationGuest $record) => $record->id),

                                    // === Actual Amount: ambil LANGSUNG dari Amount to pay now (preview) ===
                                    TextInput::make('actual_amount_view')
                                        ->label('Actual Amount (IDR)')
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->mask(RawJs::make('$money($input)'))
                                        ->extraInputAttributes(['inputmode' => 'numeric'])
                                        ->default(function (\App\Models\ReservationGuest $record): int {
                                            $calc = \App\Filament\Resources\ReservationGuestCheckOuts\Schemas\ReservationGuestCheckOutForm::buildBreakdown($record);
                                            return (int) ($calc['reservation_open_due'] ?? 0);   // == Amount to pay now
                                        }),

                                    Hidden::make('actual_amount')
                                        ->default(function (\App\Models\ReservationGuest $record): int {
                                            $calc = \App\Filament\Resources\ReservationGuestCheckOuts\Schemas\ReservationGuestCheckOutForm::buildBreakdown($record);
                                            return (int) ($calc['reservation_open_due'] ?? 0);   // == Amount to pay now
                                        }),

                                    // Default-kan amount ke actual_amount biar auto terisi
                                    TextInput::make('amount')
                                        ->label('Amount (IDR)')
                                        ->numeric()
                                        ->minValue(0)
                                        ->mask(RawJs::make('$money($input)'))
                                        ->stripCharacters(',')
                                        ->required()
                                        ->default(function (\App\Models\ReservationGuest $record): int {
                                            $calc = \App\Filament\Resources\ReservationGuestCheckOuts\Schemas\ReservationGuestCheckOutForm::buildBreakdown($record);
                                            return (int) ($calc['reservation_open_due'] ?? 0);
                                        }),

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
                                    // Safety fallback: kalau user hapus amount, pakai angka dari breakdown (Amount to pay now)
                                    if (!isset($data['amount']) || $data['amount'] === '' || $data['amount'] === null) {
                                        $calc = \App\Filament\Resources\ReservationGuestCheckOuts\Schemas\ReservationGuestCheckOutForm::buildBreakdown($record);
                                        $data['amount'] = (int) ($calc['reservation_open_due'] ?? 0);
                                    }

                                    // pastikan hidden actual_amount juga sinkron
                                    if (!isset($data['actual_amount'])) {
                                        $calc = \App\Filament\Resources\ReservationGuestCheckOuts\Schemas\ReservationGuestCheckOutForm::buildBreakdown($record);
                                        $data['actual_amount'] = (int) ($calc['reservation_open_due'] ?? 0);
                                    }

                                    $data['reservation_guest_id'] = (int) $record->id;
                                    return $data;
                                })
                                ->action(function (array $data, \App\Models\ReservationGuest $record, \Livewire\Component $livewire) {
                                    // Validasi: amount harus ≥ actual_amount
                                    $pay  = (int) ($data['amount'] ?? 0);
                                    $must = (int) ($data['actual_amount'] ?? 0);

                                    if ($pay < $must) {
                                        \Filament\Notifications\Notification::make()
                                            ->title('Amount kurang dari tagihan.')
                                            ->body('Jumlah yang dibayar harus ≥ Actual Amount.')
                                            ->danger()
                                            ->send();
                                        return; // batal insert
                                    }

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
                                            'reservation_guest_id' => $record->id,
                                            // amount = uang yang dibayar guest
                                            'amount'               => (int) $data['amount'],
                                            // actual_amount = persis "Amount to pay now" saat modal dibuka
                                            'actual_amount'        => (int) $data['actual_amount'],
                                            'method'               => (string) $data['method'],
                                            'payment_date'         => now(),
                                            // perhatikan: di model fillable kamu pakai 'notes', bukan 'note'
                                            'notes'                => (string) ($data['note'] ?? ''),
                                            'created_by'           => \Illuminate\Support\Facades\Auth::id(),
                                        ]);

                                        $now = now();
                                        $resFresh = $res->fresh();

                                        $openGuests = $resFresh->reservationGuests()->whereNull('actual_checkout')->get();
                                        foreach ($openGuests as $g) {
                                            // checkout-kan SELALU untuk guest pemicu ($record),
                                            // dan checkout-kan guest lain hanya jika SUDAH ada payment.
                                            $hasPayment = \App\Models\Payment::where('reservation_guest_id', $g->id)->exists();
                                            if (! $hasPayment && $g->id !== $record->id) {
                                                continue;
                                            }

                                            $g->forceFill([
                                                'actual_checkout' => $now,
                                                'bill_closed_at'  => $now,
                                            ])->save();

                                            // ===== Mark all minibar receipts for this guest as PAID on checkout =====
                                            $mr = \App\Models\MinibarReceipt::query()
                                                ->where('reservation_guest_id', $g->id);

                                            $update = [];
                                            if (\Illuminate\Support\Facades\Schema::hasColumn('minibar_receipts', 'is_paid')) {
                                                $update['is_paid'] = true;
                                            }
                                            if (\Illuminate\Support\Facades\Schema::hasColumn('minibar_receipts', 'status')) {
                                                $update['status'] = 'PAID';
                                            }
                                            if (\Illuminate\Support\Facades\Schema::hasColumn('minibar_receipts', 'paid_at')) {
                                                $update['paid_at'] = $now;
                                            }
                                            if (\Illuminate\Support\Facades\Schema::hasColumn('minibar_receipts', 'paid_by')) {
                                                $update['paid_by'] = \Illuminate\Support\Facades\Auth::id();
                                            }
                                            if (\Illuminate\Support\Facades\Schema::hasColumn('minibar_receipts', 'updated_by')) {
                                                $update['updated_by'] = \Illuminate\Support\Facades\Auth::id();
                                            }
                                            if (! empty($update)) {
                                                $mr->update($update);
                                            }

                                            if ($g->room_id) {
                                                \App\Models\Room::whereKey($g->room_id)->update([
                                                    'status'            => \App\Models\Room::ST_VD,
                                                    'status_changed_at' => $now,
                                                ]);
                                            }
                                        }

                                        if ($resFresh->reservationGuests()->whereNull('actual_checkout')->count() === 0 && ! $resFresh->checkout_date) {
                                            $resFresh->checkout_date = $now;
                                            $resFresh->save();
                                        }

                                        \Filament\Notifications\Notification::make()
                                            ->title('Payment tersimpan & semua guest sudah checkout.')
                                            ->success()
                                            ->send();
                                    });

                                    $printUrl = route('reservation-guests.bill', $record) . '?mode=all';
                                    $listUrl  = url('/admin/reservation-guest-check-outs'); // atau ReservationGuestCheckOutResource::getUrl('index')

                                    $jsPrint  = json_encode($printUrl);
                                    $jsList   = json_encode($listUrl);

                                    $livewire->js(<<<JS
            (() => {
            // buka print di tab baru
            const a = document.createElement('a');
            a.href = {$jsPrint};
            a.target = '_blank';
            a.rel = 'noopener,noreferrer';
            a.style.display = 'none';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);

            // redirect halaman sekarang ke list
            window.location.href = {$jsList};
            })();
            JS);

                                    return;
                                }),
                        ])->columns(1)->columnSpan(3)->alignment('center'),

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

        // === Agregat per reservation: total semua RG, total RG yang SUDAH checkout, dan sisa (open) ===
        $totalAll = 0;
        $totalChecked = 0;

        if ($rg->reservation) {
            $allGuests = $rg->reservation->reservationGuests()->orderBy('id')->get();

            foreach ($allGuests as $g) {
                $calcG  = \App\Support\ReservationMath::guestBill($g, ['tz' => 'Asia/Makassar']);
                $grandG = (int) ($calcG['grand'] ?? 0);

                $totalAll += $grandG;

                if (filled($g->actual_checkout)) {
                    $totalChecked += $grandG;
                }
            }
        }

        $openDue = max(0, $totalAll - $totalChecked);

        return [
            'reservation_total_all'     => (int) $totalAll,
            'reservation_total_checked' => (int) $totalChecked,
            'reservation_open_due'      => (int) $openDue,
            'rg'                => $rg,
            'nights'            => $calc['nights'],
            'rate_after_disc'   => (int) $calc['room_after_disc'],
            'charge'           => (int) $calc['charge'],
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
