<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReservationGuestCheckOuts\Schemas;

use App\Models\Bank;
use App\Models\Room;
use App\Models\Payment;
use App\Models\BankLedger;
use Filament\Support\RawJs;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use App\Models\AccountLedger;
use App\Models\MinibarReceipt;
use Illuminate\Support\Carbon;
use App\Models\ReservationGuest;
use App\Support\ReservationMath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Actions as SchemaActions;
use Illuminate\Support\Facades\Schema as DBSchema; // ⬅️ penting: aliaskan ke DBSchema
use Illuminate\Support\Carbon as _Carbon;
use App\Support\ReservationMath as _Math;

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
                                ->url(
                                    fn(\App\Models\ReservationGuest $record) =>
                                    route('reservation-guests.bill', $record) . '?mode=all'
                                )
                                ->openUrlInNewTab()
                                ->color('primary')
                                ->button(),
                        ])->columns(1)->columnSpan(3)
                            ->alignment('center'),

                        SchemaActions::make([
                            \Filament\Actions\Action::make('split_bill')
                                ->label('Split Bill')
                                ->icon('heroicon-o-scissors')
                                ->color('warning')
                                ->button()
                                ->mountUsing(function (
                                    \App\Models\ReservationGuest $record,
                                    \Filament\Actions\Action $action,
                                    \Livewire\Component $livewire
                                ) {
                                    $alreadySplit = \App\Models\Payment::query()
                                        ->where('reservation_id', $record->reservation_id)
                                        ->where('reservation_guest_id', $record->id)
                                        // kalau hanya mau yang berasal dari split, aktifkan baris di bawah:
                                        // ->where('method', 'SPLIT')
                                        ->exists();

                                    if ($alreadySplit) {
                                        $url = route('reservation-guests.bill', $record) . '?mode=single';
                                        $livewire->js("window.open('{$url}', '_blank', 'noopener,noreferrer')");
                                        $action->halt(); // <- hentikan mounting, modal tidak jadi dibuka
                                    }
                                })
                                ->schema([
                                    // ===== Show porsi tagihan (read-only)
                                    \Filament\Forms\Components\TextInput::make('actual_amount_view')
                                        ->label('Actual Amount (IDR)')
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->mask(\Filament\Support\RawJs::make('$money($input)'))
                                        ->extraInputAttributes(['inputmode' => 'numeric'])
                                        ->afterStateHydrated(function ($set, \App\Models\ReservationGuest $record) {
                                            $tz  = 'Asia/Makassar';
                                            $res = $record->reservation;

                                            // Ambil SEMUA RG & peta total minibar semua RG (sekali query) — sama seperti bill
                                            $allGuests = $res ? $res->reservationGuests()->orderBy('id')->get() : collect();
                                            $ids       = $allGuests->pluck('id')->all();

                                            $minibarMap = [];
                                            if (! empty($ids)) {
                                                $minibarMap = \App\Models\MinibarReceipt::query()
                                                    ->whereIn('reservation_guest_id', $ids)
                                                    ->selectRaw('reservation_guest_id, SUM(total_amount) AS sum_total')
                                                    ->groupBy('reservation_guest_id')
                                                    ->pluck('sum_total', 'reservation_guest_id')
                                                    ->toArray();
                                            }

                                            // === BASE tamu INI (harus SAMA persis dengan bill.blade.php) ===
                                            $in  = $record->actual_checkin ?: $record->expected_checkin;
                                            $out = $record->actual_checkout ?: \Illuminate\Support\Carbon::now($tz);
                                            $n   = \App\Support\ReservationMath::nights($in, $out, 1);

                                            $rate    = (float) \App\Support\ReservationMath::basicRate($record);
                                            $discPct = (float) ($record->discount_percent ?? 0);
                                            $discAmt = (int) round(($rate * $discPct) / 100);
                                            $after   = max(0, $rate - $discAmt);

                                            $charge   = (int) ($record->charge ?? 0);
                                            $extra    = (int) ($record->extra_bed_total ?? ((int) ($record->extra_bed ?? 0) * 100_000));
                                            $mbSvc    = (int) ($minibarMap[$record->id] ?? 0); // minibar = "Service"
                                            $pen      = \App\Support\ReservationMath::latePenalty(
                                                $record->expected_checkin ?: ($record->reservation?->expected_arrival),
                                                $record->actual_checkin,
                                                $rate,
                                                ['tz' => $tz],
                                            );
                                            $penalty  = (int) ($pen['amount'] ?? 0);

                                            $baseThis = (int) ($after * $n + $charge + $mbSvc + $extra + $penalty);

                                            // === totalBaseAll dengan rumus yang SAMA persis seperti di bill ===
                                            $totalBaseAll = 0;
                                            foreach ($allGuests as $rg) {
                                                $inG  = $rg->actual_checkin ?: $rg->expected_checkin;
                                                $outG = $rg->actual_checkout ?: \Illuminate\Support\Carbon::now($tz);
                                                $nG   = \App\Support\ReservationMath::nights($inG, $outG, 1);

                                                $rateG    = (float) \App\Support\ReservationMath::basicRate($rg);
                                                $discPctG = (float) ($rg->discount_percent ?? 0);
                                                $discAmtG = (int) round(($rateG * $discPctG) / 100);
                                                $afterG   = max(0, $rateG - $discAmtG);

                                                $chargeG = (int) ($rg->charge ?? 0);
                                                $extraG  = (int) ($rg->extra_bed_total ?? ((int) ($rg->extra_bed ?? 0) * 100_000));
                                                $mbSvcG  = (int) ($minibarMap[$rg->id] ?? 0);
                                                $penG    = \App\Support\ReservationMath::latePenalty(
                                                    $rg->expected_checkin ?: ($rg->reservation?->expected_arrival),
                                                    $rg->actual_checkin,
                                                    $rateG,
                                                    ['tz' => $tz],
                                                );
                                                $penaltyG = (int) ($penG['amount'] ?? 0);

                                                $baseG = (int) ($afterG * $nG + $chargeG + $mbSvcG + $extraG + $penaltyG);
                                                $totalBaseAll += $baseG;
                                            }

                                            $taxPctReservation = (float) ($res?->tax?->percent ?? 0);

                                            // === Pajak total reservation → bagi rata (identik dengan bill) ===
                                            $participants = max(1, (int) $allGuests->count());
                                            if ($participants <= 0) {
                                                $participants = max(1, (int) ($res?->num_guests ?? 1));
                                            }

                                            $totalTaxAll  = (int) round(($totalBaseAll * $taxPctReservation) / 100);
                                            $taxPerPerson = intdiv($totalTaxAll, $participants);
                                            $remainder    = $totalTaxAll - ($taxPerPerson * $participants);

                                            if ($remainder > 0 && $res) {
                                                $maxId = (int) $allGuests->max('id');
                                                if ((int) $record->id === $maxId) {
                                                    $taxPerPerson += $remainder;
                                                }
                                            }

                                            // === Actual (untuk modal) = BASE tamu ini + share pajak global
                                            $actual = (int) $baseThis + (int) $taxPerPerson;

                                            $set('actual_amount_view', $actual);
                                            $set('actual_amount', $actual);
                                            $set('amount', $actual); // default Amount = Actual
                                        }),

                                    \Filament\Forms\Components\Hidden::make('actual_amount'),

                                    // ===== Toggle pay now
                                    \Filament\Forms\Components\Toggle::make('record_payment_now')
                                        ->label('Record payment now (post to ledger)')
                                        ->inline(false)
                                        ->live(),

                                    // ===== Fields saat pay now
                                    \Filament\Forms\Components\TextInput::make('amount')
                                        ->label('Amount (IDR)')
                                        ->numeric()
                                        ->minValue(0)
                                        ->mask(\Filament\Support\RawJs::make('$money($input)'))
                                        ->stripCharacters(',')
                                        ->visible(fn(Get $get) => (bool) $get('record_payment_now'))
                                        ->required(fn(Get $get) => (bool) $get('record_payment_now'))
                                        ->dehydrated(fn(Get $get) => (bool) $get('record_payment_now')),

                                    \Filament\Forms\Components\Select::make('method')
                                        ->label('Method')
                                        ->options([
                                            'CASH'     => 'Cash',
                                            'CARD'     => 'Card',
                                            'TRANSFER' => 'Transfer',
                                            'OTHER'    => 'Other',
                                        ])
                                        ->visible(fn(Get $get) => (bool) $get('record_payment_now'))
                                        ->required(fn(Get $get) => (bool) $get('record_payment_now'))
                                        ->live()
                                        ->dehydrated(fn(Get $get) => (bool) $get('record_payment_now')),

                                    \Filament\Forms\Components\Select::make('bank_id')
                                        ->label('Bank Account')
                                        ->options(function (\App\Models\ReservationGuest $record) {
                                            return \App\Models\Bank::query()
                                                ->where('hotel_id', $record->hotel_id)
                                                ->orderBy('name')->pluck('name', 'id');
                                        })
                                        ->searchable()->preload()->native(false)
                                        ->visible(
                                            fn(Get $get) =>
                                            (bool) $get('record_payment_now') && in_array($get('method'), ['CARD', 'TRANSFER'], true)
                                        )
                                        ->required(
                                            fn(Get $get) =>
                                            (bool) $get('record_payment_now') && in_array($get('method'), ['CARD', 'TRANSFER'], true)
                                        )
                                        ->dehydrated(
                                            fn(Get $get) =>
                                            (bool) $get('record_payment_now') && in_array($get('method'), ['CARD', 'TRANSFER'], true)
                                        )
                                        ->helperText('Wajib diisi untuk CARD/TRANSFER.'),

                                    \Filament\Forms\Components\Textarea::make('note')
                                        ->label('Note')
                                        ->rows(2)
                                        ->visible(fn(Get $get) => (bool) $get('record_payment_now'))
                                        ->dehydrated(fn(Get $get) => (bool) $get('record_payment_now')),
                                ])
                                ->action(function (array $data, \App\Models\ReservationGuest $record, \Livewire\Component $livewire) {

                                    // Jika sudah checkout: JANGAN buat payment baru → hanya buka bill single.
                                    if (filled($record->actual_checkout)) {
                                        $livewire->js("window.open('" . route('reservation-guests.bill', $record) . "?mode=single', '_blank', 'noopener,noreferrer')");
                                        return;
                                    }

                                    // ===== Recompute actual_amount jika kosong/0 (safety server-side)
                                    $actual = (int) ($data['actual_amount'] ?? 0);
                                    if ($actual <= 0) {
                                        $tz  = 'Asia/Makassar';
                                        $res = $record->reservation;

                                        $allGuests = $res ? $res->reservationGuests()->orderBy('id')->get() : collect();
                                        $ids       = $allGuests->pluck('id')->all();

                                        $minibarMap = [];
                                        if (! empty($ids)) {
                                            $minibarMap = \App\Models\MinibarReceipt::query()
                                                ->whereIn('reservation_guest_id', $ids)
                                                ->selectRaw('reservation_guest_id, SUM(total_amount) AS sum_total')
                                                ->groupBy('reservation_guest_id')
                                                ->pluck('sum_total', 'reservation_guest_id')
                                                ->toArray();
                                        }

                                        // BASE tamu INI
                                        $in  = $record->actual_checkin ?: $record->expected_checkin;
                                        $out = $record->actual_checkout ?: \Illuminate\Support\Carbon::now($tz);
                                        $n   = \App\Support\ReservationMath::nights($in, $out, 1);

                                        $rate    = (float) \App\Support\ReservationMath::basicRate($record);
                                        $discPct = (float) ($record->discount_percent ?? 0);
                                        $discAmt = (int) round(($rate * $discPct) / 100);
                                        $after   = max(0, $rate - $discAmt);

                                        $charge  = (int) ($record->charge ?? 0);
                                        $extra   = (int) ($record->extra_bed_total ?? ((int) ($record->extra_bed ?? 0) * 100_000));
                                        $mbSvc   = (int) ($minibarMap[$record->id] ?? 0);
                                        $pen     = \App\Support\ReservationMath::latePenalty(
                                            $record->expected_checkin ?: ($record->reservation?->expected_arrival),
                                            $record->actual_checkin,
                                            $rate,
                                            ['tz' => $tz],
                                        );
                                        $penalty = (int) ($pen['amount'] ?? 0);

                                        $baseThis = (int) ($after * $n + $charge + $mbSvc + $extra + $penalty);

                                        // totalBaseAll
                                        $totalBaseAll = 0;
                                        foreach ($allGuests as $rg) {
                                            $inG  = $rg->actual_checkin ?: $rg->expected_checkin;
                                            $outG = $rg->actual_checkout ?: \Illuminate\Support\Carbon::now($tz);
                                            $nG   = \App\Support\ReservationMath::nights($inG, $outG, 1);

                                            $rateG    = (float) \App\Support\ReservationMath::basicRate($rg);
                                            $discPctG = (float) ($rg->discount_percent ?? 0);
                                            $discAmtG = (int) round(($rateG * $discPctG) / 100);
                                            $afterG   = max(0, $rateG - $discAmtG);

                                            $chargeG = (int) ($rg->charge ?? 0);
                                            $extraG  = (int) ($rg->extra_bed_total ?? ((int) ($rg->extra_bed ?? 0) * 100_000));
                                            $mbSvcG  = (int) ($minibarMap[$rg->id] ?? 0);
                                            $penG    = \App\Support\ReservationMath::latePenalty(
                                                $rg->expected_checkin ?: ($rg->reservation?->expected_arrival),
                                                $rg->actual_checkin,
                                                $rateG,
                                                ['tz' => $tz],
                                            );
                                            $penaltyG = (int) ($penG['amount'] ?? 0);

                                            $baseG = (int) ($afterG * $nG + $chargeG + $mbSvcG + $extraG + $penaltyG);
                                            $totalBaseAll += $baseG;
                                        }

                                        $taxPctReservation = (float) ($res?->tax?->percent ?? 0);
                                        $participants      = max(1, (int) $allGuests->count());
                                        if ($participants <= 0) {
                                            $participants = max(1, (int) ($res?->num_guests ?? 1));
                                        }

                                        $totalTaxAll  = (int) round(($totalBaseAll * $taxPctReservation) / 100);
                                        $taxPerPerson = intdiv($totalTaxAll, $participants);
                                        $remainder    = $totalTaxAll - ($taxPerPerson * $participants);

                                        if ($remainder > 0 && $res) {
                                            $maxId = (int) $allGuests->max('id');
                                            if ((int) $record->id === $maxId) {
                                                $taxPerPerson += $remainder;
                                            }
                                        }

                                        $actual = (int) $baseThis + (int) $taxPerPerson;
                                    }

                                    // ===== MODE 1: Split only (marker, tanpa posting ledger)
                                    if (empty($data['record_payment_now'])) {
                                        $res = $record->reservation;
                                        if (! $res) {
                                            \Filament\Notifications\Notification::make()->title('Reservation tidak ditemukan.')->warning()->send();
                                            return;
                                        }

                                        // tandai checkout pada RG ini (header reservation tidak disentuh)
                                        $record->forceFill([
                                            'actual_checkout' => now(),
                                            'bill_closed_at'  => now(),
                                        ])->save();

                                        \App\Models\Payment::updateOrCreate(
                                            ['reservation_guest_id' => $record->id, 'method' => 'SPLIT'],
                                            [
                                                'hotel_id'       => (int) $res->hotel_id,
                                                'reservation_id' => (int) $res->id,
                                                'amount'         => $actual,
                                                'actual_amount'  => $actual,
                                                'payment_date'   => now(),
                                                'notes'          => 'Auto entry (Split Bill)',
                                                'created_by'     => \Illuminate\Support\Facades\Auth::id(),
                                            ]
                                        );

                                        // Mark minibar paid (sekali update – hindari when() on int)
                                        $now    = now();
                                        $update = [];
                                        if (\Illuminate\Support\Facades\Schema::hasColumn('minibar_receipts', 'is_paid')) $update['is_paid'] = true;
                                        if (\Illuminate\Support\Facades\Schema::hasColumn('minibar_receipts', 'status'))  $update['status']  = 'PAID';
                                        if (\Illuminate\Support\Facades\Schema::hasColumn('minibar_receipts', 'paid_at')) $update['paid_at'] = $now;
                                        if (\Illuminate\Support\Facades\Schema::hasColumn('minibar_receipts', 'paid_by')) $update['paid_by'] = \Illuminate\Support\Facades\Auth::id();
                                        if (! empty($update)) {
                                            \App\Models\MinibarReceipt::where('reservation_guest_id', $record->id)->update($update);
                                        }

                                        // room → VD
                                        if ($record->room_id) {
                                            \App\Models\Room::whereKey($record->room_id)->update([
                                                'status'            => \App\Models\Room::ST_VD,
                                                'status_changed_at' => $now,
                                            ]);
                                        }

                                        $livewire->js("window.open('" . route('reservation-guests.bill', $record) . "?mode=single', '_blank', 'noopener,noreferrer')");
                                        return;
                                    }

                                    // ===== MODE 2: Pay now + posting ke ledger (tetap sama)
                                    $res = $record->reservation;
                                    if (! $res) {
                                        \Filament\Notifications\Notification::make()->title('Reservation tidak ditemukan.')->warning()->send();
                                        return;
                                    }

                                    $paid       = (int) ($data['amount'] ?? 0);
                                    if ($paid < $actual) {
                                        \Filament\Notifications\Notification::make()
                                            ->title('Amount kurang dari porsi tagihan (split).')
                                            ->body('Jumlah yang dibayar harus ≥ Actual Amount.')
                                            ->danger()->send();
                                        return;
                                    }

                                    $methodForm = strtoupper((string) ($data['method'] ?? 'CASH'));
                                    $bankId     = isset($data['bank_id']) ? (int) $data['bank_id'] : null;

                                    if (in_array($methodForm, ['CARD', 'TRANSFER'], true)) {
                                        if (empty($bankId)) {
                                            \Filament\Notifications\Notification::make()->title('Bank belum dipilih.')->warning()->send();
                                            return;
                                        }
                                        $isSameHotel = \App\Models\Bank::whereKey($bankId)->where('hotel_id', $record->hotel_id)->exists();
                                        if (! $isSameHotel) {
                                            \Filament\Notifications\Notification::make()->title('Bank tidak valid untuk hotel ini.')->danger()->send();
                                            return;
                                        }
                                    }

                                    $methodForLedger = match ($methodForm) {
                                        'CASH'     => 'cash',
                                        'CARD'     => 'edc',
                                        'TRANSFER' => 'transfer',
                                        default    => 'other',
                                    };

                                    \Illuminate\Support\Facades\DB::transaction(function () use ($data, $record, $res, $methodForLedger, $bankId, $methodForm, $actual, $paid) {

                                        $now = now();
                                        $record->forceFill([
                                            'actual_checkout' => $now,
                                            'bill_closed_at'  => $now,
                                        ])->save();

                                        $payment = \App\Models\Payment::create([
                                            'hotel_id'             => (int) $res->hotel_id,
                                            'reservation_id'       => (int) $res->id,
                                            'reservation_guest_id' => (int) $record->id,
                                            'bank_id'              => $bankId,
                                            'amount'               => $paid,
                                            'actual_amount'        => $actual,
                                            'method'               => $methodForm,
                                            'payment_date'         => now(),
                                            'notes'                => (string) ($data['note'] ?? 'Split Bill (pay now)'),
                                            'created_by'           => \Illuminate\Support\Facades\Auth::id(),
                                        ]);

                                        $entryDate   = now()->toDateString();
                                        $desc        = 'Split Checkout #' . ($res->reservation_no ?? $res->id) . '/RG#' . $record->id;

                                        $change      = max(0, $paid - $actual);
                                        $amountInNet = min($paid, $actual);

                                        if ($methodForLedger === 'cash' || empty($bankId)) {
                                            \App\Models\AccountLedger::create([
                                                'hotel_id'        => (int) $res->hotel_id,
                                                'ledger_type'     => 'room',
                                                'reference_table' => 'payments',
                                                'reference_id'    => (int) $payment->id,
                                                'account_code'    => 'CASH_ON_HAND',
                                                'method'          => 'cash',
                                                'debit'           => $amountInNet,
                                                'credit'          => 0,
                                                'date'            => $entryDate,
                                                'description'     => $desc . ' (receipt)',
                                                'is_posted'       => true,
                                                'posted_at'       => now(),
                                                'posted_by'       => \Illuminate\Support\Facades\Auth::id(),
                                            ]);
                                            if ($change > 0) {
                                                \App\Models\AccountLedger::create([
                                                    'hotel_id'        => (int) $res->hotel_id,
                                                    'ledger_type'     => 'room',
                                                    'reference_table' => 'payments',
                                                    'reference_id'    => (int) $payment->id,
                                                    'account_code'    => 'CASH_ON_HAND',
                                                    'method'          => 'cash',
                                                    'debit'           => 0,
                                                    'credit'          => $change,
                                                    'date'            => $entryDate,
                                                    'description'     => $desc . ' (change returned)',
                                                    'is_posted'       => true,
                                                    'posted_at'       => now(),
                                                    'posted_by'       => \Illuminate\Support\Facades\Auth::id(),
                                                ]);
                                            }
                                        } else {
                                            \App\Models\BankLedger::create([
                                                'hotel_id'        => (int) $res->hotel_id,
                                                'bank_id'         => (int) $bankId,
                                                'deposit'         => $amountInNet,
                                                'withdraw'        => 0,
                                                'date'            => $entryDate,
                                                'description'     => $desc . ' (receipt)',
                                                'method'          => $methodForLedger,
                                                'ledger_type'     => 'room',
                                                'reference_table' => 'payments',
                                                'reference_id'    => (int) $payment->id,
                                                'is_posted'       => true,
                                                'posted_at'       => now(),
                                                'posted_by'       => \Illuminate\Support\Facades\Auth::id(),
                                            ]);
                                            if ($change > 0) {
                                                \App\Models\BankLedger::create([
                                                    'hotel_id'        => (int) $res->hotel_id,
                                                    'bank_id'         => (int) $bankId,
                                                    'deposit'         => 0,
                                                    'withdraw'        => $change,
                                                    'date'            => $entryDate,
                                                    'description'     => $desc . ' (refund/change)',
                                                    'method'          => $methodForLedger,
                                                    'ledger_type'     => 'room',
                                                    'reference_table' => 'payments',
                                                    'reference_id'    => (int) $payment->id,
                                                    'is_posted'       => true,
                                                    'posted_at'       => now(),
                                                    'posted_by'       => \Illuminate\Support\Facades\Auth::id(),
                                                ]);
                                            }
                                        }

                                        // Mark minibar paid (sekali update – FIX)
                                        $update = [];
                                        if (\Illuminate\Support\Facades\Schema::hasColumn('minibar_receipts', 'is_paid')) $update['is_paid'] = true;
                                        if (\Illuminate\Support\Facades\Schema::hasColumn('minibar_receipts', 'status'))  $update['status']  = 'PAID';
                                        if (\Illuminate\Support\Facades\Schema::hasColumn('minibar_receipts', 'paid_at')) $update['paid_at'] = $now;
                                        if (\Illuminate\Support\Facades\Schema::hasColumn('minibar_receipts', 'paid_by')) $update['paid_by'] = \Illuminate\Support\Facades\Auth::id();
                                        if (! empty($update)) {
                                            \App\Models\MinibarReceipt::where('reservation_guest_id', $record->id)->update($update);
                                        }

                                        // Pastikan room → VD
                                        if ($record->room_id) {
                                            \App\Models\Room::whereKey($record->room_id)->update([
                                                'status'            => \App\Models\Room::ST_VD,
                                                'status_changed_at' => $now,
                                            ]);
                                        }

                                        \Filament\Notifications\Notification::make()
                                            ->title('Split payment posted.')
                                            ->success()->send();
                                    });

                                    // Tampilkan bill single
                                    $livewire->js("window.open('" . route('reservation-guests.bill', $record) . "?mode=single', '_blank', 'noopener,noreferrer')");
                                })
                                ->tooltip('Pisahkan tagihan untuk tamu ini'),
                        ])->columns(1)->columnSpan(3)->alignment('center'),

                        // ===================== Payment & Check Out =====================
                        // ===================== Payment & Check Out =====================
                        SchemaActions::make([
                            Action::make('pay_and_checkout_adv')
                                ->label('C/O')
                                ->icon('heroicon-o-credit-card')
                                ->color('success')
                                ->button()
                                ->disabled(function (\App\Models\ReservationGuest $record): bool {
                                    $hasActualCheckout = filled($record->actual_checkout);
                                    $hasPayment        = Payment::where('reservation_guest_id', $record->id)->exists();

                                    Log::info('[C/O Button] CHECK', [
                                        'guest_id'           => $record->id,
                                        'reservation_id'     => $record->reservation_id,
                                        'hasActualCheckout'  => $hasActualCheckout,
                                        'hasPayment'         => $hasPayment,
                                        'disabled'           => ($hasActualCheckout || $hasPayment),
                                    ]);

                                    return $hasActualCheckout || $hasPayment;
                                })
                                ->schema([
                                    Hidden::make('reservation_guest_id')
                                        ->default(fn(\App\Models\ReservationGuest $record) => $record->id),

                                    TextInput::make('actual_amount_view')
                                        ->label('Actual Amount (IDR)')
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->mask(RawJs::make('$money($input)'))
                                        ->extraInputAttributes(['inputmode' => 'numeric'])
                                        ->afterStateHydrated(function ($state, callable $set, \App\Models\ReservationGuest $record) {
                                            $dueNow = self::dueNowForGuest($record);
                                            $set('actual_amount_view', $dueNow);
                                            $set('actual_amount', $dueNow);
                                            $set('amount', $dueNow);

                                            Log::info('[C/O hydrate] dueNow', [
                                                'guest_id' => $record->id,
                                                'due_now'  => $dueNow,
                                            ]);
                                        }),

                                    Hidden::make('actual_amount'),

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
                                        ->required()
                                        ->reactive(),

                                    Select::make('bank_id')
                                        ->label('Bank Account')
                                        ->options(fn(\App\Models\ReservationGuest $record) => Bank::query()
                                            ->where('hotel_id', $record->hotel_id)
                                            ->orderBy('name')
                                            ->pluck('name', 'id'))
                                        ->searchable()
                                        ->preload()
                                        ->native(false)
                                        ->visible(fn(Get $get) => in_array($get('method'), ['CARD', 'TRANSFER'], true))
                                        ->required(fn(Get $get) => in_array($get('method'), ['CARD', 'TRANSFER'], true))
                                        ->helperText('Wajib diisi untuk CARD/TRANSFER.'),

                                    Textarea::make('note')->label('Note')->rows(2),
                                ])
                                ->mutateDataUsing(function (array $data, \App\Models\ReservationGuest $record) {
                                    $due = self::dueNowForGuest($record);

                                    $data['amount']                ??= $due;
                                    $data['actual_amount']         ??= $due;
                                    $data['reservation_guest_id']  = (int) $record->id;

                                    Log::info('[C/O Button] mutateDataUsing', [
                                        'guest_id' => $record->id,
                                        'due'      => $due,
                                        'data'     => $data,
                                    ]);

                                    return $data;
                                })
                                ->action(function (array $data, \App\Models\ReservationGuest $record, \Livewire\Component $livewire) {
                                    Log::info('[C/O Button] CLICK', [
                                        'guest_id'       => $record->id,
                                        'reservation_id' => $record->reservation_id,
                                        'data'           => $data,
                                        'user_id'        => Auth::id(),
                                    ]);

                                    $pay  = (int) ($data['amount'] ?? 0);
                                    $must = (int) ($data['actual_amount'] ?? 0);

                                    if ($pay < $must) {
                                        Log::warning('[C/O Button] Amount kurang dari tagihan', compact('pay', 'must'));
                                        \Filament\Notifications\Notification::make()
                                            ->title('Amount kurang dari tagihan.')
                                            ->body('Jumlah yang dibayar harus ≥ Actual Amount.')
                                            ->danger()->send();
                                        return;
                                    }

                                    $methodForm = strtoupper((string) ($data['method'] ?? 'CASH'));
                                    $bankId     = isset($data['bank_id']) ? (int) $data['bank_id'] : null;

                                    if (in_array($methodForm, ['CARD', 'TRANSFER'], true) && empty($bankId)) {
                                        Log::warning('[C/O Button] Bank wajib diisi untuk CARD/TRANSFER', ['method' => $methodForm, 'bank_id' => $bankId]);
                                        \Filament\Notifications\Notification::make()
                                            ->title('Bank belum dipilih.')
                                            ->body('Pilih bank untuk metode CARD/TRANSFER.')
                                            ->warning()->send();
                                        return;
                                    }

                                    if ($bankId) {
                                        $isSameHotel = Bank::whereKey($bankId)
                                            ->where('hotel_id', $record->hotel_id)
                                            ->exists();
                                        if (! $isSameHotel) {
                                            Log::error('[C/O Button] Bank tidak sesuai hotel', ['bank_id' => $bankId, 'hotel_id' => $record->hotel_id]);
                                            \Filament\Notifications\Notification::make()
                                                ->title('Bank tidak valid.')
                                                ->body('Akun bank yang dipilih tidak sesuai dengan hotel aktif.')
                                                ->danger()->send();
                                            return;
                                        }
                                    }

                                    $methodForLedger = match ($methodForm) {
                                        'CASH'     => 'cash',
                                        'CARD'     => 'edc',
                                        'TRANSFER' => 'transfer',
                                        default    => 'other',
                                    };

                                    try {
                                        DB::beginTransaction();

                                        $res = $record->reservation()->lockForUpdate()->first();
                                        if (! $res) {
                                            Log::error('[C/O Button] Reservation not found', ['guest_id' => $record->id]);
                                            \Filament\Notifications\Notification::make()
                                                ->title('Reservation tidak ditemukan.')
                                                ->warning()->send();
                                            DB::rollBack();
                                            return;
                                        }

                                        if (filled($record->actual_checkout)) {
                                            Log::warning('[C/O Button] Guest already checked out', ['guest_id' => $record->id]);
                                            \Filament\Notifications\Notification::make()
                                                ->title('Guest ini sudah checkout.')
                                                ->warning()->send();
                                            DB::rollBack();
                                            return;
                                        }

                                        // 1) Payment
                                        Log::info('[C/O Button] Creating payment ...', ['guest_id' => $record->id]);
                                        $payment = Payment::create([
                                            'hotel_id'             => $res->hotel_id,
                                            'reservation_id'       => $res->id,
                                            'reservation_guest_id' => $record->id,
                                            'bank_id'              => $bankId,
                                            'amount'               => $pay,
                                            'actual_amount'        => $must,
                                            'method'               => $methodForm,
                                            'payment_date'         => now(),
                                            'notes'                => (string) ($data['note'] ?? ''),
                                            'created_by'           => Auth::id(),
                                        ]);
                                        Log::info('[C/O Button] Payment created', ['payment_id' => $payment->id]);

                                        // 2) Ledger
                                        $entryDate   = now()->toDateString();
                                        $description = 'Room Checkout #' . ($res->reservation_no ?? $res->id);
                                        $change      = max(0, $pay - $must);
                                        $amountInNet = min($pay, $must);

                                        if ($methodForLedger === 'cash' || empty($bankId)) {
                                            Log::info('[C/O Button] Posting AccountLedger (cash) ...', compact('amountInNet', 'change'));
                                            AccountLedger::create([
                                                'hotel_id'        => (int) $res->hotel_id,
                                                'ledger_type'     => 'room',
                                                'reference_table' => 'payments',
                                                'reference_id'    => (int) $payment->id,
                                                'account_code'    => 'CASH_ON_HAND',
                                                'method'          => 'cash',
                                                'debit'           => $amountInNet,
                                                'credit'          => 0,
                                                'date'            => $entryDate,
                                                'description'     => $description . ' (receipt)',
                                                'is_posted'       => true,
                                                'posted_at'       => now(),
                                                'posted_by'       => Auth::id(),
                                            ]);
                                            if ($change > 0) {
                                                AccountLedger::create([
                                                    'hotel_id'        => (int) $res->hotel_id,
                                                    'ledger_type'     => 'room',
                                                    'reference_table' => 'payments',
                                                    'reference_id'    => (int) $payment->id,
                                                    'account_code'    => 'CASH_ON_HAND',
                                                    'method'          => 'cash',
                                                    'debit'           => 0,
                                                    'credit'          => $change,
                                                    'date'            => $entryDate,
                                                    'description'     => $description . ' (change returned)',
                                                    'is_posted'       => true,
                                                    'posted_at'       => now(),
                                                    'posted_by'       => Auth::id(),
                                                ]);
                                            }
                                        } else {
                                            Log::info('[C/O Button] Posting BankLedger ...', ['amountInNet' => $amountInNet, 'change' => $change, 'bank_id' => $bankId, 'method' => $methodForLedger]);
                                            BankLedger::create([
                                                'hotel_id'        => (int) $res->hotel_id,
                                                'bank_id'         => (int) $bankId,
                                                'deposit'         => $amountInNet,
                                                'withdraw'        => 0,
                                                'date'            => $entryDate,
                                                'description'     => $description . ' (receipt)',
                                                'method'          => $methodForLedger,
                                                'ledger_type'     => 'room',
                                                'reference_table' => 'payments',
                                                'reference_id'    => (int) $payment->id,
                                                'is_posted'       => true,
                                                'posted_at'       => now(),
                                                'posted_by'       => Auth::id(),
                                            ]);
                                            if ($change > 0) {
                                                BankLedger::create([
                                                    'hotel_id'        => (int) $res->hotel_id,
                                                    'bank_id'         => (int) $bankId,
                                                    'deposit'         => 0,
                                                    'withdraw'        => $change,
                                                    'date'            => $entryDate,
                                                    'description'     => $description . ' (refund/change)',
                                                    'method'          => $methodForLedger,
                                                    'ledger_type'     => 'room',
                                                    'reference_table' => 'payments',
                                                    'reference_id'    => (int) $payment->id,
                                                    'is_posted'       => true,
                                                    'posted_at'       => now(),
                                                    'posted_by'       => Auth::id(),
                                                ]);
                                            }
                                        }

                                        // 3) Checkout massal: current + sibling RG tanpa due & belum checkout
                                        $now = now();

                                        // current
                                        $record->forceFill([
                                            'actual_checkout' => $now,
                                            'bill_closed_at'  => $now,
                                        ])->saveQuietly();

                                        // minibar current
                                        $upd = [];
                                        if (DBSchema::hasColumn('minibar_receipts', 'is_paid')) {
                                            $upd['is_paid'] = true;
                                        }
                                        if (DBSchema::hasColumn('minibar_receipts', 'status')) {
                                            $upd['status'] = 'PAID';
                                        }
                                        if (DBSchema::hasColumn('minibar_receipts', 'paid_at')) {
                                            $upd['paid_at'] = $now;
                                        }
                                        if (DBSchema::hasColumn('minibar_receipts', 'paid_by')) {
                                            $upd['paid_by'] = Auth::id();
                                        }
                                        if (! empty($upd)) {
                                            MinibarReceipt::query()->where('reservation_guest_id', $record->id)->update($upd);
                                        }

                                        // room current
                                        if ($record->room_id) {
                                            Room::whereKey($record->room_id)->update([
                                                'status'            => Room::ST_VD,
                                                'status_changed_at' => $now,
                                            ]);
                                        }

                                        // siblings
                                        // siblings: checkout SEMUA tamu lain yang actual_checkout masih null
                                        $siblings = $res->reservationGuests()
                                            ->lockForUpdate()
                                            ->whereNull('actual_checkout')
                                            ->whereKeyNot($record->id)
                                            ->get();

                                        $checkedOutSiblings = 0;

                                        foreach ($siblings as $g) {
                                            // mark checkout
                                            $g->forceFill([
                                                'actual_checkout' => $now,
                                                'bill_closed_at'  => $now,
                                            ])->saveQuietly();

                                            // mark minibar paid (pakai $upd yang sudah disiapkan di atas)
                                            if (!empty($upd)) {
                                                MinibarReceipt::query()
                                                    ->where('reservation_guest_id', $g->id)
                                                    ->update($upd);
                                            }

                                            // set room -> VD
                                            if ($g->room_id) {
                                                Room::whereKey($g->room_id)->update([
                                                    'status'            => Room::ST_VD,
                                                    'status_changed_at' => $now,
                                                ]);
                                            }

                                            $checkedOutSiblings++;
                                        }

                                        // reservation checkout_date
                                        $resFresh = $res->fresh();
                                        if ($resFresh->reservationGuests()->whereNull('actual_checkout')->count() === 0 && blank($resFresh->checkout_date)) {
                                            $resFresh->checkout_date = $now;
                                            $resFresh->save();
                                        }

                                        DB::commit();

                                        // Notifikasi + open bill + redirect — pakai nowdoc biar aman dari kutip
                                        $baseMsg = 'Payment tersimpan & guest telah checkout.';
                                        if ($checkedOutSiblings > 0) {
                                            $baseMsg .= " Auto-checkout {$checkedOutSiblings} guest lain dalam reservation yang sama.";
                                        }

                                        \Filament\Notifications\Notification::make()
                                            ->title('Checkout berhasil')
                                            ->body($baseMsg)
                                            ->success()
                                            ->send();

                                        $printUrl = route('reservation-guests.bill', $record) . '?mode=all';
                                        $listUrl  = url('/admin/reservation-guest-check-outs');

                                        $printUrlJs = json_encode($printUrl);
                                        $listUrlJs  = json_encode($listUrl);

                                        $livewire->js(
                                            <<<'JS'
                                                (function () {
                                                    const printUrl = __PRINT_URL__;
                                                    const listUrl  = __LIST_URL__;
                                                    const a = document.createElement('a');
                                                    a.href = printUrl;
                                                    a.target = '_blank';
                                                    a.rel = 'noopener,noreferrer';
                                                    a.style.display = 'none';
                                                    document.body.appendChild(a);
                                                    a.click();
                                                    document.body.removeChild(a);
                                                    window.location.href = listUrl;
                                                })();
                                            JS,
                                            // placeholders (Filament akan replace string sebelum eval)
                                            placeholders: [
                                                '__PRINT_URL__' => $printUrlJs,
                                                '__LIST_URL__'  => $listUrlJs,
                                            ]
                                        );
                                    } catch (\Throwable $e) {
                                        DB::rollBack();
                                        Log::error('[C/O Button] EXCEPTION', [
                                            'guest_id' => $record->id,
                                            'message'  => $e->getMessage(),
                                            'file'     => $e->getFile(),
                                            'line'     => $e->getLine(),
                                        ]);

                                        \Filament\Notifications\Notification::make()
                                            ->title('Gagal checkout.')
                                            ->body($e->getMessage())
                                            ->danger()->send();
                                    }
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
        $end   = $rg->actual_checkout ?: \Illuminate\Support\Carbon::now($tz);

        $totalAll      = 0;
        $totalChecked  = 0;
        $checkedItems  = []; // <-- detail siapa & berapa

        if ($rg->reservation) {
            $allGuests = $rg->reservation->reservationGuests()->orderBy('id')->get();

            foreach ($allGuests as $g) {
                $calcG  = \App\Support\ReservationMath::guestBill($g, ['tz' => 'Asia/Makassar']);
                $grandG = (int) ($calcG['grand'] ?? 0);

                $totalAll += $grandG;

                if (filled($g->actual_checkout)) {
                    $totalChecked += $grandG;
                    $checkedItems[] = [
                        'guest_id'   => $g->id,
                        'guest_name' => $g->guest?->name ?: ('RG#' . $g->id),
                        'amount'     => $grandG,
                    ];
                }
            }
        }

        $openDue = max(0, $totalAll - $totalChecked);

        return [
            'reservation_total_all'     => (int) $totalAll,
            'reservation_total_checked' => (int) $totalChecked,
            'reservation_open_due'      => (int) $openDue,
            'checked_items'             => $checkedItems, // <-- baru
            'rg'                => $rg,
            'nights'            => $calc['nights'],
            'rate_after_disc'   => (int) $calc['room_after_disc'],
            'charge'            => (int) $calc['charge'],
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

    private static function dueNowForGuest(ReservationGuest $rg): int
    {
        $res = $rg->reservation;
        if (! $res) {
            return 0;
        }

        // Ambil semua RG dalam reservation ini (cukup eager load tax saja jika memang ada relasinya)
        $guests = $res->reservationGuests()
            ->with(['reservation.tax'])   // ⬅️ HAPUS 'reservation.service'
            ->orderBy('id')
            ->get();

        $sumGrand     = 0; // TOTAL (Amount Due + Tax) semua tamu
        $checkedGrand = 0; // TOTAL untuk tamu yang SUDAH checkout

        foreach ($guests as $g) {
            // Nights
            $in  = $g->actual_checkin ?: $g->expected_checkin;
            $out = $g->actual_checkout ?: now('Asia/Makassar');
            $n   = \App\Support\ReservationMath::nights($in, $out, 1);

            // Basic rate & diskon
            $rate     = (float) \App\Support\ReservationMath::basicRate($g);
            $discPct  = (float) ($g->discount_percent ?? 0);
            $discAmt  = (int) round(($rate * $discPct) / 100);
            $rateAfter = max(0, $rate - $discAmt);

            // Komponen lain
            $charge  = (int) ($g->charge ?? 0);
            $extra   = (int) ($g->extra_bed_total ?? ((int) ($g->extra_bed ?? 0) * 100_000));

            // Penalty (prioritaskan expected_checkin RG)
            $pen = \App\Support\ReservationMath::latePenalty(
                $g->expected_checkin ?: ($g->reservation?->expected_arrival),
                $g->actual_checkin,
                $rate,
                ['tz' => 'Asia/Makassar'],
            );
            $penalty = (int) ($pen['amount'] ?? 0);

            // Minibar subtotal per-RG
            $minibarSub = (int) \App\Models\MinibarReceiptItem::query()
                ->whereHas('receipt', fn($q) => $q->where('reservation_guest_id', $g->id))
                ->sum('line_total');

            // Service dihitung dari subtotal minibar × service_percent (field di reservations)
            $svcPct    = (float) ($g->reservation?->service_percent ?? 0);
            $serviceRp = (int) round(($minibarSub * $svcPct) / 100);

            // Pajak & GRAND
            $taxPct  = (float) ($g->reservation?->tax?->percent ?? 0);
            $taxBase = (int) ($rateAfter * $n + $charge + $minibarSub + $serviceRp + $extra + $penalty);
            $taxRp   = (int) round(($taxBase * $taxPct) / 100);
            $grand   = (int) ($taxBase + $taxRp);

            $sumGrand += $grand;
            if (filled($g->actual_checkout)) {
                $checkedGrand += $grand;
            }
        }

        // Amount to pay now (sesuai blade: total seluruh tamu dikurangi yang sudah checkout)
        $remaining = max(0, $sumGrand - $checkedGrand);

        Log::info('[C/O dueNowForGuest]', [
            'reservation_id' => $res->id,
            'sumGrand'       => $sumGrand,
            'checkedGrand'   => $checkedGrand,
            'remaining'      => $remaining,
        ]);

        return $remaining;
    }
}
