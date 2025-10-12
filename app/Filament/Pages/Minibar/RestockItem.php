<?php

namespace App\Filament\Pages\Minibar;

use Filament\Pages\Page;
use App\Models\MinibarItem;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use App\Models\MinibarVendor;
use Illuminate\Support\Facades\DB;
use App\Models\MinibarStockMovement;

// Pakai komponen Forms DI DALAM schema:
use Illuminate\Support\Facades\Auth;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Illuminate\Support\Facades\Session;

use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Forms\Components\DateTimePicker;
use Illuminate\Validation\ValidationException;
use App\Filament\Traits\ForbidReceptionistResource;

class RestockItem extends Page
{
    use ForbidReceptionistResource;
    /** Sidebar nav */
    protected static string|\UnitEnum|null   $navigationGroup = 'Minibar';
    protected static string|\BackedEnum|null $navigationIcon  = 'heroicon-o-arrow-up-on-square';
    protected static ?int $navigationSort    = 15;
    protected static ?string $navigationLabel = 'Restock Item';
    protected static ?string $title           = 'Restock Item';

    /**
     * STATE yang diikat ke schema via ->statePath('data')
     * (Jangan pakai fillSchema / getSchemaState)
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->data ??= [];

        $this->data['hotel_id']     = $this->data['hotel_id']     ?? Session::get('active_hotel_id');
        $this->data['happened_at']  = $this->data['happened_at']  ?? now()->toDateTimeString(); // ✅ string
        $this->data['vendor_id']    = $this->data['vendor_id']    ?? null;
        $this->data['reference_no'] = $this->data['reference_no'] ?? null;
        $this->data['notes']        = $this->data['notes']        ?? null;
        $this->data['items']        = $this->data['items']        ?? [];
    }

    /** Konten halaman: pakai Schemas */
    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('hotel_id')
                    ->default(fn() => Session::get('active_hotel_id'))
                    ->required(),

                DateTimePicker::make('happened_at')
                    ->label('Tanggal & Waktu')
                    ->seconds(false)
                    ->displayFormat('d/m/Y H:i')
                    ->default(fn() => now()) // fallback
                    ->afterStateHydrated(function ($state, callable $set) {
                        if (blank($state)) {
                            $set('happened_at', now()->toDateTimeString()); // ✅ paksa isi saat render
                        }
                    })
                    ->disabled()
                    ->required(),

                Select::make('vendor_id')
                    ->label('Vendor')
                    ->options(fn() => MinibarVendor::query()
                        ->where('hotel_id', Session::get('active_hotel_id'))
                        ->orderBy('name')
                        ->pluck('name', 'id'))
                    ->searchable()
                    ->preload(),

                Textarea::make('notes')
                    ->label('Catatan')
                    ->rows(2)
                    ->columnSpanFull(),

                Repeater::make('items')
                    ->label('Daftar Item Restock')
                    ->minItems(1)
                    ->columns(12)
                    ->schema([
                        Select::make('item_id')
                            ->label('Item')
                            ->options(fn() => MinibarItem::query()
                                ->where('hotel_id', Session::get('active_hotel_id'))
                                ->orderBy('name')
                                ->pluck('name', 'id'))
                            ->searchable()
                            ->preload()
                            ->columnSpan(5)
                            ->required()
                            ->live() // agar bisa ambil qty terkini dan update subtotal saat pilih item
                            ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                if (! $state) return;

                                $item = MinibarItem::find($state);
                                if ($item) {
                                    $set('unit_cost', (string) $item->default_cost_price);

                                    // gunakan qty terbaru (jangan hardcode 1)
                                    $qty  = (float) ($get('quantity') ?? 1);
                                    $cost = (float) $item->default_cost_price;

                                    $set('line_total', number_format($qty * $cost, 2, '.', ''));
                                }
                            }),

                        TextInput::make('quantity')
                            ->label('Qty')
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->required()
                            ->columnSpan(2)
                            ->live(debounce: 200) // ← per-keystroke (tanpa blur)
                            ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                $qty  = (float) ($state ?? 0);
                                $cost = (float) ($get('unit_cost') ?? 0);
                                $set('line_total', number_format($qty * $cost, 2, '.', ''));
                            }),

                        TextInput::make('unit_cost')
                            ->label('Harga Beli / Unit')
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->columnSpan(3)
                            ->live(debounce: 200) // ← per-keystroke (tanpa blur)
                            ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                $qty  = (float) ($get('quantity') ?? 0);
                                $cost = (float) ($state ?? 0);
                                $set('line_total', number_format($qty * $cost, 2, '.', ''));
                            }),

                        TextInput::make('line_total')
                            ->label('Subtotal')
                            ->dehydrated(false)   // tidak disimpan ke payload
                            ->disabled()
                            ->columnSpan(2),
                    ]),
            ])
            ->statePath('data'); // <— ikat ke $this->data
    }

    /** Tombol aksi footer halaman */
    protected function getActions(): array
    {
        return [
            Action::make('save')
                ->label('Simpan Restock')
                ->action('submit')
                ->keyBindings(['mod+s'])
                ->color('primary')
                ->icon('heroicon-o-check'),

            Action::make('reset')
                ->label('Reset')
                ->action('resetForm')
                ->color('gray')
                ->icon('heroicon-o-arrow-path'),
        ];
    }

    /** Simpan */
    public function submit(): void
    {
        $data = $this->data; // <— ambil dari properti yang terikat schema

        if (empty($data['items'] ?? [])) {
            throw ValidationException::withMessages([
                'data.items' => 'Tambahkan minimal 1 item restock.',
            ]);
        }

        DB::transaction(function () use ($data) {
            $hotelId = $data['hotel_id'] ?? Session::get('active_hotel_id');

            foreach ($data['items'] as $row) {
                $itemId   = (int) ($row['item_id'] ?? 0);
                $qty      = (int) ($row['quantity'] ?? 0);
                $unitCost = (float) ($row['unit_cost'] ?? 0);

                if ($itemId <= 0 || $qty <= 0) continue;

                MinibarStockMovement::create([
                    'hotel_id'             => $hotelId,
                    'item_id'              => $itemId,
                    'movement_type'        => MinibarStockMovement::TYPE_RESTOCK,
                    'quantity'             => $qty,
                    'unit_cost'            => $unitCost,
                    'unit_price'           => null,
                    'vendor_id'            => $data['vendor_id'] ?? null,
                    'receipt_id'           => null,
                    'reservation_guest_id' => null,
                    'reference_no'         => $data['reference_no'] ?? 'RSK-' . now()->format('YmdHis'),
                    'performed_by'         => Auth::id(),
                    'notes'                => $data['notes'] ?? null,
                    'happened_at'          => $data['happened_at'] ?? now(),
                ]);

                MinibarItem::whereKey($itemId)->increment('current_stock', $qty);
            }
        });

        Notification::make()
            ->title('Restock berhasil disimpan.')
            ->success()
            ->send();

        $this->resetForm();
    }

    public function resetForm(): void
    {
        $this->data = [
            'hotel_id'     => Session::get('active_hotel_id'),
            'happened_at'  => now(),
            'vendor_id'    => null,
            'reference_no' => null,
            'notes'        => null,
            'items'        => [],
        ];
    }
}
