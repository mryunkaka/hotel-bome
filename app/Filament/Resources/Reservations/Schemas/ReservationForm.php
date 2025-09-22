<?php

namespace App\Filament\Resources\Reservations\Schemas;

use App\Models\Room;
use App\Models\Guest;
use App\Models\Reservation;
use Filament\Support\RawJs;
use Illuminate\Support\Arr;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use App\Models\ReservationGuest;
use Illuminate\Support\Facades\DB;
use Filament\Forms\Components\Radio;
use Illuminate\Support\Facades\Auth;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Illuminate\Support\Facades\Session;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Illuminate\Database\Eloquent\Builder;
use Filament\Forms\Components\DateTimePicker;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Forms\Components\Select as FSelect;
use App\Filament\Resources\ReservationGuests\ReservationGuestResource;

class ReservationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            // ===========================
            // Reservation Info (sederhana)
            // ===========================
            Section::make('Reservation Info')
                ->schema([
                    Grid::make(12)->schema([
                        Hidden::make('hotel_id')
                            ->default(fn() => Session::get('active_hotel_id')),

                        Hidden::make('reservation_no')
                            ->default(function () {
                                $hid = (int) (Session::get('active_hotel_id') ?? 0);
                                return Reservation::generateReservationNo($hid ?: null);
                            }),

                        Hidden::make('created_by')
                            ->default(fn() => Auth::id()),

                        Hidden::make('entry_date')
                            ->default(fn($record) => $record?->entry_date ?? now()),

                        Select::make('option')
                            ->label('Option')
                            ->options([
                                'WALKIN'     => 'WALK-IN',
                                'GOVERNMENT' => 'GOVERNMENT',
                                'CORPORATE'  => 'CORPORATE',
                                'TRAVEL'     => 'TRAVEL',
                                'OTA'        => 'OTA',
                                'WEEKLY'     => 'WEEKLY',
                                'MONTHLY'    => 'MONTHLY',
                            ])
                            ->default('WALKIN')
                            ->required()
                            ->columnSpan(2),

                        Select::make('method')
                            ->label('Method')
                            ->options([
                                'PHONE'    => 'Phone',
                                'WA/SMS'   => 'WA / SMS',
                                'EMAIL'    => 'Email',
                                'LETTER'   => 'Letter',
                                'PERSONAL' => 'Personal',
                                'OTA'      => 'OTA',
                                'OTHER'    => 'Other',
                            ])
                            ->default('PERSONAL')
                            ->required()
                            ->columnSpan(2),

                        Select::make('status')
                            ->label('Status')
                            ->options([
                                'CONFIRM'   => 'Confirm',
                                'TENTATIVE' => 'Tentative',
                                'CANCELLED' => 'Cancelled',
                            ])
                            ->default('CONFIRM')
                            ->required()
                            ->columnSpan(2),

                        Radio::make('deposit_type')
                            ->label('Deposit Type')
                            ->options([
                                'DP'   => 'Room Deposit',
                                'CARD' => 'Deposit Card',
                            ])
                            ->default('DP')
                            ->columnSpan(3),

                        TextInput::make('deposit')
                            ->label('Deposit')
                            ->mask(RawJs::make('$money($input)'))
                            ->stripCharacters(',')
                            ->numeric()
                            ->default(0)
                            ->columnSpan(3),
                    ]),
                ])
                ->columnSpanFull(),

            // ===========================
            // Information (readonly ringkas)
            // ===========================
            Section::make('Information')
                ->schema([
                    Grid::make(12)->schema([
                        Select::make('created_by')
                            ->label('Entry By')
                            ->relationship('creator', 'name')   // relasi di model: creator()
                            ->default(fn() => Auth::id())      // agar saat Create tampil nama user aktif
                            ->disabled()
                            ->dehydrated(false)
                            ->columnSpan(6),

                        DateTimePicker::make('entry_date')
                            ->label('Entry Date')
                            ->disabled()
                            ->dehydrated(false)
                            ->columnSpan(6),

                        DateTimePicker::make('expected_arrival')
                            ->label('Arrival')
                            ->required()
                            ->default(fn() => now()->setTime(12, 0)) // 12:00 siang
                            ->seconds(false)
                            ->columnSpan(5),

                        TextInput::make('nights')
                            ->label('Nights')
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->required()
                            ->columnSpan(3),

                        // === Tax (global di reservations) dipindah ke atas ===
                        Select::make('id_tax')
                            ->label('Tax')
                            ->placeholder('Select')
                            ->native(true)
                            ->nullable()
                            ->options(function () {
                                $hid = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;
                                return \App\Models\TaxSetting::query()
                                    ->where('hotel_id', $hid)
                                    ->orderBy('is_active', 'desc')
                                    ->orderBy('name')
                                    ->limit(200)
                                    ->pluck('name', 'id');
                            })
                            ->columnSpan(4),
                    ]),
                ]),

            // ===========================
            // Reserved By (sederhana)
            // ===========================

            Section::make('Reserved By')
                ->schema([
                    Grid::make(12)
                        ->extraAttributes([
                            // Alpine state lokal, tanpa round-trip Livewire
                            'x-data' => <<<'ALPINE'
                            {
                                by: 'GUEST',
                                init() {
                                    this.$nextTick(() => {
                                        const r = this.$el.querySelector('input[name=\'data[reserved_by_type]\']:checked');
                                        this.by = r ? r.value : this.by; // 'GROUP' saat edit, 'GUEST' saat create
                                    });
                                }
                            }
                            ALPINE,
                        ])
                        ->schema([
                            Radio::make('reserved_by_type')
                                ->label('Reserved By Type')
                                ->options(['GUEST' => 'Guest', 'GROUP' => 'Group'])
                                ->default('GUEST')
                                ->extraAttributes([
                                    'x-on:change' => 'by = $event.target.value', // cepat, murni JS
                                ])
                                ->columnSpan(12),

                            // === BLOK GUEST (dibungkus supaya seluruhnya bisa disembunyikan ringan) ===
                            Group::make()
                                ->extraAttributes([
                                    'x-show'  => "by === 'GUEST'",
                                    'x-cloak' => '', // cegah flicker saat load
                                ])
                                ->schema([
                                    \Filament\Forms\Components\Select::make('guest_id')
                                        ->label('Guest')
                                        ->native(false)
                                        ->searchable()
                                        // Validasi server tetap aman dan ringan
                                        ->rules(['required_without:group_id'])
                                        // Opsi: exclude tamu yang sedang menginap (checkin != null & checkout == null)
                                        ->options(function () {
                                            $hid = \Illuminate\Support\Facades\Session::get('active_hotel_id')
                                                ?? \Illuminate\Support\Facades\Auth::user()?->hotel_id;

                                            $rows = \App\Models\Guest::query()
                                                ->where('hotel_id', $hid)
                                                ->whereNotExists(function ($sub) use ($hid) {
                                                    $sub->from('reservation_guests as rg')
                                                        ->whereColumn('rg.guest_id', 'guests.id')
                                                        ->where('rg.hotel_id', $hid)
                                                        ->whereNotNull('rg.actual_checkin')
                                                        ->whereNull('rg.actual_checkout');
                                                })
                                                ->orderBy('name')
                                                ->limit(200)
                                                ->get(['id', 'name', 'id_card']);

                                            return $rows->mapWithKeys(function ($g) {
                                                $idCard = trim((string) ($g->id_card ?? ''));
                                                $label  = $g->name . ($idCard !== '' && $idCard !== '-' ? " ({$idCard})" : '');
                                                return [$g->id => $label];
                                            })->toArray();
                                        })
                                        ->getSearchResultsUsing(function (string $search) {
                                            $hid = \Illuminate\Support\Facades\Session::get('active_hotel_id')
                                                ?? \Illuminate\Support\Facades\Auth::user()?->hotel_id;
                                            $s   = trim(preg_replace('/\s+/', ' ', $search));

                                            $rows = \App\Models\Guest::query()
                                                ->where('hotel_id', $hid)
                                                ->where(function ($q) use ($s) {
                                                    $q->where('name', 'like', "%{$s}%")
                                                        ->orWhere('phone', 'like', "%{$s}%")
                                                        ->orWhere('id_card', 'like', "%{$s}%");
                                                })
                                                ->whereNotExists(function ($sub) use ($hid) {
                                                    $sub->from('reservation_guests as rg')
                                                        ->whereColumn('rg.guest_id', 'guests.id')
                                                        ->where('rg.hotel_id', $hid)
                                                        ->whereNotNull('rg.actual_checkin')
                                                        ->whereNull('rg.actual_checkout');
                                                })
                                                ->orderBy('name')
                                                ->limit(50)
                                                ->get(['id', 'name', 'id_card']);

                                            return $rows->mapWithKeys(function ($g) {
                                                $idCard = trim((string) ($g->id_card ?? ''));
                                                $label  = $g->name . ($idCard !== '' && $idCard !== '-' ? " ({$idCard})" : '');
                                                return [$g->id => $label];
                                            })->toArray();
                                        })
                                        ->getOptionLabelUsing(function ($value) {
                                            $g = \App\Models\Guest::query()->select(['name', 'id_card'])->find($value);
                                            if (! $g) return null;
                                            $idCard = trim((string) ($g->id_card ?? ''));
                                            return $g->name . ($idCard !== '' && $idCard !== '-' ? " ({$idCard})" : '');
                                        })
                                        ->createOptionForm([
                                            \Filament\Schemas\Components\Section::make('Guest Info')->schema([
                                                \Filament\Schemas\Components\Grid::make(12)->schema([
                                                    \Filament\Forms\Components\Select::make('salutation')
                                                        ->label('Title')
                                                        ->options(['MR' => 'MR', 'MRS' => 'MRS', 'MISS' => 'MISS'])
                                                        ->native(false)->columnSpan(3),
                                                    \Filament\Forms\Components\TextInput::make('name')->label('Name')->required()->maxLength(150)->columnSpan(9),
                                                    \Filament\Forms\Components\Select::make('guest_type')
                                                        ->label('Guest Type')
                                                        ->options(['DOMESTIC' => 'Domestic', 'INTERNATIONAL' => 'International'])
                                                        ->native(false)->columnSpan(4),
                                                    \Filament\Forms\Components\TextInput::make('nationality')->label('Nationality')->maxLength(50)->columnSpan(4),
                                                    \Filament\Forms\Components\TextInput::make('address')->label('Address')->maxLength(255)->columnSpan(12),
                                                    \Filament\Forms\Components\TextInput::make('city')->label('City')->maxLength(50)->columnSpan(4),
                                                    \Filament\Forms\Components\TextInput::make('profession')->label('Profession')->maxLength(50)->columnSpan(4),
                                                    \Filament\Forms\Components\Select::make('id_type')->label('Identity Type')->options([
                                                        'ID' => 'National ID',
                                                        'PASSPORT' => 'Passport',
                                                        'DRIVER_LICENSE' => 'Driver License',
                                                        'OTHER' => 'Other',
                                                    ])->native(false)->columnSpan(4),
                                                    \Filament\Forms\Components\TextInput::make('id_card')
                                                        ->label('Identity Number')->maxLength(100)->rule('not_in:-')
                                                        ->rules([
                                                            \Illuminate\Validation\Rule::unique('guests', 'id_card')
                                                                ->where(fn($q) => $q->where('hotel_id', \Illuminate\Support\Facades\Session::get('active_hotel_id') ?? \Illuminate\Support\Facades\Auth::user()?->hotel_id)
                                                                    ->whereNull('deleted_at')),
                                                        ])->nullable()->columnSpan(6),
                                                    \Filament\Forms\Components\FileUpload::make('id_card_file')
                                                        ->label('Attach ID (JPG/PNG/PDF)')
                                                        ->directory('guests/id')->disk('public')
                                                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])
                                                        ->maxSize(4096)->downloadable()->openable()->columnSpan(6),
                                                    \Filament\Forms\Components\TextInput::make('issued_place')->label('Issued Place')->maxLength(100)->columnSpan(6),
                                                    \Filament\Forms\Components\DatePicker::make('issued_date')->label('Issued Date')->native(false)->columnSpan(6),
                                                    \Filament\Forms\Components\TextInput::make('birth_place')->label('Birth Place')->maxLength(50)->columnSpan(6),
                                                    \Filament\Forms\Components\DatePicker::make('birth_date')->label('Birth Date')->native(false)->columnSpan(6),
                                                    \Filament\Forms\Components\TextInput::make('phone')->label('Phone No')->maxLength(50)
                                                        ->rule('regex:/^\\+?\\d{6,20}$/')
                                                        ->rules([
                                                            \Illuminate\Validation\Rule::unique('guests', 'phone')
                                                                ->where(fn($q) => $q->where('hotel_id', \Illuminate\Support\Facades\Session::get('active_hotel_id') ?? \Illuminate\Support\Facades\Auth::user()?->hotel_id)
                                                                    ->whereNull('deleted_at')),
                                                        ])->nullable()->columnSpan(6),
                                                    \Filament\Forms\Components\TextInput::make('email')->label('Email')->email()->maxLength(150)
                                                        ->rules([
                                                            \Illuminate\Validation\Rule::unique('guests', 'email')
                                                                ->where(fn($q) => $q->where('hotel_id', \Illuminate\Support\Facades\Session::get('active_hotel_id') ?? \Illuminate\Support\Facades\Auth::user()?->hotel_id)
                                                                    ->whereNull('deleted_at')),
                                                        ])->nullable()->columnSpan(6),
                                                    \Filament\Forms\Components\Hidden::make('hotel_id')
                                                        ->default(fn() => \Illuminate\Support\Facades\Session::get('active_hotel_id') ?? \Illuminate\Support\Facades\Auth::user()?->hotel_id),
                                                ]),
                                            ]),
                                        ])
                                        ->createOptionUsing(fn(array $data) => \App\Models\Guest::create($data)->id),
                                ])
                                ->columnSpan(12),

                            // === BLOK GROUP ===
                            Group::make()
                                ->extraAttributes([
                                    'x-show'  => "by === 'GROUP'",
                                    'x-cloak' => '',
                                ])
                                ->schema([
                                    \Filament\Forms\Components\Select::make('group_id')
                                        ->label('Group')
                                        ->native(false)
                                        ->searchable()
                                        ->relationship(
                                            name: 'group',
                                            titleAttribute: 'name',
                                            modifyQueryUsing: function ($q) {
                                                if (! $q instanceof \Illuminate\Database\Eloquent\Builder) return;
                                                $hotelId = \Illuminate\Support\Facades\Session::get('active_hotel_id') ?? \Illuminate\Support\Facades\Auth::user()?->hotel_id;
                                                if ($hotelId) $q->where('hotel_id', $hotelId);
                                            }
                                        )
                                        ->rules(['required_without:guest_id'])
                                        ->createOptionForm([
                                            \Filament\Forms\Components\TextInput::make('name')->label('Group Name')->required(),
                                            \Filament\Forms\Components\TextInput::make('address')->label('Address'),
                                            \Filament\Forms\Components\TextInput::make('city')->label('City'),
                                            \Filament\Forms\Components\TextInput::make('fax')->label('Fax'),
                                            \Filament\Forms\Components\TextInput::make('phone')->label('Phone'),
                                            \Filament\Forms\Components\TextInput::make('handphone')->label('Handphone'),
                                            \Filament\Forms\Components\TextInput::make('email')->label('Email')->email(),
                                            \Filament\Forms\Components\Textarea::make('long_remark')->label('Remark')->rows(2),
                                            \Filament\Forms\Components\Hidden::make('hotel_id')->default(fn() => \Illuminate\Support\Facades\Session::get('active_hotel_id') ?? \Illuminate\Support\Facades\Auth::user()?->hotel_id),
                                            \Filament\Forms\Components\Hidden::make('created_by')->default(fn() => \Illuminate\Support\Facades\Auth::id()),
                                        ]),
                                ])
                                ->columnSpan(12),
                        ]),
                ]),

            // =========================================
            // Assign Room & Guest (tanpa reactive/helper)
            // =========================================
            Section::make('Information Guest & Room Assignment')
                ->schema([
                    Repeater::make('reservationGuests')
                        ->relationship('reservationGuests')
                        ->columnSpanFull()
                        ->columns(12)
                        // ===== Create hook =====
                        ->mutateRelationshipDataBeforeCreateUsing(function (array $data): array {
                            // Hotel id
                            $data['hotel_id'] = $data['hotel_id']
                                ?? (\Illuminate\Support\Facades\Session::get('active_hotel_id')
                                    ?? \Illuminate\Support\Facades\Auth::user()?->hotel_id);

                            // Pax total
                            $male     = (int) ($data['male'] ?? 0);
                            $female   = (int) ($data['female'] ?? 0);
                            $children = (int) ($data['children'] ?? 0);
                            $data['jumlah_orang'] = max(1, $male + $female + $children);

                            // ==== Sync tanggal dari header (arrival/departure/nights) ====
                            $parentArrival    = \Illuminate\Support\Arr::get(request()->input(), 'data.expected_arrival');
                            $parentDeparture  = \Illuminate\Support\Arr::get(request()->input(), 'data.expected_departure');
                            $parentNights     = (int) (\Illuminate\Support\Arr::get(request()->input(), 'data.nights') ?? 1);

                            $headerIn = $parentArrival
                                ? \Illuminate\Support\Carbon::parse($parentArrival)->setTime(12, 0)
                                : null;

                            $headerOut = $parentDeparture
                                ? \Illuminate\Support\Carbon::parse($parentDeparture)->setTime(12, 0)
                                : ($headerIn
                                    ? $headerIn->copy()->addDays(max(1, $parentNights))->setTime(12, 0)
                                    : null);

                            // Default checkin/checkout per baris
                            if (empty($data['expected_checkin'])) {
                                $data['expected_checkin'] = $headerIn ? $headerIn->copy() : now()->setTime(12, 0);
                            }
                            if (empty($data['expected_checkout'])) {
                                $data['expected_checkout'] = $headerOut
                                    ? $headerOut->copy()
                                    : \Illuminate\Support\Carbon::parse($data['expected_checkin'])->addDay()->setTime(12, 0);
                            }

                            // Pastikan checkout > checkin (≥+1 hari)
                            if (
                                \Illuminate\Support\Carbon::parse($data['expected_checkout'])
                                ->lessThanOrEqualTo(\Illuminate\Support\Carbon::parse($data['expected_checkin']))
                            ) {
                                $data['expected_checkout'] = \Illuminate\Support\Carbon::parse($data['expected_checkin'])
                                    ->addDay()->setTime(12, 0);
                            }

                            // Auto room rate jika kosong
                            if (empty($data['room_rate']) && ! empty($data['room_id'])) {
                                $price = \App\Models\Room::whereKey($data['room_id'])->value('price');
                                $data['room_rate'] = (int) ($price ?? 0);
                            }

                            return $data;
                        })

                        // ===== Save hook =====
                        ->mutateRelationshipDataBeforeSaveUsing(function (array $data): array {
                            $data['hotel_id'] = $data['hotel_id']
                                ?? (Session::get('active_hotel_id')
                                    ?? \Illuminate\Support\Facades\Auth::user()?->hotel_id);

                            // Pax total
                            $male     = (int) ($data['male'] ?? 0);
                            $female   = (int) ($data['female'] ?? 0);
                            $children = (int) ($data['children'] ?? 0);
                            $data['jumlah_orang'] = max(1, $male + $female + $children);

                            // Sync dari header jika field kosong
                            $parentArrival   = \Illuminate\Support\Arr::get(request()->input(), 'data.expected_arrival');
                            $parentDeparture = \Illuminate\Support\Arr::get(request()->input(), 'data.expected_departure');

                            if (empty($data['expected_checkin']) && $parentArrival) {
                                $data['expected_checkin'] = \Illuminate\Support\Carbon::parse($parentArrival)->setTime(12, 0);
                            }
                            if (empty($data['expected_checkout']) && $parentDeparture) {
                                $data['expected_checkout'] = \Illuminate\Support\Carbon::parse($parentDeparture)->setTime(12, 0);
                            }

                            // Default fallback
                            if (empty($data['expected_checkin'])) {
                                $data['expected_checkin'] = now()->setTime(12, 0);
                            }
                            if (empty($data['expected_checkout'])) {
                                $data['expected_checkout'] = \Illuminate\Support\Carbon::parse($data['expected_checkin'])->addDay()->setTime(12, 0);
                            }

                            // Room rate normalize/auto
                            if (empty($data['room_rate']) && ! empty($data['room_id'])) {
                                $price = \App\Models\Room::whereKey($data['room_id'])->value('price');
                                $data['room_rate'] = (int) ($price ?? 0);
                            } else {
                                $data['room_rate'] = (int) ($data['room_rate'] ?? 0);
                            }

                            return $data;
                        })

                        ->schema([
                            Grid::make(12)->schema([
                                \Filament\Forms\Components\Hidden::make('id')
                                    ->dehydrated(true)
                                    ->disabled(),

                                // =========================
                                // ROW 1 (wajib)
                                // =========================

                                // Room (2/12)
                                \Filament\Forms\Components\Select::make('room_id')
                                    ->label('Room')
                                    ->native(false)
                                    ->placeholder('Select')
                                    ->searchable()
                                    ->rules(['required', 'distinct'])
                                    ->live()
                                    ->reactive()
                                    ->options(function (Get $get) {
                                        $hid     = \Illuminate\Support\Facades\Session::get('active_hotel_id')
                                            ?? \Illuminate\Support\Facades\Auth::user()?->hotel_id;
                                        $current = (int) ($get('room_id') ?? 0);

                                        $picked  = array_map('intval', array_filter($get('../../reservationGuests.*.room_id') ?? []));
                                        $exclude = array_diff($picked, [$current]);

                                        // GANTI: gunakan scope assignable + filter hotel + exclude yg sudah dipilih
                                        return \App\Models\Room::query()
                                            ->where('hotel_id', $hid)
                                            ->assignable() // TAMBAH: hanya VC/VCI & tidak OCC
                                            ->when(! empty($exclude), fn($q) => $q->whereNotIn('id', $exclude))
                                            ->orderBy('room_no')
                                            ->limit(200)
                                            ->pluck('room_no', 'id');
                                    })
                                    ->getSearchResultsUsing(function (string $search, Get $get) {
                                        $hid     = \Illuminate\Support\Facades\Session::get('active_hotel_id')
                                            ?? \Illuminate\Support\Facades\Auth::user()?->hotel_id;
                                        $current = (int) ($get('room_id') ?? 0);

                                        $picked  = array_map('intval', array_filter($get('../../reservationGuests.*.room_id') ?? []));
                                        $exclude = array_diff($picked, [$current]);

                                        // GANTI: tetap hormati assignable()
                                        return \App\Models\Room::query()
                                            ->where('hotel_id', $hid)
                                            ->assignable() // TAMBAH
                                            ->where('room_no', 'like', "%{$search}%")
                                            ->when(! empty($exclude), fn($q) => $q->whereNotIn('id', $exclude))
                                            ->orderBy('room_no')
                                            ->limit(50)
                                            ->pluck('room_no', 'id');
                                    })
                                    ->columnSpan(2),

                                // Guest (6/12)
                                \Filament\Forms\Components\Select::make('guest_id')
                                    ->label('Guest')
                                    ->placeholder('Select')
                                    ->native(false)
                                    ->rules(['required', 'distinct'])
                                    ->searchable()
                                    ->live()
                                    ->reactive()
                                    ->createOptionForm([
                                        Section::make('Guest Info')->schema([
                                            Grid::make(12)->schema([
                                                Select::make('salutation')
                                                    ->label('Title')
                                                    ->options(['MR' => 'MR', 'MRS' => 'MRS', 'MISS' => 'MISS'])
                                                    ->native(false)
                                                    ->columnSpan(3),

                                                \Filament\Forms\Components\TextInput::make('name')
                                                    ->label('Name')
                                                    ->required()
                                                    ->maxLength(150)
                                                    ->columnSpan(9),

                                                \Filament\Forms\Components\Select::make('guest_type')
                                                    ->label('Guest Type')
                                                    ->options([
                                                        'DOMESTIC'      => 'Domestic',
                                                        'INTERNATIONAL' => 'International',
                                                    ])
                                                    ->native(false)
                                                    ->columnSpan(4),

                                                \Filament\Forms\Components\TextInput::make('nationality')
                                                    ->label('Nationality')
                                                    ->maxLength(50)
                                                    ->columnSpan(4),

                                                \Filament\Forms\Components\TextInput::make('address')
                                                    ->label('Address')
                                                    ->maxLength(255)
                                                    ->columnSpan(12),

                                                \Filament\Forms\Components\TextInput::make('city')->label('City')->maxLength(50)->columnSpan(4),
                                                \Filament\Forms\Components\TextInput::make('profession')->label('Profession')->maxLength(50)->columnSpan(4),

                                                \Filament\Forms\Components\Select::make('id_type')
                                                    ->label('Identity Type')
                                                    ->options([
                                                        'ID'             => 'National ID',
                                                        'PASSPORT'       => 'Passport',
                                                        'DRIVER_LICENSE' => 'Driver License',
                                                        'OTHER'          => 'Other',
                                                    ])
                                                    ->native(false)
                                                    ->columnSpan(4),

                                                \Filament\Forms\Components\TextInput::make('id_card')
                                                    ->label('Identity Number')
                                                    ->maxLength(100)
                                                    ->rule('not_in:-')
                                                    ->rules([
                                                        \Illuminate\Validation\Rule::unique('guests', 'id_card')
                                                            ->where(fn($q) => $q
                                                                ->where('hotel_id', \Illuminate\Support\Facades\Session::get('active_hotel_id') ?? \Illuminate\Support\Facades\Auth::user()?->hotel_id)
                                                                ->whereNull('deleted_at')),
                                                    ])
                                                    ->nullable()
                                                    ->columnSpan(6),

                                                \Filament\Forms\Components\FileUpload::make('id_card_file')
                                                    ->label('Attach ID (JPG/PNG/PDF)')
                                                    ->directory('guests/id')
                                                    ->disk('public')
                                                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])
                                                    ->maxSize(4096)
                                                    ->downloadable()
                                                    ->openable()
                                                    ->columnSpan(6),

                                                \Filament\Forms\Components\TextInput::make('issued_place')->label('Issued Place')->maxLength(100)->columnSpan(6),
                                                \Filament\Forms\Components\DatePicker::make('issued_date')->label('Issued Date')->native(false)->columnSpan(6),

                                                \Filament\Forms\Components\TextInput::make('birth_place')->label('Birth Place')->maxLength(50)->columnSpan(6),
                                                \Filament\Forms\Components\DatePicker::make('birth_date')->label('Birth Date')->native(false)->columnSpan(6),

                                                \Filament\Forms\Components\TextInput::make('phone')
                                                    ->label('Phone No')
                                                    ->maxLength(50)
                                                    ->rule('regex:/^\\+?\\d{6,20}$/')
                                                    ->rules([
                                                        \Illuminate\Validation\Rule::unique('guests', 'phone')
                                                            ->where(fn($q) => $q
                                                                ->where('hotel_id', \Illuminate\Support\Facades\Session::get('active_hotel_id') ?? \Illuminate\Support\Facades\Auth::user()?->hotel_id)
                                                                ->whereNull('deleted_at')),
                                                    ])
                                                    ->nullable()
                                                    ->columnSpan(6),

                                                \Filament\Forms\Components\TextInput::make('email')
                                                    ->label('Email')
                                                    ->email()
                                                    ->maxLength(150)
                                                    ->rules([
                                                        \Illuminate\Validation\Rule::unique('guests', 'email')
                                                            ->where(fn($q) => $q
                                                                ->where('hotel_id', \Illuminate\Support\Facades\Session::get('active_hotel_id') ?? \Illuminate\Support\Facades\Auth::user()?->hotel_id)
                                                                ->whereNull('deleted_at')),
                                                    ])
                                                    ->nullable()
                                                    ->columnSpan(6),

                                                \Filament\Forms\Components\Hidden::make('hotel_id')
                                                    ->default(fn() => \Illuminate\Support\Facades\Session::get('active_hotel_id') ?? \Illuminate\Support\Facades\Auth::user()?->hotel_id),
                                            ]),
                                        ]),
                                    ])
                                    ->createOptionUsing(fn(array $data) => \App\Models\Guest::create($data)->id)
                                    ->options(function (Get $get) {
                                        $hid     = \Illuminate\Support\Facades\Session::get('active_hotel_id')
                                            ?? \Illuminate\Support\Facades\Auth::user()?->hotel_id;
                                        $current = (int) ($get('guest_id') ?? 0);

                                        $picked  = array_map('intval', array_filter($get('../../reservationGuests.*.guest_id') ?? []));
                                        $exclude = array_diff($picked, [$current]);

                                        // GANTI: exclude tamu yg sedang menginap (open stay)
                                        $rows = \App\Models\Guest::query()
                                            ->where('hotel_id', $hid)
                                            ->when(! empty($exclude), fn($q) => $q->whereNotIn('id', $exclude))
                                            ->whereNotExists(function ($sub) use ($hid) {       // TAMBAH
                                                $sub->from('reservation_guests as rg')
                                                    ->whereColumn('rg.guest_id', 'guests.id')
                                                    ->where('rg.hotel_id', $hid)
                                                    ->whereNotNull('rg.actual_checkin')
                                                    ->whereNull('rg.actual_checkout');
                                            })
                                            ->orderBy('name')
                                            ->limit(200)
                                            ->get(['id', 'name', 'id_card']);

                                        return $rows->mapWithKeys(function ($g) {
                                            $idCard = trim((string) ($g->id_card ?? ''));
                                            $label  = $g->name . ($idCard !== '' && $idCard !== '-' ? " ({$idCard})" : '');
                                            return [$g->id => $label];
                                        })->toArray();
                                    })
                                    ->getSearchResultsUsing(function (string $search, Get $get) {
                                        $hid     = \Illuminate\Support\Facades\Session::get('active_hotel_id')
                                            ?? \Illuminate\Support\Facades\Auth::user()?->hotel_id;
                                        $current = (int) ($get('guest_id') ?? 0);

                                        $picked  = array_map('intval', array_filter($get('../../reservationGuests.*.guest_id') ?? []));
                                        $exclude = array_diff($picked, [$current]);

                                        $s = trim(preg_replace('/\s+/', ' ', $search));

                                        // GANTI: exclude open stay juga saat pencarian
                                        $rows = \App\Models\Guest::query()
                                            ->where('hotel_id', $hid)
                                            ->when(! empty($exclude), fn($q) => $q->whereNotIn('id', $exclude))
                                            ->where(function ($q) use ($s) {
                                                $q->where('name', 'like', "%{$s}%")
                                                    ->orWhere('phone', 'like', "%{$s}%")
                                                    ->orWhere('id_card', 'like', "%{$s}%");
                                            })
                                            ->whereNotExists(function ($sub) use ($hid) {       // TAMBAH
                                                $sub->from('reservation_guests as rg')
                                                    ->whereColumn('rg.guest_id', 'guests.id')
                                                    ->where('rg.hotel_id', $hid)
                                                    ->whereNotNull('rg.actual_checkin')
                                                    ->whereNull('rg.actual_checkout');
                                            })
                                            ->orderBy('name')
                                            ->limit(50)
                                            ->get(['id', 'name', 'id_card']);

                                        return $rows->mapWithKeys(function ($g) {
                                            $idCard = trim((string) ($g->id_card ?? ''));
                                            $label  = $g->name . ($idCard !== '' && $idCard !== '-' ? " ({$idCard})" : '');
                                            return [$g->id => $label];
                                        })->toArray();
                                    })
                                    ->getOptionLabelUsing(function ($value) {
                                        $g = \App\Models\Guest::query()->select(['name', 'id_card'])->find($value);
                                        if (! $g) return null;
                                        $idCard = trim((string) ($g->id_card ?? ''));
                                        return $g->name . ($idCard !== '' && $idCard !== '-' ? " ({$idCard})" : '');
                                    })
                                    ->extraAttributes([
                                        'x-init' => '$nextTick(() => { const el = $el.querySelector(".ts-control"); if(el){ el.style.whiteSpace="nowrap"; el.style.overflow="hidden"; el.style.textOverflow="ellipsis"; } })',
                                    ])
                                    ->columnSpan(6),

                                // Rate (2/12)
                                \Filament\Forms\Components\TextInput::make('room_rate')
                                    ->label('Rate')
                                    ->numeric()
                                    ->mask(\Filament\Support\RawJs::make('$money($input)'))
                                    ->stripCharacters(',')
                                    ->placeholder('Auto from room')
                                    ->minValue(0)
                                    ->columnSpan(2),

                                // Discount (2/12)
                                \Filament\Forms\Components\TextInput::make('discount_percent')
                                    ->label('Discount (%)')
                                    ->numeric()
                                    ->inputMode('decimal')
                                    ->step('0.01')
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->placeholder('0.00')
                                    ->suffix('%')
                                    ->default(0)
                                    ->columnSpan(2),

                                // =========================
                                // ROW 2 (opsi tarif)
                                // =========================
                                \Filament\Forms\Components\TextInput::make('service')
                                    ->label('Service')
                                    ->mask(\Filament\Support\RawJs::make('$money($input)'))
                                    ->stripCharacters(',')
                                    ->numeric()
                                    ->default(0)
                                    ->columnSpan(3),

                                \Filament\Forms\Components\TextInput::make('extra_bed')
                                    ->label('E. Bed')
                                    ->numeric()
                                    ->default(0)
                                    ->minValue(0)
                                    ->columnSpan(2),

                                \Filament\Forms\Components\Select::make('breakfast')
                                    ->label('Breakfast')
                                    ->options(['Yes' => 'Yes', 'No' => 'No'])
                                    ->default('No')
                                    ->columnSpan(2),

                                \Filament\Forms\Components\TextInput::make('person')
                                    ->label('Person Charge')
                                    ->columnSpan(3),

                                // =========================
                                // ROW 3 (pax & keterangan)
                                // =========================
                                \Filament\Forms\Components\TextInput::make('male')
                                    ->label('Male')
                                    ->numeric()
                                    ->default(1)
                                    ->minValue(0)
                                    ->columnSpan(2),

                                \Filament\Forms\Components\TextInput::make('female')
                                    ->label('Female')
                                    ->numeric()
                                    ->default(0)
                                    ->minValue(0)
                                    ->columnSpan(2),

                                \Filament\Forms\Components\TextInput::make('children')
                                    ->label('Children')
                                    ->numeric()
                                    ->default(0)
                                    ->minValue(0)
                                    ->columnSpan(2),

                                \Filament\Forms\Components\Hidden::make('jumlah_orang'),

                                \Filament\Forms\Components\TextInput::make('pov')
                                    ->label('Purpose of Visit')
                                    ->maxLength(150)
                                    ->columnSpan(3),

                                \Filament\Forms\Components\TextInput::make('note')
                                    ->label('Note')
                                    ->maxLength(150)
                                    ->columnSpan(5),
                            ])->columnSpanFull(),
                        ])

                        ->deletable(false)

                        ->extraItemActions([
                            // ===== Open Check-In page =====
                            Action::make('check_in')
                                ->label('Check In')
                                ->color('success')
                                ->icon('heroicon-o-arrow-right-on-rectangle')
                                ->visible(function ($record): bool {
                                    if ($record instanceof \App\Models\ReservationGuest) {
                                        return filled($record->getKey());
                                    }
                                    if (isset($record->reservation_guest_id) && $record->reservation_guest_id) {
                                        return true;
                                    }
                                    if (method_exists($record, 'reservationGuests')) {
                                        return $record->reservationGuests()->exists();
                                    }
                                    return true;
                                })
                                ->action(function (array $arguments, $livewire, $record) {
                                    $rgId = null;

                                    // Ambil ID dari state Repeater (paling akurat)
                                    if ($itemKey = \Illuminate\Support\Arr::get($arguments, 'item')) {
                                        $rgId = (int) data_get($livewire, "data.reservationGuests.{$itemKey}.id");
                                    }

                                    // Fallback lain
                                    if (! $rgId && $record instanceof \App\Models\ReservationGuest) {
                                        $rgId = $record->getKey();
                                    }
                                    if (! $rgId && isset($record->reservation_guest_id) && $record->reservation_guest_id) {
                                        $rgId = (int) $record->reservation_guest_id;
                                    }
                                    if (! $rgId && method_exists($record, 'reservationGuests')) {
                                        $rgId = optional($record->reservationGuests()->latest('id')->first())?->getKey();
                                    }

                                    if (! $rgId) {
                                        \Filament\Notifications\Notification::make()
                                            ->title('Gagal membuka Check In')
                                            ->body('ReservationGuest ID tidak ditemukan dari item/form yang dipilih.')
                                            ->danger()
                                            ->send();
                                        return;
                                    }

                                    $url = ReservationGuestResource::getUrl('edit', ['record' => $rgId]);

                                    $livewire->js(<<<JS
                            window.open("{$url}", "_blank", "noopener");
                        JS);
                                }),

                            // ===== Hard delete baris sekarang =====
                            Action::make('hapus_sekarang')
                                ->label('Hapus (langsung)')
                                ->icon('heroicon-o-trash')
                                ->color('danger')
                                ->requiresConfirmation()
                                ->modalHeading('Hapus baris ini sekarang?')
                                ->modalDescription(function (array $arguments, $livewire) {
                                    $itemKey = data_get($arguments, 'item');
                                    $rgId    = (int) data_get($livewire, "data.reservationGuests.{$itemKey}.id");
                                    return 'Tindakan ini tidak dapat dibatalkan. ID RG: #' . ($rgId ?: '-');
                                })
                                ->action(function (array $arguments, $livewire) {
                                    $itemKey = data_get($arguments, 'item');
                                    $rgId    = (int) data_get($livewire, "data.reservationGuests.{$itemKey}.id");

                                    if (! $rgId) {
                                        \Filament\Notifications\Notification::make()
                                            ->title('Gagal menghapus')
                                            ->body('ID ReservationGuest tidak ditemukan pada baris ini.')
                                            ->danger()
                                            ->send();
                                        return;
                                    }

                                    \Illuminate\Support\Facades\DB::transaction(function () use ($rgId) {
                                        \App\Models\ReservationGuest::find($rgId)?->delete(); // hard delete (model RG tidak pakai SoftDeletes)
                                    });

                                    // Hapus dari state agar hilang di UI tanpa reload
                                    $items = (array) data_get($livewire, 'data.reservationGuests', []);
                                    unset($items[$itemKey]);
                                    data_set($livewire, 'data.reservationGuests', $items);

                                    \Filament\Notifications\Notification::make()
                                        ->title("ReservationGuest #{$rgId} dihapus")
                                        ->success()
                                        ->send();
                                }),
                        ]),
                ])
                ->columnSpanFull(),
        ]);
    }
}
