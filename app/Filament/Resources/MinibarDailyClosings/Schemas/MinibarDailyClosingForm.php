<?php

namespace App\Filament\Resources\MinibarDailyClosings\Schemas;

use Filament\Schemas\Schema;
use App\Models\MinibarReceipt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

                    DateTimePicker::make('closed_at')
                        ->label('Closed At')
                        ->default(fn() => now())
                        ->seconds(false)
                        ->required()
                        ->columnSpan(6)
                        ->readOnly()
                        ->hintIcon('heroicon-o-information-circle')
                        ->extraAttributes(['title' => 'Tanggal dan waktu closing otomatis diambil dari sistem.']),

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
                        ->extraAttributes(['title' => 'Tanggal yang menjadi dasar perhitungan semua transaksi minibar.']),

                    Grid::make(12)->schema([

                        TextInput::make('total_sales')
                            ->label('Total Sales')
                            ->numeric()
                            ->required()
                            ->default(0)
                            ->readOnly()
                            ->dehydrated(true)
                            ->columnSpan(4)
                            ->hintIcon('heroicon-o-currency-dollar')
                            ->extraAttributes(['title' => 'Jumlah total penjualan minibar pada tanggal closing ini.']),

                        TextInput::make('total_cogs')
                            ->label('Total COGS')
                            ->numeric()
                            ->required()
                            ->default(0)
                            ->readOnly()
                            ->dehydrated(true)
                            ->columnSpan(4)
                            ->hintIcon('heroicon-o-cube')
                            ->extraAttributes(['title' => 'Total biaya pokok barang (Cost of Goods Sold) untuk semua item minibar yang terjual.']),

                        TextInput::make('total_restock_cost')
                            ->label('Total Restock Cost')
                            ->numeric()
                            ->required()
                            ->default(0)
                            ->readOnly()
                            ->dehydrated(true)
                            ->columnSpan(4)
                            ->hintIcon('heroicon-o-arrow-path')
                            ->extraAttributes(['title' => 'Otomatis. Dijumlah dari restock (quantity × unit_cost) pada tanggal closing.']),

                        TextInput::make('total_profit')
                            ->label('Total Profit')
                            ->numeric()
                            ->required()
                            ->default(0)
                            ->readOnly()
                            ->dehydrated(true)
                            ->columnSpan(4)
                            ->hintIcon('heroicon-o-chart-bar')
                            ->extraAttributes(['title' => 'Keuntungan bersih: Total Sales dikurangi Total COGS dan biaya restock.']),

                        TextInput::make('variance_amount')
                            ->label('Variance')
                            ->numeric()
                            ->required()
                            ->default(0)
                            ->readOnly()
                            ->dehydrated(true)
                            ->columnSpan(4)
                            ->hintIcon('heroicon-o-adjustments-horizontal')
                            ->extraAttributes(['title' => 'Otomatis. Saat ini 0 (silakan ubah logikanya di recalculate() bila ada metode lain).']),

                        Toggle::make('is_balanced')
                            ->label('Balanced?')
                            ->inline(false)
                            ->default(true)
                            ->disabled()
                            ->dehydrated(true)
                            ->columnSpan(4)
                            ->hintIcon('heroicon-o-check-circle')
                            ->extraAttributes(['title' => 'Menunjukkan apakah hasil closing seimbang (variance = 0).']),
                    ])->columnSpan(12),

                    Textarea::make('notes')
                        ->label('Notes')
                        ->rows(3)
                        ->placeholder('Tuliskan catatan atau temuan selama proses closing...')
                        ->columnSpanFull()
                        ->hintIcon('heroicon-o-pencil-square')
                        ->extraAttributes(['title' => 'Tambahkan keterangan tambahan atau observasi terkait closing harian.']),
                ])
                ->columnSpanFull()
                ->afterStateHydrated(function (Get $get, Set $set) {
                    self::recalculate($get, $set);
                }),
        ]);
    }

    /**
     * Hitung semua angka berdasarkan closing_date & hotel_id.
     * - Sales/COGS: dari minibar_receipts (issued_at di tanggal tsb)
     * - Restock cost: dari minibar_stock_movements (movement_type = 'restock') → sum(quantity * unit_cost)
     */
    protected static function recalculate(Get $get, Set $set): void
    {
        $hotelId = (int) ($get('hotel_id') ?? Session::get('active_hotel_id'));
        $date    = $get('closing_date')
            ? Carbon::parse($get('closing_date'))->toDateString()
            : today()->toDateString();

        $start = Carbon::parse($date)->startOfDay();
        $end   = Carbon::parse($date)->endOfDay();

        // Sales & COGS dari receipts
        $receiptQuery = MinibarReceipt::query()
            ->where('hotel_id', $hotelId)
            ->whereBetween('issued_at', [$start, $end]);

        $totalSales = (float) $receiptQuery->sum('total_amount');
        $totalCogs  = (float) $receiptQuery->sum('total_cogs');

        // Restock cost dari stock movements
        // (gunakan DB::table agar tidak tergantung Model khusus)
        $restock = (float) DB::table('minibar_stock_movements')
            ->where('hotel_id', $hotelId)
            ->where('movement_type', 'restock')
            ->whereBetween('happened_at', [$start, $end])
            ->selectRaw('COALESCE(SUM(quantity * unit_cost), 0) as total')
            ->value('total');

        // Profit & variance
        $profit   = $totalSales - $totalCogs - $restock;
        $variance = 0.0;
        $balanced = abs($variance) < 0.00001;

        // Set ke state (disimpan karena dehydrated)
        $set('total_sales', number_format($totalSales, 0, '.', ''));
        $set('total_cogs', number_format($totalCogs, 0, '.', ''));
        $set('total_restock_cost', number_format($restock, 0, '.', ''));
        $set('total_profit', number_format($profit, 0, '.', ''));
        $set('variance_amount', number_format($variance, 0, '.', ''));
        $set('is_balanced', $balanced);
    }
}
