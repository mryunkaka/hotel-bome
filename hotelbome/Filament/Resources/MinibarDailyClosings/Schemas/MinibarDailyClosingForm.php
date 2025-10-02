<?php

namespace App\Filament\Resources\MinibarDailyClosings\Schemas;

use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as DBSchema;
use Illuminate\Support\Facades\Session;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class MinibarDailyClosingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Daily Closing')
                ->icon('heroicon-o-clipboard-document-check')
                ->columns(12)
                ->schema([

                    Hidden::make('hotel_id')
                        ->default(fn() => (int) Session::get('active_hotel_id'))
                        ->required(),

                    Hidden::make('closed_by')
                        ->default(fn() => Auth::id())
                        ->required(),

                    // ====== PERIODE (otomatis berdasar closing terakhir)
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
                        ->extraAttributes(['title' => 'Waktu closing diambil dari sistem; dipakai sebagai closing_end_at.']),

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
                        ->extraAttributes(['title' => 'Tanggal referensi tampilan; periode dihitung otomatis dari closing terakhir.']),

                    Grid::make(12)->schema([

                        TextInput::make('total_sales')
                            ->label('Total Sales')
                            ->numeric()
                            ->required()
                            ->default(0)
                            ->readOnly()
                            ->dehydrated(true)
                            ->columnSpan(3)
                            ->hintIcon('heroicon-o-currency-dollar'),

                        TextInput::make('total_cogs')
                            ->label('Total COGS')
                            ->numeric()
                            ->required()
                            ->default(0)
                            ->readOnly()
                            ->dehydrated(true)
                            ->columnSpan(3)
                            ->hintIcon('heroicon-o-cube'),

                        TextInput::make('total_profit')
                            ->label('Total Profit')
                            ->numeric()
                            ->required()
                            ->default(0)
                            ->readOnly()
                            ->dehydrated(true)
                            ->columnSpan(3)
                            ->hintIcon('heroicon-o-chart-bar'),

                        // OPSIONAL: kas fisik → variance = cash_actual - total_sales (hit. saat blur)
                        TextInput::make('cash_actual')
                            ->label('Cash (Actual)')
                            ->numeric()
                            ->minValue(0)
                            ->default(null)
                            ->dehydrated(true)
                            ->live(onBlur: true) // ⬅️ jangan live per keypress
                            ->afterStateUpdated(function ($state, Get $get, Set $set) {
                                $sales    = (int) ($get('total_sales') ?? 0);
                                $cash     = (int) ($state ?? 0);
                                $variance = $cash - $sales;

                                $set('variance_amount', $variance);
                                $set('is_balanced', $variance === 0);
                            })
                            ->extraAttributes([
                                'autocomplete' => 'off',
                                'inputmode'    => 'numeric',
                            ])
                            ->columnSpan(3)
                            ->hintIcon('heroicon-o-banknotes'),

                        // Variance MANUAL; auto-toggle Balanced? (hit. saat blur)
                        TextInput::make('variance_amount')
                            ->label('Variance')
                            ->numeric()
                            ->default(0)
                            ->dehydrated(true)
                            ->live(onBlur: true) // ⬅️ jangan live per keypress
                            ->afterStateUpdated(function ($state, Get $get, Set $set) {
                                $set('is_balanced', (int) ($state ?? 0) === 0);
                            })
                            ->columnSpan(3)
                            ->hintIcon('heroicon-o-adjustments-horizontal'),

                        Toggle::make('is_balanced')
                            ->label('Balanced?')
                            ->inline(false)
                            ->default(true)
                            ->disabled()
                            ->dehydrated(true)
                            ->columnSpan(3)
                            ->hintIcon('heroicon-o-check-circle'),

                        // Kunci data setelah closing final
                        Toggle::make('is_locked')
                            ->label('Lock (final)?')
                            ->default(false)
                            ->inline(false)
                            ->dehydrated(true)
                            ->columnSpan(3)
                            ->hintIcon('heroicon-o-lock-closed')
                            ->helperText('Saat tersimpan & terkunci, transaksi periode ini tidak akan dihitung lagi pada closing berikutnya.'),
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
     * Global Summary Mode (otomatis):
     * - Periode: [closing_start_at, closing_end_at] ditentukan dari closing terakhir (per hotel).
     *   closing_end_at = closed_at (sekarang), closing_start_at = last(closing_end_at) sebelumnya, atau awal data belum-terkunci.
     * - Sales/COGS: dari minibar_receipts (issued_at dalam periode), prefer yang belum terkunci (closing_id IS NULL) jika kolom tersedia.
     * - Profit = sales - cogs.
     * - Variance: manual (editable) atau auto dari cash_actual (jika diisi).
     * - Auto-toggle is_balanced bila variance == 0.
     */
    protected static function recalculate(Get $get, Set $set): void
    {
        $hotelId  = (int) ($get('hotel_id') ?? Session::get('active_hotel_id'));
        $closedAt = $get('closed_at') ? Carbon::parse($get('closed_at')) : now();

        // Cek dukungan kolom periode
        $hasStart = DBSchema::hasColumn('minibar_daily_closings', 'closing_start_at');
        $hasEnd   = DBSchema::hasColumn('minibar_daily_closings', 'closing_end_at');

        if ($hasStart && $hasEnd) {
            // === MODE PERIODE (Global Summary)
            $lastEnd = DB::table('minibar_daily_closings')
                ->where('hotel_id', $hotelId)
                ->max('closing_end_at');

            $start = $lastEnd ? Carbon::parse($lastEnd) : (function () use ($hotelId, $closedAt) {
                $first = DB::table('minibar_receipts')
                    ->where('hotel_id', $hotelId)
                    ->when(DBSchema::hasColumn('minibar_receipts', 'closing_id'), fn($q) => $q->whereNull('closing_id'))
                    ->min('issued_at');
                return $first ? Carbon::parse($first) : $closedAt->copy()->startOfDay();
            })();

            $end = $closedAt->copy();

            // Simpan ke state bila kolom ada
            $set('closing_start_at', $start->toDateTimeString());
            $set('closing_end_at',   $end->toDateTimeString());
        } else {
            // === MODE HARIAN (fallback – tidak pakai kolom periode)
            $date  = $get('closing_date') ? Carbon::parse($get('closing_date'))->toDateString() : today()->toDateString();
            $start = Carbon::parse($date)->startOfDay();
            $end   = Carbon::parse($date)->endOfDay();
        }

        // ===== Agregasi Sales/COGS dari receipts
        $receiptQuery = DB::table('minibar_receipts')
            ->where('hotel_id', $hotelId)
            ->whereBetween('issued_at', [$start, $end]);

        if (DBSchema::hasColumn('minibar_receipts', 'closing_id')) {
            $receiptQuery->whereNull('closing_id'); // hanya yang belum terkunci
        }

        $totalSales = (int) $receiptQuery->sum('total_amount');
        $totalCogs  = (int) $receiptQuery->sum('total_cogs');
        $profit     = $totalSales - $totalCogs;

        // Variance dari cash_actual bila ada; jika tidak, pakai nilai manual
        $cash = (int) ($get('cash_actual') ?? 0);
        if ($cash > 0) {
            $variance = $cash - $totalSales;
            $set('variance_amount', $variance);
            $set('is_balanced', abs($variance) === 0);
        } else {
            $variance = (int) ($get('variance_amount') ?? 0);
            $set('is_balanced', abs($variance) === 0);
        }

        // Set angka
        $set('total_sales',  $totalSales);
        $set('total_cogs',   $totalCogs);
        $set('total_profit', $profit);
    }


    /**
     * PENGUNCIAN DATA:
     * Panggil method ini SETELAH record disimpan dan is_locked = true.
     * Pastikan tabel transaksi punya kolom closing_id (nullable) agar bisa ditandai.
     *
     * Contoh panggilan (di Resource/Page afterSave):
     *   MinibarDailyClosingForm::lockPeriodData($record->id, $record->hotel_id, $record->closing_start_at, $record->closing_end_at);
     */
    public static function lockPeriodData(int $closingId, int $hotelId, string $startAt, string $endAt): void
    {
        $start = Carbon::parse($startAt);
        $end   = Carbon::parse($endAt);

        // Tandai receipt dalam periode sebagai terkunci jika kolom tersedia
        if (DBSchema::hasColumn('minibar_receipts', 'closing_id')) {
            DB::table('minibar_receipts')
                ->where('hotel_id', $hotelId)
                ->whereBetween('issued_at', [$start, $end])
                ->whereNull('closing_id')
                ->update(['closing_id' => $closingId]);
        }

        // (Opsional) jika ingin kunci pergerakan stok juga
        if (DBSchema::hasColumn('minibar_stock_movements', 'closing_id')) {
            DB::table('minibar_stock_movements')
                ->where('hotel_id', $hotelId)
                ->whereBetween('happened_at', [$start, $end])
                ->whereNull('closing_id')
                ->update(['closing_id' => $closingId]);
        }
    }
}
