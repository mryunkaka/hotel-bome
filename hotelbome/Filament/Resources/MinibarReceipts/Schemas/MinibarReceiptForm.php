<?php

namespace App\Filament\Resources\MinibarReceipts\Schemas;

use App\Models\MinibarItem;
use Filament\Schemas\Schema;
use App\Models\ReservationGuest;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Illuminate\Support\Facades\Session;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\DateTimePicker;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class MinibarReceiptForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            // =======================
            // Section: Receipt Info
            // =======================
            Section::make('Receipt Info')
                ->description('General minibar transaction details: date/time, payment method, and (optional) guest.')
                ->icon('heroicon-o-clipboard-document')
                ->columns(12)
                ->schema([
                    Grid::make(12)->schema([
                        Hidden::make('hotel_id')
                            ->default(fn() => Session::get('active_hotel_id'))
                            ->required(),

                        DateTimePicker::make('issued_at')
                            ->label('Date & Time')
                            ->seconds(false)
                            ->displayFormat('d/m/Y H:i')
                            ->default(fn() => now())
                            ->required()
                            ->columnSpan(6),

                        Select::make('method')
                            ->label('Payment')
                            ->options([
                                'cash'           => 'Cash',
                                'transfer'       => 'Bank Transfer',
                                'edc'            => 'EDC / Card',
                                'charge_to_room' => 'Charge to Room',
                            ])
                            ->default('cash')
                            ->required()
                            ->columnSpan(6),

                        Select::make('reservation_guest_id')
                            ->label('Guest')
                            ->placeholder('Select')
                            ->options(function () {
                                return ReservationGuest::query()
                                    ->with(['guest', 'room', 'reservation'])
                                    ->where('hotel_id', Session::get('active_hotel_id'))
                                    ->whereNotNull('actual_checkin')
                                    ->whereNull('actual_checkout')
                                    ->orderByDesc('actual_checkin')
                                    ->get()
                                    ->mapWithKeys(function ($rg) {
                                        $guestName = $rg->guest?->name ?? '(Unknown Guest)';
                                        $roomNo    = $rg->room?->room_no ?? '-';
                                        $resCode   = $rg->reservation?->reservation_no ?? '-';
                                        return [
                                            $rg->id => sprintf('%s — %s (Room %s)', $guestName, $resCode, $roomNo),
                                        ];
                                    })
                                    ->toArray();
                            })
                            ->searchable()
                            ->preload()
                            ->columnSpan(12),
                    ])->columnSpanFull(),

                    Textarea::make('notes')
                        ->label('Notes')
                        ->placeholder('Optional notes for this transaction…')
                        ->rows(3)
                        ->columnSpanFull(),
                ])
                ->collapsible(),

            // =======================
            // Section: Sold Items
            // =======================
            Section::make('Sold Items')
                ->description('Minibar items sold or charged to the guest.')
                ->icon('heroicon-o-shopping-bag')
                ->columns(1)
                ->schema([
                    \Filament\Forms\Components\Repeater::make('items')
                        ->label(' ')
                        ->hiddenLabel()
                        // ->relationship('items') // JANGAN pakai relationship, kita simpan manual di Page
                        ->minItems(1)
                        ->addActionLabel('Add Item')
                        ->columns(12)
                        ->schema([
                            \Filament\Forms\Components\Select::make('item_id')
                                ->label('')
                                ->options(function (): array {
                                    return \App\Models\MinibarItem::query()
                                        ->where('hotel_id', \Illuminate\Support\Facades\Session::get('active_hotel_id'))
                                        ->where('is_active', true)
                                        ->where('current_stock', '>', 0)
                                        ->orderBy('name')
                                        ->limit(500)
                                        ->get()
                                        ->mapWithKeys(fn($i) => [
                                            $i->id => sprintf(
                                                '%s — %s pcs',
                                                $i->name,
                                                number_format($i->current_stock, 0, ',', '.')
                                            ),
                                        ])
                                        ->toArray();
                                })
                                ->searchable()
                                ->preload()
                                ->required()
                                ->reactive()
                                ->afterStateUpdated(function ($state, \Filament\Schemas\Components\Utilities\Get $get, \Filament\Schemas\Components\Utilities\Set $set): void {
                                    $item  = \App\Models\MinibarItem::find($state);
                                    $price = $item->default_sale_price ?? ($item->default_selling_price ?? 0);
                                    $cost  = $item->default_cost_price ?? 0;

                                    $set('unit_price', $price);
                                    $set('unit_cost',  $cost);

                                    $qty = (int) ($get('quantity') ?? 0);
                                    $set('line_total', (float)$price * $qty);
                                    $set('line_cogs',  (float)$cost  * $qty);
                                })
                                ->columnSpan(12),

                            \Filament\Forms\Components\TextInput::make('quantity')
                                ->label('Qty')
                                ->numeric()
                                ->minValue(1)
                                ->default(1)
                                ->required()
                                ->reactive()
                                ->afterStateUpdated(function ($state, $get, $set): void {
                                    $qty   = (int) $state;
                                    $price = (float) ($get('unit_price') ?? 0);
                                    $cost  = (float) ($get('unit_cost')  ?? 0);

                                    $set('line_total', $price * $qty);
                                    $set('line_cogs',  $cost  * $qty);
                                })
                                ->columnSpan(6),

                            \Filament\Forms\Components\TextInput::make('unit_price')
                                ->label('Harga / item')
                                ->numeric()
                                ->required()
                                ->disabled()
                                ->dehydrated(true)   // penting: ikut terkirim
                                ->default(0)
                                ->reactive()
                                ->afterStateUpdated(function ($state, $get, $set): void {
                                    $qty = (int) ($get('quantity') ?? 0);
                                    $set('line_total', (float)$state * $qty);
                                })
                                ->columnSpan(6),

                            \Filament\Forms\Components\Hidden::make('unit_cost')->dehydrated(true)->default(0),
                            \Filament\Forms\Components\Hidden::make('line_total')->dehydrated(true)->default(0),
                            \Filament\Forms\Components\Hidden::make('line_cogs')->dehydrated(true)->default(0),
                        ]),
                    // Tidak perlu mutateRelationshipDataBeforeSaveUsing karena tidak pakai relationship
                ])
                ->collapsible(),
        ]);
    }
}
