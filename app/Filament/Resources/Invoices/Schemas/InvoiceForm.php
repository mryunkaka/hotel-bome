<?php

namespace App\Filament\Resources\Invoices\Schemas;

use Closure;
use App\Models\Booking;
use App\Models\TaxSetting;

use Filament\Support\RawJs;
use Filament\Schemas\Schema;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Illuminate\Database\Eloquent\Builder;
use Filament\Infolists\Components\TextEntry;
use Filament\Forms\Components\DateTimePicker;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class InvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        $recalc = fn(Get $get, Set $set) => self::recalcTotals($get, $set);
        return $schema->components([
            Hidden::make('hotel_id')
                ->default(fn() => session('active_hotel_id'))
                ->required()
                ->dehydrated(true),

            # ===== Header (Booking / Tax / Date) =====
            Section::make()
                ->schema([
                    Grid::make(36)->schema([
                        Select::make('booking_id')
                            ->label('Booking')
                            ->native(false)
                            ->searchable()
                            ->preload()
                            ->options(function (Get $get) {
                                $hotelId   = session('active_hotel_id');
                                $currentId = $get('booking_id'); // id booking yang sedang terpilih saat edit

                                $query = Booking::query()
                                    ->with(['room:id,room_no', 'guest:id,name'])
                                    ->where('hotel_id', $hotelId);

                                if ($currentId) {
                                    // tampilkan booking aktif ATAU booking yang sedang terpilih (meski checked_out)
                                    $query->where(function ($q) use ($currentId) {
                                        $q->where('status', '!=', 'checked_out')
                                            ->orWhere('id', $currentId);   // <-- pengganti orWhereKey()
                                    });
                                } else {
                                    $query->where('status', '!=', 'checked_out');
                                }

                                return $query->latest('check_in_at')
                                    ->get()
                                    ->mapWithKeys(fn($b) => [
                                        $b->id => 'Room ' . ($b->room->room_no ?? '-') . ' — ' . ($b->guest->name ?? 'Guest'),
                                    ])
                                    ->toArray();
                            })
                            ->required()
                            ->live(debounce: 400)
                            ->afterStateUpdated(function ($state, Get $get, \Filament\Schemas\Components\Utilities\Set $set) {
                                self::fillItemsFromBooking($state, $get, $set);
                                self::recalcTotals($get, $set);
                            })
                            ->columnSpan(12),

                        Select::make('tax_setting_id')
                            ->label('Select Tax')
                            ->native(false)
                            ->searchable()
                            ->preload()
                            ->options(function (Get $get) {
                                $hotelId   = session('active_hotel_id');
                                $currentId = $get('tax_setting_id');

                                $q = TaxSetting::query()->where('hotel_id', $hotelId);

                                if ($currentId) {
                                    $q->where(function ($qq) use ($currentId) {
                                        $qq->where('is_active', true)
                                            ->orWhere('id', $currentId);  // <-- pengganti orWhereKey()
                                    });
                                } else {
                                    $q->where('is_active', true);
                                }

                                return $q->orderBy('name')
                                    ->get()
                                    ->mapWithKeys(fn($t) => [$t->id => "{$t->name} ({$t->percent}%)"])
                                    ->toArray();
                            })
                            ->live(debounce: 300)
                            ->afterStateUpdated(fn(Get $get, \Filament\Schemas\Components\Utilities\Set $set) => self::recalcTotals($get, $set))
                            ->columnSpan(12),

                        DateTimePicker::make('date')
                            ->label('Date')
                            ->seconds(false)
                            ->default(now())
                            ->required()
                            ->columnSpan(12),
                    ]),
                ])
                ->columns(36)
                ->columnSpanFull(),

            # ===== Items (tombol tambah terlihat) =====
            Section::make('Items')
                ->schema([
                    Repeater::make('items')
                        ->relationship()           // invoice_items()
                        ->minItems(1)
                        ->collapsible(false)       // hilangkan toggle kecil
                        ->reorderable(false)
                        ->addActionLabel('Tambah Item')   // tampilkan tombol + label
                        // ->grid(36)
                        ->columns(36)
                        ->schema([
                            TextInput::make('item_name')
                                ->label('Item Name')
                                ->required()
                                ->columnSpan(7),

                            TextInput::make('description')
                                ->label('Item Description')
                                ->columnSpan(7),

                            TextInput::make('qty')
                                ->numeric()
                                ->minValue(0.01)
                                ->default(1)
                                ->required()
                                ->live(debounce: 400)
                                ->afterStateUpdated($recalc)
                                ->columnSpan(7),

                            TextInput::make('unit_price')
                                ->numeric()
                                ->minValue(0)
                                ->default(0)
                                ->prefix('Rp')
                                ->live(debounce: 500)
                                ->mask(RawJs::make('$money($input)'))
                                ->stripCharacters(',')
                                ->required()
                                ->afterStateUpdated($recalc)
                                ->columnSpan(7),

                            TextInput::make('amount')
                                ->label('Price')
                                ->prefix('Rp')
                                ->readOnly()
                                ->dehydrated(false)
                                ->formatStateUsing(fn($state) => $state === null ? '' : number_format((float) $state)) // 160,000
                                ->columnSpan(7),
                        ])
                        ->live()
                        ->afterStateUpdated($recalc)
                        ->afterStateHydrated($recalc)            // << tambah ini
                        ->defaultItems(0),
                ])
                ->columnSpanFull(),

            # ===== Totals =====
            Section::make('Totals')
                ->schema([
                    Grid::make(36)->schema([
                        TextEntry::make('subtotal_display')
                            ->label('SubTotal')
                            ->state(fn(Get $get) => 'Rp ' . number_format((float) ($get('subtotal') ?? 0), 0, ',', '.'))
                            ->live()
                            ->columnSpan(9),

                        TextEntry::make('tax_total_display')
                            ->label('TaxTotal')
                            ->state(fn(Get $get) => 'Rp ' . number_format((float) ($get('tax_total') ?? 0), 0, ',', '.'))
                            ->live()
                            ->columnSpan(9),

                        TextEntry::make('total_display')
                            ->label('Total')
                            ->state(fn(Get $get) => 'Rp ' . number_format((float) ($get('total') ?? 0), 0, ',', '.'))
                            ->live()
                            ->columnSpan(9),

                        Select::make('payment_method')
                            ->label('Payment Method')
                            ->native(false)
                            ->searchable()
                            ->preload()
                            ->options([
                                'cash'     => 'Cash',
                                'bank'     => 'Bank',
                                'transfer' => 'Transfer',
                                'card'     => 'Card',
                                'ewallet'  => 'E-Wallet',
                            ])
                            ->columnSpan(9),
                    ]),
                ])
                ->columns(12)
                ->columnSpanFull(),
        ]);
    }
    /**
     * Hitung subtotal, tax, total dari state form (runtime, realtime).
     * NOTE: tidak menyimpan ke DB; hanya set state form.
     */
    private static function recalcTotals(
        \Filament\Schemas\Components\Utilities\Get $get,
        \Filament\Schemas\Components\Utilities\Set $set
    ): void {
        // uang: buang semua non-digit (anggap IDR tanpa desimal)
        $parseMoney = static function ($v): float {
            if (is_numeric($v)) return (float) $v;
            $s = preg_replace('/\D+/', '', (string) $v); // hapus selain 0-9
            return $s === '' ? 0.0 : (float) $s;
        };
        // qty: izinkan desimal pakai titik/koma (kita normalisasi koma -> titik)
        $parseQty = static function ($v): float {
            if (is_numeric($v)) return (float) $v;
            $s = str_replace(',', '.', (string) $v);
            $s = preg_replace('/[^0-9.]/', '', $s);
            if ($s === '' || $s === '.') return 0.0;
            // jika user ketik banyak titik, buang yang berlebih
            $s = preg_replace('/\.(?=.*\.)/', '', $s);
            return (float) $s;
        };

        $items = $get('items') ?? [];
        $subtotal = 0.0;

        foreach ($items as $i => $row) {
            $qty   = $parseQty($row['qty'] ?? 0);
            $price = $parseMoney($row['unit_price'] ?? 0);

            // selalu hitung dari qty * unit_price
            $amount = round($qty * $price, 2);
            $subtotal += $amount;

            // sinkronkan kolom Price di UI
            $set("items.$i.amount", $amount);
        }

        $rate = 0.0;
        if ($tsId = $get('tax_setting_id')) {
            $rate = (float) (\App\Models\TaxSetting::query()->whereKey($tsId)->value('percent') ?? 0);
        }

        $tax   = round($subtotal * ($rate / 100), 2);
        $total = $subtotal + $tax;

        $set('subtotal', $subtotal);
        $set('tax_total', $tax);
        $set('total', $total);
    }

    private static function fillItemsFromBooking($bookingId, \Filament\Schemas\Components\Utilities\Get $get, \Filament\Schemas\Components\Utilities\Set $set): void
    {
        if (empty($bookingId)) {
            $set('items', []);
            return;
        }

        $booking = \App\Models\Booking::query()
            ->with('room:id,price,room_no')
            ->find($bookingId);

        if (! $booking) {
            $set('items', []);
            return;
        }

        $rate   = (float) ($booking->room->price ?? 0);
        $in     = optional($booking->check_in_at)->copy()->startOfDay() ?? now()->startOfDay();
        // Saat create, booking belum checkout → pakai now() sebagai batas
        $out    = now()->startOfDay();
        $nights = max(1, $in->diffInDays($out) ?: 1);

        $desc = sprintf(
            'Room %s | %d malam @ %s',
            $booking->room->room_no ?? '-',
            $nights,
            number_format($rate, 0, ',', '.')
        );

        $items = [[
            'item_name'  => 'Room',
            'description' => $desc,
            'qty'        => $nights,
            'unit_price' => $rate,
            'amount'     => round($nights * $rate, 2),
        ]];

        $set('items', $items);
    }
}
