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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\Schema as DBSchema;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\Utilities\Get as SchemaGet;

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
                        SchemaActions::make([
                            // 1) Guest Folio
                            Action::make('folio_pdf')
                                ->label('Guest Folio')
                                ->icon('heroicon-o-clipboard-document-list')
                                ->color('info')
                                ->button()
                                ->outlined()
                                ->disabled(function (\App\Models\ReservationGuest $record) {
                                    $res = $record->reservation;
                                    if (!$res) return true;
                                    return $res->reservationGuests()->whereNull('actual_checkout')->exists();
                                })
                                ->tooltip(function (\App\Models\ReservationGuest $record) {
                                    $res = $record->reservation;
                                    if (! $res) return 'Reservation tidak ditemukan.';
                                    return $res->reservationGuests()->whereNull('actual_checkout')->exists()
                                        ? 'Hanya tersedia setelah semua tamu telah check out.' : null;
                                })
                                ->url(fn(\App\Models\ReservationGuest $record) => route('reservation-guests.folio', $record))
                                ->openUrlInNewTab(),

                            // 2) P & C
                            Action::make('post_corr')
                                ->label('P & C')
                                ->icon('heroicon-o-adjustments-horizontal')
                                ->color('gray')
                                ->button()
                                ->outlined()
                                ->disabled(fn(\App\Models\ReservationGuest $record) => filled($record->actual_checkout))
                                ->tooltip(fn(\App\Models\ReservationGuest $record) => filled($record->actual_checkout) ? 'Tamu sudah check out.' : null)
                                ->schema([
                                    TextInput::make('adjustment_rp')
                                        ->label('Adjustment (±Rp) → ditempel ke kolom Charge')
                                        ->numeric()
                                        ->mask(RawJs::make('$money($input)'))
                                        ->stripCharacters(',')
                                        ->required(),
                                    \Filament\Forms\Components\Textarea::make('reason')->label('Reason')->rows(2),
                                ])
                                ->action(function (array $data, \App\Models\ReservationGuest $record): void {
                                    if (filled($record->actual_checkout)) {
                                        \Filament\Notifications\Notification::make()
                                            ->title('Guest sudah checkout — tidak bisa melakukan Room Post & Correction.')
                                            ->warning()->send();
                                        return;
                                    }
                                    $record->charge = (int) ($record->charge ?? 0) + (int) $data['adjustment_rp'];
                                    $record->save();
                                    \Filament\Notifications\Notification::make()->title('Charge adjusted.')->success()->send();
                                }),

                            // 3) Print Bill
                            Action::make('print_bill')
                                ->label('Print Bill')
                                ->icon('heroicon-o-printer')
                                ->color('primary')
                                ->button()
                                ->disabled(function (\App\Models\ReservationGuest $record) {
                                    $res = $record->reservation;
                                    if (! $res) return true;
                                    return $res->reservationGuests()->whereNull('actual_checkout')->exists();
                                })
                                ->tooltip(function (\App\Models\ReservationGuest $record) {
                                    $res = $record->reservation;
                                    if (! $res) return 'Reservation tidak ditemukan.';
                                    return $res->reservationGuests()->whereNull('actual_checkout')->exists()
                                        ? 'Hanya tersedia setelah semua tamu telah check out.' : null;
                                })
                                ->url(fn(\App\Models\ReservationGuest $record) => route('reservation-guests.bill', $record) . '?mode=all')
                                ->openUrlInNewTab(),

                            // 4) Split Minibar — tampil hanya jika ada due
                            Action::make('split_minibar')
                                ->label('Split Minibar')
                                ->icon('heroicon-o-sparkles')
                                ->color('secondary')
                                ->button()
                                ->visible(fn(\App\Models\ReservationGuest $record) => \App\Support\ReservationMath::hasUnpaidMinibar($record))
                                ->mountUsing(function (\App\Models\ReservationGuest $record, Action $action) {
                                    $due = \App\Support\ReservationMath::minibarDue($record);
                                    $action->fillForm([
                                        'actual_amount' => $due,
                                        'amount'        => $due,
                                        'method'        => null,
                                        'bank_id'       => null,
                                        'note'          => 'Split Minibar',
                                    ]);
                                })
                                ->schema([
                                    \Filament\Forms\Components\Placeholder::make('actual_amount_view')
                                        ->label('Minibar Due (IDR)')
                                        ->content(fn(\App\Models\ReservationGuest $record) =>
                                        'Rp ' . number_format(\App\Support\ReservationMath::minibarDue($record), 0, ',', '.')),
                                    Hidden::make('actual_amount')
                                        ->default(fn(\App\Models\ReservationGuest $record) => \App\Support\ReservationMath::minibarDue($record)),
                                    TextInput::make('amount')
                                        ->label('Amount (IDR)')
                                        ->numeric()->minValue(0)
                                        ->default(fn(\App\Models\ReservationGuest $record) => \App\Support\ReservationMath::minibarDue($record))
                                        ->mask(\Filament\Support\RawJs::make('$money($input)'))
                                        ->stripCharacters(',')->required(),
                                    Select::make('method')->label('Method')
                                        ->options(['CASH' => 'Cash', 'CARD' => 'Card', 'TRANSFER' => 'Transfer', 'OTHER' => 'Other'])
                                        ->required()->reactive(),
                                    Select::make('bank_id')->label('Bank Account')
                                        ->options(fn(\App\Models\ReservationGuest $record) => Bank::query()
                                            ->where('hotel_id', $record->hotel_id)->orderBy('name')->pluck('name', 'id'))
                                        ->searchable()->preload()->native(false)
                                        ->visible(fn(SchemaGet $get) => in_array($get('method'), ['CARD', 'TRANSFER'], true))
                                        ->required(fn(SchemaGet $get) => in_array($get('method'), ['CARD', 'TRANSFER'], true))
                                        ->helperText('Wajib diisi untuk CARD/TRANSFER.'),
                                    Textarea::make('note')->label('Note')->rows(2),
                                ])
                                // ⬇ aksi sama persis seperti yang sudah kamu buat sebelumnya
                                ->action(function (array $data, \App\Models\ReservationGuest $record, \Livewire\Component $livewire) {

                                    $res = $record->reservation;
                                    if (! $res) {
                                        \Filament\Notifications\Notification::make()->title('Reservation tidak ditemukan.')->warning()->send();
                                        return;
                                    }

                                    $must = (int) ($data['actual_amount'] ?? 0);
                                    if ($must <= 0) $must = \App\Support\ReservationMath::minibarDue($record);

                                    $pay  = (int) ($data['amount'] ?? 0);
                                    if ($pay < $must) {
                                        \Filament\Notifications\Notification::make()
                                            ->title('Amount kurang dari Minibar Due.')
                                            ->body('Jumlah yang dibayar harus ≥ Minibar Due.')
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
                                        'CASH' => 'cash',
                                        'CARD' => 'edc',
                                        'TRANSFER' => 'transfer',
                                        default => 'other'
                                    };

                                    $paymentId = null;
                                    $affectedReceiptIds = [];

                                    DB::transaction(function () use ($res, $record, $data, $methodForLedger, $bankId, $methodForm, $must, $pay, &$paymentId, &$affectedReceiptIds) {
                                        $now = now();

                                        // --- KUMPULKAN RECEIPT YANG AKAN DIBAYAR (sebelum di-update) ---
                                        $q = \App\Models\MinibarReceipt::query()->where('reservation_guest_id', $record->id);
                                        if (\Illuminate\Support\Facades\Schema::hasColumn('minibar_receipts', 'status')) {
                                            $q->where('status', '!=', 'PAID');
                                        } elseif (\Illuminate\Support\Facades\Schema::hasColumn('minibar_receipts', 'is_paid')) {
                                            $q->where(fn($z) => $z->whereNull('is_paid')->orWhere('is_paid', false));
                                        }
                                        $affectedReceiptIds = $q->pluck('id')->all();

                                        // 1) Payment
                                        $payment = \App\Models\Payment::create([
                                            'hotel_id'             => (int) $res->hotel_id,
                                            'reservation_id'       => (int) $res->id,
                                            'reservation_guest_id' => (int) $record->id,
                                            'bank_id'              => $bankId,
                                            'amount'               => $pay,
                                            'actual_amount'        => $must,
                                            'method'               => $methodForm,
                                            'payment_date'         => $now,
                                            'notes'                => (string) ($data['note'] ?? 'Split Minibar'),
                                            'created_by'           => \Illuminate\Support\Facades\Auth::id(),
                                        ]);
                                        $paymentId = (int) $payment->id;

                                        // 2) Ledger
                                        $entryDate   = $now->toDateString();
                                        $desc        = 'Split Minibar #' . ($res->reservation_no ?? $res->id) . '/RG#' . $record->id;

                                        $change      = max(0, $pay - $must);
                                        $amountInNet = min($pay, $must);

                                        if ($methodForLedger === 'cash' || empty($bankId)) {
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
                                                'description'     => $desc . ' (receipt)',
                                                'is_posted'       => true,
                                                'posted_at'       => $now,
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
                                                    'description'     => $desc . ' (change returned)',
                                                    'is_posted'       => true,
                                                    'posted_at'       => $now,
                                                    'posted_by'       => Auth::id(),
                                                ]);
                                            }
                                        } else {
                                            BankLedger::create([
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
                                                'posted_at'       => $now,
                                                'posted_by'       => Auth::id(),
                                            ]);
                                            if ($change > 0) {
                                                BankLedger::create([
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
                                                    'posted_at'       => $now,
                                                    'posted_by'       => Auth::id(),
                                                ]);
                                            }
                                        }

                                        // 3) Tandai MINIBAR receipt yang belum dibayar → PAID
                                        $upd = [];
                                        if (\Illuminate\Support\Facades\Schema::hasColumn('minibar_receipts', 'is_paid')) $upd['is_paid'] = true;
                                        if (\Illuminate\Support\Facades\Schema::hasColumn('minibar_receipts', 'status'))  $upd['status']  = 'PAID';
                                        if (\Illuminate\Support\Facades\Schema::hasColumn('minibar_receipts', 'paid_at')) $upd['paid_at'] = $now;
                                        if (\Illuminate\Support\Facades\Schema::hasColumn('minibar_receipts', 'paid_by')) $upd['paid_by'] = \Illuminate\Support\Facades\Auth::id();

                                        if (!empty($upd) && !empty($affectedReceiptIds)) {
                                            \App\Models\MinibarReceipt::whereIn('id', $affectedReceiptIds)->update($upd);
                                        }
                                    });

                                    \Filament\Notifications\Notification::make()->title('Split Minibar posted.')->success()->send();

                                    // === BUKA PRINT ===
                                    // Jika hanya 1 receipt → pakai route single
                                    if (count($affectedReceiptIds) === 1) {
                                        $url = route('minibar-receipts.print', ['receipt' => $affectedReceiptIds[0]]);
                                    } else {
                                        // Banyak receipt → pakai bulk note (lihat Route B di bawah)
                                        $url = route('minibar-receipts.print-bulk', [
                                            'ids' => implode(',', $affectedReceiptIds),
                                            'pid' => $paymentId,               // opsional, untuk tampilkan blok payment
                                        ]);
                                    }

                                    $livewire->js("window.open(" . json_encode($url) . ", '_blank', 'noopener,noreferrer')");
                                })
                                ->tooltip('Bayar hanya komponen Minibar (akan dikeluarkan dari Split Bill/C/O)'),

                            // 5) Split Bill
                            Action::make('split_bill')
                                ->label('Split Bill')
                                ->icon('heroicon-o-scissors')
                                ->color('warning')
                                ->button()
                                ->disabled(fn(\App\Models\ReservationGuest $record) => filled($record->actual_checkout))
                                ->mountUsing(function (
                                    \App\Models\ReservationGuest $record,
                                    \Filament\Actions\Action $action,
                                    \Livewire\Component $livewire
                                ) {
                                    // seed nilai awal - gunakan fungsi yang SAMA dengan blade view
                                    $actual = self::calculateSplitAmount($record);

                                    $action->fillForm([
                                        'actual_amount' => $actual,
                                        'amount'        => $actual,
                                        'method'        => null,
                                        'bank_id'       => null,
                                        'note'          => null,
                                    ]);
                                })
                                ->schema([
                                    // ==== DISPLAY actual (read-only, menggunakan Placeholder) ====
                                    \Filament\Forms\Components\Placeholder::make('actual_amount_display')
                                        ->label('Actual Amount (IDR)')
                                        ->content(function (\App\Models\ReservationGuest $record) {
                                            $amount = self::calculateSplitAmount($record);
                                            return 'Rp ' . number_format($amount, 0, ',', '.');
                                        }),

                                    \Filament\Forms\Components\Hidden::make('actual_amount')
                                        ->default(fn(\App\Models\ReservationGuest $record) => self::calculateSplitAmount($record)),

                                    // ==== Amount yang dibayar (tetap bisa diedit) ====
                                    \Filament\Forms\Components\TextInput::make('amount')
                                        ->label('Amount (IDR)')
                                        ->numeric()
                                        ->minValue(0)
                                        ->default(fn(\App\Models\ReservationGuest $record) => self::calculateSplitAmount($record))
                                        ->mask(\Filament\Support\RawJs::make('$money($input)'))
                                        ->stripCharacters(',')
                                        ->required()
                                        ->afterStateHydrated(function ($state, callable $set, callable $get, \App\Models\ReservationGuest $record) {
                                            if (blank($state)) {
                                                $fallback = self::calculateSplitAmount($record);
                                                $set('amount', $fallback);
                                            }
                                        }),

                                    \Filament\Forms\Components\Select::make('method')
                                        ->label('Pay Method')
                                        ->options([
                                            'CASH'     => 'Cash',
                                            'CARD'     => 'Card',
                                            'TRANSFER' => 'Transfer',
                                            'OTHER'    => 'Other',
                                        ])
                                        ->required()
                                        ->reactive()
                                        ->afterStateHydrated(function ($state) {}),

                                    \Filament\Forms\Components\Select::make('bank_id')
                                        ->label('Bank Account')
                                        ->options(
                                            fn(\App\Models\ReservationGuest $record) =>
                                            \App\Models\Bank::query()
                                                ->where('hotel_id', $record->hotel_id)
                                                ->orderBy('name')
                                                ->pluck('name', 'id')
                                        )
                                        ->searchable()->preload()->native(false)
                                        ->visible(
                                            fn(\Filament\Schemas\Components\Utilities\Get $get) =>
                                            in_array($get('method'), ['CARD', 'TRANSFER'], true)
                                        )
                                        ->required(
                                            fn(\Filament\Schemas\Components\Utilities\Get $get) =>
                                            in_array($get('method'), ['CARD', 'TRANSFER'], true)
                                        )
                                        ->helperText('Wajib diisi untuk CARD/TRANSFER.')
                                        ->afterStateHydrated(function ($state) {}),

                                    \Filament\Forms\Components\Textarea::make('note')->label('Note')->rows(2),
                                ])
                                ->action(function (array $data, \App\Models\ReservationGuest $record, \Livewire\Component $livewire) {

                                    if (filled($record->actual_checkout)) {
                                        $livewire->js("window.open('" . route('reservation-guests.bill', $record) . "?mode=single', '_blank', 'noopener,noreferrer')");
                                        return;
                                    }

                                    $actual = (int)($data['actual_amount'] ?? 0);
                                    if ($actual <= 0) {
                                        $actual = self::calculateSplitAmount($record);
                                    }

                                    $res = $record->reservation;
                                    if (! $res) {
                                        \Filament\Notifications\Notification::make()->title('Reservation tidak ditemukan.')->warning()->send();
                                        return;
                                    }

                                    $paid = (int)($data['amount'] ?? 0);
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

                                    DB::transaction(function () use ($data, $record, $res, $methodForLedger, $bankId, $methodForm, $actual, $paid) {
                                        $now = now();

                                        $record->forceFill(['actual_checkout' => $now, 'bill_closed_at' => $now])->save();

                                        $payment = Payment::create([
                                            'hotel_id'             => (int) $res->hotel_id,
                                            'reservation_id'       => (int) $res->id,
                                            'reservation_guest_id' => (int) $record->id,
                                            'bank_id'              => $bankId,
                                            'amount'               => $paid,
                                            'actual_amount'        => $actual,
                                            'method'               => $methodForm,
                                            'payment_date'         => now(),
                                            'notes'                => (string) ($data['note'] ?? 'Split Bill (pay now)'),
                                            'created_by'           => Auth::id(),
                                        ]);

                                        $entryDate   = now()->toDateString();
                                        $desc        = 'Split Checkout #' . ($res->reservation_no ?? $res->id) . '/RG#' . $record->id;

                                        $change      = max(0, $paid - $actual);
                                        $amountInNet = min($paid, $actual);

                                        if ($methodForLedger === 'cash' || empty($bankId)) {
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
                                                'description'     => $desc . ' (receipt)',
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
                                                    'description'     => $desc . ' (change returned)',
                                                    'is_posted'       => true,
                                                    'posted_at'       => now(),
                                                    'posted_by'       => Auth::id(),
                                                ]);
                                            }
                                        } else {
                                            BankLedger::create([
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
                                                'posted_by'       => Auth::id(),
                                            ]);
                                            if ($change > 0) {
                                                BankLedger::create([
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
                                                    'posted_by'       => Auth::id(),
                                                ]);
                                            }
                                        }

                                        $update = [];
                                        if (DBSchema::hasColumn('minibar_receipts', 'is_paid')) $update['is_paid'] = true;
                                        if (DBSchema::hasColumn('minibar_receipts', 'status'))  $update['status']  = 'PAID';
                                        if (DBSchema::hasColumn('minibar_receipts', 'paid_at')) $update['paid_at'] = $now;
                                        if (DBSchema::hasColumn('minibar_receipts', 'paid_by')) $update['paid_by'] = Auth::id();
                                        if (! empty($update)) {
                                            MinibarReceipt::where('reservation_guest_id', $record->id)->update($update);
                                        }

                                        if ($record->room_id) {
                                            Room::whereKey($record->room_id)->update([
                                                'status'            => Room::ST_VD,
                                                'status_changed_at' => $now,
                                            ]);
                                        }

                                        \Filament\Notifications\Notification::make()
                                            ->title('Split payment posted.')
                                            ->success()->send();
                                    });

                                    $livewire->js("window.open('" . route('reservation-guests.bill', $record) . "?mode=single','_blank','noopener,noreferrer')");
                                })
                                ->tooltip('Pisahkan tagihan untuk tamu ini'),

                            // 6) C/O
                            Action::make('pay_and_checkout_adv')
                                ->label('C/O')
                                ->icon('heroicon-o-credit-card')
                                ->color('success')
                                ->button()
                                ->disabled(fn(\App\Models\ReservationGuest $record) => filled($record->actual_checkout))
                                ->schema([
                                    Hidden::make('reservation_guest_id')
                                        ->default(fn(\App\Models\ReservationGuest $record) => $record->id),

                                    TextInput::make('actual_amount_view')
                                        ->label('Actual Amount (IDR)')
                                        ->disabled()
                                        ->dehydrated(false)
                                        // ->mask(RawJs::make('$money($input)'))
                                        ->extraInputAttributes(['inputmode' => 'numeric'])
                                        ->afterStateHydrated(function ($state, callable $set, \App\Models\ReservationGuest $record) {
                                            $dueNow = self::dueNowForGuest($record);
                                            $set('actual_amount_view', $dueNow);
                                            $set('actual_amount', $dueNow);
                                            $set('amount', $dueNow);
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
                                        ->label('Pay Method')
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
                                        ->visible(fn(SchemaGet $get) => in_array($get('method'), ['CARD', 'TRANSFER'], true))
                                        ->required(fn(SchemaGet $get) => in_array($get('method'), ['CARD', 'TRANSFER'], true))
                                        ->helperText('Wajib diisi untuk CARD/TRANSFER.'),

                                    Textarea::make('note')->label('Note')->rows(2),
                                ])
                                ->mutateDataUsing(function (array $data, \App\Models\ReservationGuest $record) {
                                    $due = self::dueNowForGuest($record);

                                    $data['amount']                ??= $due;
                                    $data['actual_amount']         ??= $due;
                                    $data['reservation_guest_id']  = (int) $record->id;

                                    return $data;
                                })
                                ->action(function (array $data, \App\Models\ReservationGuest $record, \Livewire\Component $livewire) {
                                    $pay  = (int) ($data['amount'] ?? 0);
                                    $must = (int) ($data['actual_amount'] ?? 0);

                                    if ($pay < $must) {
                                        \Filament\Notifications\Notification::make()
                                            ->title('Amount kurang dari tagihan.')
                                            ->body('Jumlah yang dibayar harus ≥ Actual Amount.')
                                            ->danger()->send();
                                        return;
                                    }

                                    $methodForm = strtoupper((string) ($data['method'] ?? 'CASH'));
                                    $bankId     = isset($data['bank_id']) ? (int) $data['bank_id'] : null;

                                    if (in_array($methodForm, ['CARD', 'TRANSFER'], true) && empty($bankId)) {
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
                                            \Filament\Notifications\Notification::make()
                                                ->title('Reservation tidak ditemukan.')
                                                ->warning()->send();
                                            DB::rollBack();
                                            return;
                                        }

                                        if (filled($record->actual_checkout)) {
                                            \Filament\Notifications\Notification::make()
                                                ->title('Guest ini sudah checkout.')
                                                ->warning()->send();
                                            DB::rollBack();
                                            return;
                                        }

                                        // 1) Payment
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
                                        // 2) Ledger
                                        $entryDate   = now()->toDateString();
                                        $description = 'Room Checkout #' . ($res->reservation_no ?? $res->id);
                                        $change      = max(0, $pay - $must);
                                        $amountInNet = min($pay, $must);

                                        if ($methodForLedger === 'cash' || empty($bankId)) {
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

                                        \Filament\Notifications\Notification::make()
                                            ->title('Gagal checkout.')
                                            ->body($e->getMessage())
                                            ->danger()->send();
                                    }
                                }),
                        ])
                            ->columns(6)          // ⬅️ semua tombol 1 baris (wrap jika sempit)
                            ->alignment('center') // ⬅️ rata tengah
                            ->columnSpan(12),
                    ]),
                ])
                ->columnSpanFull(),

        ]);
    }

    /**
     * Hitung amount split per guest (Amount Due sudah termasuk tax, dibagi rata per guest).
     * Ini SAMA dengan logika di blade view untuk "Amount to pay now" per guest.
     */
    private static function calculateSplitAmount(\App\Models\ReservationGuest $record): int
    {
        $arr = \App\Support\ReservationMath::subtotalGuestBill($record);
        return (int) ($arr['subtotal'] ?? 0);
    }

    private static function dueNowForGuest(\App\Models\ReservationGuest $rg): int
    {
        $agg = \App\Support\ReservationMath::aggregateGuestInfoFooter($rg);
        return (int) ($agg['to_pay_now'] ?? 0);
    }
}
