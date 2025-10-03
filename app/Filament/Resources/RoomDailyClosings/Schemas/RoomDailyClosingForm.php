<?php

namespace App\Filament\Resources\RoomDailyClosings\Schemas;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as DBSchema;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Carbon;

class RoomDailyClosingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Daily Closing (Room)')
                ->icon('heroicon-o-clipboard-document-check')
                ->columns(12)
                ->schema([

                    // Scope & user
                    Hidden::make('hotel_id')
                        ->default(fn() => (int) Session::get('active_hotel_id'))
                        ->required(),

                    Hidden::make('closed_by')
                        ->default(fn() => Auth::id())
                        ->required(),

                    // Periode (otomatis berdasar closing terakhir)
                    Hidden::make('closing_start_at')->dehydrated(true),
                    Hidden::make('closing_end_at')->dehydrated(true),

                    DateTimePicker::make('closed_at')
                        ->label('Closed At')
                        ->default(fn() => now())
                        ->seconds(false)
                        ->required()
                        ->columnSpan(6)
                        ->readOnly()
                        ->reactive()
                        ->live()
                        ->afterStateUpdated(function ($state, Get $get, Set $set) {
                            self::recalculate($get, $set);
                        })
                        ->hintIcon('heroicon-o-information-circle')
                        ->extraAttributes(['title' => 'Dipakai sebagai closing_end_at / penanda akhir periode.']),

                    DatePicker::make('closing_date')
                        ->label('Closing Date')
                        ->default(fn() => today())
                        ->required()
                        ->reactive()
                        ->live()
                        ->afterStateUpdated(function ($state, Get $get, Set $set) {
                            self::recalculate($get, $set);
                        })
                        ->columnSpan(6)
                        ->hintIcon('heroicon-o-calendar-days')
                        ->extraAttributes(['title' => 'Tanggal referensi tampilan; periode dihitung otomatis.']),


                    Grid::make(12)->schema([

                        TextInput::make('total_room_revenue')
                            ->label('Room Revenue (Net)')
                            ->numeric()
                            ->required()
                            ->default(0)
                            ->readOnly()
                            ->dehydrated(true)
                            ->columnSpan(3)
                            ->extraAttributes(['title' => 'Total pendapatan kamar netto pada periode (total_payment − total_tax).'])
                            ->hintIcon('heroicon-o-currency-dollar'),

                        TextInput::make('total_tax')
                            ->label('Total Tax')
                            ->numeric()
                            ->required()
                            ->default(0)
                            ->readOnly()
                            ->dehydrated(true)
                            ->columnSpan(3)
                            ->extraAttributes(['title' => 'Total pajak kamar pada periode (jika dipisah).'])
                            ->hintIcon('heroicon-o-receipt-percent'),

                        TextInput::make('total_discount')
                            ->label('Total Discount')
                            ->numeric()
                            ->required()
                            ->default(0)
                            ->readOnly()
                            ->dehydrated(true)
                            ->columnSpan(3)
                            ->extraAttributes(['title' => 'Total diskon kamar pada periode.'])
                            ->hintIcon('heroicon-o-tag'),

                        TextInput::make('total_deposit')
                            ->label('Total Deposit')
                            ->numeric()
                            ->required()
                            ->default(0)
                            ->readOnly()
                            ->dehydrated(true)
                            ->columnSpan(3)
                            ->extraAttributes(['title' => 'Total deposit yang diterima pada periode (jika dilacak).'])
                            ->hintIcon('heroicon-o-banknotes'),

                        TextInput::make('total_refund')
                            ->label('Total Refund')
                            ->numeric()
                            ->required()
                            ->default(0)
                            ->readOnly()
                            ->dehydrated(true)
                            ->columnSpan(3)
                            ->extraAttributes(['title' => 'Total pengembalian dana/refund (termasuk refund deposit) pada periode.'])
                            ->hintIcon('heroicon-o-arrow-uturn-left'),

                        TextInput::make('total_payment')
                            ->label('Total Payment')
                            ->numeric()
                            ->required()
                            ->default(0)
                            ->readOnly()
                            ->dehydrated(true)
                            ->columnSpan(3)
                            ->extraAttributes(['title' => 'Total semua pembayaran tamu pada periode (semua metode, tidak termasuk refund).'])
                            ->hintIcon('heroicon-o-credit-card'),

                        // Kas fisik → variance = cash_actual - total_cash_only (hitung saat blur)
                        TextInput::make('cash_actual')
                            ->label('Cash (Actual)')
                            ->numeric()
                            ->minValue(0)
                            ->default(null)
                            ->dehydrated(true)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, Get $get, Set $set) {
                                $cash = (float) ($state ?? 0);
                                $totalCashOnly = (float) ($get('total_cash_only') ?? 0);
                                $variance = $cash - $totalCashOnly;

                                $set('variance_amount', $variance);
                                $set('is_balanced', abs($variance) == 0.0);
                            })
                            ->extraAttributes([
                                'autocomplete' => 'off',
                                'inputmode'    => 'numeric',
                            ])
                            ->columnSpan(3)
                            ->extraAttributes(['title' => 'Uang tunai fisik yang dihitung di laci; dipakai untuk hitung selisih (variance).'])
                            ->hintIcon('heroicon-o-banknotes'),

                        TextInput::make('variance_amount')
                            ->label('Variance')
                            ->readOnly()
                            ->numeric()
                            ->default(0)
                            ->dehydrated(true)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, Get $get, Set $set) {
                                $set('is_balanced', (float) ($state ?? 0) == 0.0);
                            })
                            ->columnSpan(3)
                            ->extraAttributes(['title' => 'Selisih kas: Cash (Actual) dikurangi total penerimaan tunai periode. 0 berarti pas.'])
                            ->hintIcon('heroicon-o-adjustments-horizontal'),

                        // (Opsional) tampilkan total cash only untuk debug kasir
                        TextInput::make('total_cash_only')
                            ->label('Total Cash Only')
                            ->numeric()
                            ->default(0)
                            ->readOnly()
                            ->dehydrated(true)
                            ->columnSpan(3)
                            ->hintIcon('heroicon-o-banknotes')
                            ->extraAttributes(['title' => 'Total penerimaan metode cash pada periode; dasar perbandingan dengan Cash (Actual).']),

                        Toggle::make('is_balanced')
                            ->label('Balanced?')
                            ->inline(false)
                            ->default(true)
                            ->disabled()
                            ->dehydrated(true)
                            ->columnSpan(3)
                            ->helperText('Status keseimbangan kas/Selisih; otomatis ON saat variance = 0.')
                            ->hintIcon('heroicon-o-check-circle'),

                        Toggle::make('is_locked')
                            ->label('Lock (final)?')
                            ->default(false)
                            ->inline(false)
                            ->dehydrated(true)
                            ->columnSpan(3)
                            ->hintIcon('heroicon-o-lock-closed')
                            ->extraAttributes(['title' => 'Kunci closing: saat ON, transaksi periode ini tidak dihitung lagi di closing berikutnya.']),

                    ])->columnSpan(12),

                    Textarea::make('notes')
                        ->label('Notes')
                        ->rows(3)
                        ->placeholder('Catatan proses closing...')
                        ->columnSpanFull()
                        ->hintIcon('heroicon-o-pencil-square'),
                ])
                ->columnSpanFull()
                ->afterStateHydrated(function (Get $get, Set $set) {
                    self::recalculate($get, $set);
                }),
        ]);
    }

    /**
     * Hitung otomatis periode & total (Room) dari payments.
     * - Periode: [closing_start_at, closing_end_at] berdasar closing terakhir per hotel.
     *   closing_end_at = closed_at; start = last(closing_end_at) atau earliest payment_date/created_at.
     * - Effective amount: CASE WHEN actual_amount > 0 THEN actual_amount ELSE amount END
     * - total_payment: sum effective (exclude is_deposit_refund = true)
     * - total_refund : sum effective (is_deposit_refund = true)
     * - total_cash_only: sum effective dengan method='cash'
     */
    protected static function recalculate(Get $get, Set $set): void
    {
        $hotelId  = (int) ($get('hotel_id') ?? Session::get('active_hotel_id'));
        $closedAt = $get('closed_at') ? Carbon::parse($get('closed_at')) : now();

        // ===== Tentukan periode =====
        $hasStart = DBSchema::hasColumn('room_daily_closings', 'closing_start_at');
        $hasEnd   = DBSchema::hasColumn('room_daily_closings', 'closing_end_at');

        if ($hasStart && $hasEnd) {
            $lastEnd = DB::table('room_daily_closings')
                ->where('hotel_id', $hotelId)
                ->max('closing_end_at');

            $start = $lastEnd ? Carbon::parse($lastEnd) : (function () use ($hotelId, $closedAt) {
                if (DBSchema::hasTable('payments')) {
                    if (DBSchema::hasColumn('payments', 'payment_date')) {
                        $first = DB::table('payments')->where('hotel_id', $hotelId)->min('payment_date');
                        if ($first) return Carbon::parse($first);
                    }
                    $first = DB::table('payments')->where('hotel_id', $hotelId)->min('created_at');
                    if ($first) return Carbon::parse($first);
                }
                return $closedAt->copy()->startOfDay();
            })();

            $end = $closedAt->copy();

            $set('closing_start_at', $start->toDateTimeString());
            $set('closing_end_at',   $end->toDateTimeString());
        } else {
            // Mode harian (fallback)
            $date  = $get('closing_date') ? Carbon::parse($get('closing_date'))->toDateString() : today()->toDateString();
            $start = Carbon::parse($date)->startOfDay();
            $end   = Carbon::parse($date)->endOfDay();
        }

        // ===== Agregasi dari payments =====
        $totalPayment   = 0.0;
        $totalTax       = 0.0; // tidak ada kolom tax di payments
        $totalDiscount  = 0.0; // tidak ada kolom discount di payments
        $totalDeposit   = 0.0; // belum ada flag deposit masuk
        $totalRefund    = 0.0;
        $totalCashOnly  = 0.0;

        if (DBSchema::hasTable('payments')) {
            $base = DB::table('payments')->where('hotel_id', $hotelId);

            // Range tanggal: payment_date -> fallback created_at
            $colDate = DBSchema::hasColumn('payments', 'payment_date') ? 'payment_date' : 'created_at';
            $base->whereBetween($colDate, [$start, $end]);

            // Effective expression
            $eff = "CASE WHEN actual_amount IS NOT NULL AND actual_amount > 0 THEN actual_amount ELSE amount END";

            // total_payment: exclude deposit_refund
            $totalPayment = (float) (clone $base)
                ->where(function ($q) {
                    $q->where('is_deposit_refund', false)
                        ->orWhereNull('is_deposit_refund');
                })
                ->selectRaw("COALESCE(SUM($eff),0) as s")
                ->value('s');

            // total_refund: only deposit refund
            $totalRefund = (float) (clone $base)
                ->where('is_deposit_refund', true)
                ->selectRaw("COALESCE(SUM($eff),0) as s")
                ->value('s');

            // total_cash_only: method = 'cash'
            if (DBSchema::hasColumn('payments', 'method')) {
                $totalCashOnly = (float) (clone $base)
                    ->where('method', 'cash')
                    ->where(function ($q) {
                        $q->where('is_deposit_refund', false)
                            ->orWhereNull('is_deposit_refund');
                    })
                    ->selectRaw("COALESCE(SUM($eff),0) as s")
                    ->value('s');
            }
        }

        // Room revenue (net) = total_payment - total_tax (saat ini = total_payment)
        $roomRevenue = max(0.0, $totalPayment - $totalTax);

        // Variance: bandingkan cash fisik vs total cash only
        $cash = (float) ($get('cash_actual') ?? 0);
        if ($cash > 0) {
            $variance = $cash - $totalCashOnly;
            $set('variance_amount', $variance);
            $set('is_balanced', abs($variance) == 0.0);
        } else {
            $variance = (float) ($get('variance_amount') ?? 0);
            $set('is_balanced', abs($variance) == 0.0);
        }

        // Set angka ke state
        $set('total_payment',       $totalPayment);
        $set('total_tax',           $totalTax);
        $set('total_discount',      $totalDiscount);
        $set('total_deposit',       $totalDeposit);
        $set('total_refund',        $totalRefund);
        $set('total_room_revenue',  $roomRevenue);
        $set('total_cash_only',     $totalCashOnly);
    }

    /**
     * Penguncian data: dipanggil setelah record tersimpan & is_locked = true.
     * Menandai payments dalam periode agar tidak terhitung lagi (jika kolom closing_id tersedia).
     */
    public static function lockPeriodData(int $closingId, int $hotelId, string $startAt, string $endAt): void
    {
        $start = Carbon::parse($startAt);
        $end   = Carbon::parse($endAt);

        if (DBSchema::hasTable('payments') && DBSchema::hasColumn('payments', 'closing_id')) {
            $q = DB::table('payments')->where('hotel_id', $hotelId);

            $colDate = DBSchema::hasColumn('payments', 'payment_date') ? 'payment_date' : 'created_at';
            $q->whereBetween($colDate, [$start, $end]);

            $q->whereNull('closing_id')->update(['closing_id' => $closingId]);
        }

        // (Opsional) Freeze GL pada rentang tanggal tersebut
        if (
            DBSchema::hasTable('ledger_accounts')
            && DBSchema::hasColumn('ledger_accounts', 'date')
            && DBSchema::hasColumn('ledger_accounts', 'is_posted')
        ) {
            DB::table('ledger_accounts')
                ->where('hotel_id', $hotelId)
                ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                ->where('is_posted', false)
                ->update(['is_posted' => true, 'posted_at' => now(), 'posted_by' => Auth::id()]);
        }
    }
}
