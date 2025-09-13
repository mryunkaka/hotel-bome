<?php

namespace App\Filament\Resources\Reservations\Schemas;

use App\Models\Room;
use App\Models\Guest;
use App\Models\Reservation;
use Filament\Support\RawJs;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Filament\Forms\Components\Radio;
use Illuminate\Support\Facades\Auth;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Illuminate\Support\Facades\Session;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\DateTimePicker;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Forms\Components\Select as FSelect;

class ReservationForm
{
    public static function configure(Schema $schema): Schema
    {
        if (! function_exists('buildGridFromRows')) {
            /**
             * Bangun data grid preview dari state repeater reservationGuests.
             * NOTE: dept (ETD) diambil dari UI header (expected_departure) jika ada,
             * kalau tidak, dihitung dari expected_arrival + nights (default).
             */
            // REPLACE buildGridFromRows with this version
            function buildGridFromRows(\Filament\Schemas\Components\Utilities\Get $get, array $rows): array
            {
                if (empty($rows)) return [];

                $prev = $get('../../reservationGrid') ?? [];

                $roomIds  = collect($rows)->pluck('room_id')->filter()->unique()->values();
                $guestIds = collect($rows)->pluck('guest_id')->filter()->unique()->values();

                $rooms  = $roomIds->isNotEmpty()  ? \App\Models\Room::whereIn('id', $roomIds)->get()->keyBy('id')  : collect();
                $guests = $guestIds->isNotEmpty() ? \App\Models\Guest::whereIn('id', $guestIds)->get()->keyBy('id') : collect();

                // ===== normalisasi tanggal dari state (Carbon|string|array) =====
                $arrState = $get('../../expected_arrival') ?? now();
                $arrival  = \App\Filament\Resources\Reservations\Schemas\ReservationForm::parseDt($arrState)->startOfDay();

                $nights   = max(1, (int) ($get('../../nights') ?: 1));
                $depState = $get('../../expected_departure');
                $defaultDept = $depState
                    ? \App\Filament\Resources\Reservations\Schemas\ReservationForm::parseDt($depState)->setTime(12, 0)
                    : $arrival->copy()->addDays($nights)->setTime(12, 0);

                return collect($rows)->values()->map(function ($r, $idx) use ($rooms, $guests, $arrival, $defaultDept, $prev) {
                    $room     = $rooms[$r['room_id'] ?? 0] ?? null;
                    $guest    = $guests[$r['guest_id'] ?? 0] ?? null;
                    $prevRow  = $prev[$idx] ?? [];

                    $male     = (int)($r['male']     ?? $prevRow['male']     ?? 0);
                    $female   = (int)($r['female']   ?? $prevRow['female']   ?? 0);
                    $children = (int)($r['children'] ?? $prevRow['children'] ?? 0);
                    $jumlah   = max(1, $male + $female + $children);

                    $roomRate = $r['room_rate'] ?? $prevRow['room_rate'] ?? ($room?->price);
                    $dept     = $r['expected_departure'] ?? ($prevRow['dept'] ?? $defaultDept);

                    return [
                        'room_id'       => $r['room_id'] ?? null,
                        'guest_id'      => $r['guest_id'] ?? null,
                        'room_no'       => $room?->room_no,
                        'category'      => $room?->type,
                        'room_rate'     => $roomRate,

                        'jumlah_orang'  => $jumlah,
                        'guest_name'    => $guest?->name ?? ($prevRow['guest_name'] ?? null),

                        'person'        => $prevRow['person'] ?? null,
                        'male'          => $male,
                        'female'        => $female,
                        'children'      => $children,
                        'charge_to'     => $r['charge_to'] ?? ($prevRow['charge_to'] ?? null),
                        'note'          => $prevRow['note'] ?? null,

                        'arrival'       => $prevRow['arrival'] ?? $arrival->copy(),
                        'dept'          => $dept ? \Illuminate\Support\Carbon::parse($dept) : $defaultDept->copy(),
                    ];
                })->all();
            }
        }

        return $schema->components([
            Section::make('Reservation Info')
                ->schema([
                    Grid::make(12)->schema([
                        Hidden::make('reservation_no')
                            ->label('Reservation No')
                            ->disabled()
                            ->dehydrated(true)
                            ->default(fn() => Reservation::nextReservationNo(Session::get('active_hotel_id'))),

                        Hidden::make('created_by')
                            ->default(fn() => Auth::id())
                            ->dehydrated(true)   // penting: ikut terkirim
                            ->required(),

                        Hidden::make('entry_date')
                            ->default(fn($record) => $record?->entry_date ?? now())
                            ->dehydrated(true)   // penting: ikut terkirim
                            ->required(),


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
                            ->native(false)
                            ->columnSpan(2),

                        Select::make('method')
                            ->label('Method')
                            ->default('PERSONAL')
                            ->options([
                                'PHONE'    => 'Phone',
                                'WA/SMS'   => 'WA / SMS',
                                'EMAIL'    => 'Email',
                                'LETTER'   => 'Letter',
                                'PERSONAL' => 'Personal',
                                'OTA'      => 'OTA',
                                'OTHER'    => 'Other',
                            ])
                            ->required()
                            ->native(false)
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
                            ->native(false)
                            ->columnSpan(2),

                        Radio::make('deposit_type')
                            ->label('Deposit Type')
                            ->options([
                                'DP'   => 'Room Deposit',
                                'CARD' => 'Deposit Card',
                            ])
                            ->inline()
                            ->default('DP')
                            ->live()
                            ->columnSpan(3),

                        TextInput::make('deposit')
                            ->label(fn($get) => $get('deposit_type') === 'CARD' ? 'Deposit Card' : 'Room Deposit')
                            ->numeric()
                            ->mask(RawJs::make('$money($input)'))
                            ->stripCharacters([','])
                            ->prefix('Rp')
                            ->default(0)
                            ->columnSpan(3),
                    ]),
                ])
                ->columnSpanFull(),

            Section::make('Information')
                ->schema([
                    Grid::make(12)->schema([
                        // view-only number
                        // Reservation No (view-only) -> ambil dari hidden reservation_no
                        TextInput::make('reservation_no_view')
                            ->label('Reservation No')
                            ->disabled()
                            ->dehydrated(false)
                            ->afterStateHydrated(function (Set $set, Get $get) {
                                $set('reservation_no_view', $get('reservation_no')
                                    ?? \App\Models\Reservation::nextReservationNo(Session::get('active_hotel_id')));
                            })
                            ->extraAttributes(['class' => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200'])
                            ->columnSpan(4),

                        // Entry By (view-only) -> dari created_by (user_id)
                        TextInput::make('created_by_view')
                            ->label('Entry By')
                            ->disabled()
                            ->dehydrated(false)
                            ->afterStateHydrated(function (Set $set, Get $get) {
                                $uid = $get('created_by');
                                $name = optional(\App\Models\User::find($uid))->name ?? Auth::user()?->name;
                                $set('created_by_view', $name);
                            })
                            ->extraAttributes(['class' => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200'])
                            ->columnSpan(4),

                        // Entry Date (view-only) -> dari entry_date (hidden) atau created_at
                        DateTimePicker::make('entry_date_view')
                            ->label('Entry Date')
                            ->seconds(false)
                            ->native(false)
                            ->disabled()
                            ->dehydrated(false)
                            ->afterStateHydrated(function (Set $set, Get $get) {
                                $set('entry_date_view', $get('entry_date') ?? now());
                            })
                            ->extraAttributes(['class' => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200'])
                            ->columnSpan(4),
                    ]),
                ]),

            Section::make('Reserved By')
                ->schema([
                    Grid::make(12)->schema([
                        // Title & Name -> hanya saat Guest
                        Select::make('reserved_title')
                            ->label('Title')
                            ->options([
                                'MR'   => 'MR',
                                'MRS'  => 'MRS',
                                'MISS' => 'MISS',
                            ])
                            ->native(false)
                            ->visible(fn(Get $get) => ($get('reserved_by_type') ?? 'GUEST') !== 'GROUP')
                            ->required(fn(Get $get) => ($get('reserved_by_type') ?? 'GUEST') === 'GUEST')
                            ->columnSpan(6),

                        TextInput::make('reserved_by')
                            ->label('Name')
                            ->visible(fn(Get $get) => ($get('reserved_by_type') ?? 'GUEST') === 'GUEST')
                            ->required(fn(Get $get) => ($get('reserved_by_type') ?? 'GUEST') === 'GUEST')
                            ->columnSpan(6),

                        // No. HP/WA -> hanya saat Guest
                        TextInput::make('reserved_number')
                            ->label('No. HP/WA')
                            ->visible(fn(Get $get) => ($get('reserved_by_type') ?? 'GUEST') === 'GUEST')
                            ->required(fn(Get $get) => ($get('reserved_by_type') ?? 'GUEST') === 'GUEST')
                            ->columnSpan(12),

                        // Pilih jenis pemesan: Guest/Group
                        Radio::make('reserved_by_type')
                            ->label('Reserved By Type')
                            ->options([
                                'GUEST' => 'Guest',
                                'GROUP' => 'Group',
                            ])
                            ->default('GUEST')
                            ->inline()
                            ->live()
                            ->afterStateHydrated(function (Set $set, $state, Get $get) {
                                if (blank($state)) {
                                    // jika edit dan sudah ada group_id -> GROUP, selain itu GUEST
                                    $set('reserved_by_type', $get('group_id') ? 'GROUP' : 'GUEST');
                                }
                            })
                            ->afterStateUpdated(function (Set $set, $state) {
                                if ($state === 'GROUP') {
                                    // bersihkan field milik Guest
                                    $set('reserved_title', null);
                                    $set('reserved_by', null);
                                    $set('reserved_number', null);
                                } else {
                                    // kembali ke Guest -> kosongkan group
                                    $set('group_id', null);
                                }
                            })
                            ->columnSpan(12),

                        // Pilihan Group (tetap ada)
                        FSelect::make('group_id')
                            ->label('Group')
                            ->relationship(
                                name: 'group',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn($query) =>
                                $query->where('hotel_id', Session::get('active_hotel_id'))
                            )
                            ->visible(
                                fn(Get $get) => ($get('reserved_by_type') === 'GROUP') || filled($get('group_id'))
                            )
                            ->required(fn(Get $get) => $get('reserved_by_type') === 'GROUP')
                            ->searchable()
                            ->preload()
                            ->createOptionForm([
                                Section::make('Group Details')->schema([
                                    Grid::make(12)->schema([
                                        TextInput::make('name')->label('Group Name')->required()->columnSpan(6),
                                        TextInput::make('phone')->label('Phone')->columnSpan(3),
                                        TextInput::make('handphone')->label('Handphone')->columnSpan(3),

                                        TextInput::make('address')->label('Address')->columnSpan(12),
                                        TextInput::make('city')->label('City')->columnSpan(4),
                                        TextInput::make('fax')->label('Fax')->columnSpan(4),
                                        TextInput::make('email')->label('Email')->email()->columnSpan(4),

                                        TextInput::make('remark_ci')->label('Remark CI')->columnSpan(12),
                                        Textarea::make('long_remark')->label('Long Remark')->rows(4)->columnSpan(12),

                                        Hidden::make('hotel_id')
                                            ->default(fn() => Session::get('active_hotel_id'))
                                            ->dehydrated(true),

                                        Hidden::make('created_by')
                                            ->default(fn() => Auth::id())
                                            ->dehydrated(true)
                                            ->required(),
                                    ]),
                                ]),
                            ])
                            ->columnSpan(12),
                    ]),
                ]),

            Section::make('Assign Room & Guest')
                ->schema([
                    // (opsional) hotel_id untuk header reservation
                    Hidden::make('hotel_id')
                        ->default(fn() => Session::get('active_hotel_id'))
                        ->dehydrated(true)
                        ->required(),

                    Repeater::make('reservationGuests')
                        ->relationship('reservationGuests')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->columns(12)
                        ->defaultItems(1)
                        ->minItems(1)
                        ->reorderable(false)
                        ->addActionLabel('Tambah tamu')

                        // Pastikan setiap baris relasi membawa hotel_id
                        ->mutateRelationshipDataBeforeCreateUsing(function (array $data): array {
                            $data['hotel_id'] = $data['hotel_id']
                                ?? (Session::get('active_hotel_id') ?? Auth::user()?->hotel_id);
                            return $data;
                        })
                        ->mutateRelationshipDataBeforeSaveUsing(function (array $data): array {
                            $data['hotel_id'] = $data['hotel_id']
                                ?? (Session::get('active_hotel_id') ?? Auth::user()?->hotel_id);
                            return $data;
                        })

                        ->live()
                        ->afterStateHydrated(function ($set, $get, ?array $state) {
                            // build grid pertama kali (edit/create)
                            if (blank($get('../../reservationGrid'))) {
                                $set('reservationGrid', buildGridFromRows($get, $state ?? []));
                            }
                        })
                        ->afterStateUpdated(function ($set, $get, ?array $state) {
                            // rebuld grid saat room/guest berubah
                            $set('reservationGrid', buildGridFromRows($get, $state ?? []));
                        })

                        ->schema([
                            // WAJIB: hotel_id di dalam item repeater agar ikut ke relasi
                            Hidden::make('hotel_id')
                                ->default(fn() => Session::get('active_hotel_id') ?? Auth::user()?->hotel_id)
                                ->dehydrated(true)
                                ->required(),

                            // ====== CARRIER FIELDS (untuk EDIT) — tidak didehydrate ======
                            Hidden::make('room_rate')->dehydrated(false),
                            Hidden::make('male')->dehydrated(false),
                            Hidden::make('female')->dehydrated(false),
                            Hidden::make('children')->dehydrated(false),
                            Hidden::make('charge_to')->dehydrated(false),
                            Hidden::make('expected_departure')->dehydrated(false), // ETD per-guest (dipakai grid->dept)
                            Hidden::make('person')->dehydrated(false),
                            Hidden::make('note')->dehydrated(false),
                            Hidden::make('jumlah_orang')->dehydrated(false),

                            // ====== Input utama ======

                            // Pilih kamar
                            FSelect::make('room_id')
                                ->label('Room')
                                ->placeholder('Select')
                                ->searchable()
                                ->preload()
                                ->reactive()
                                ->rules(['distinct'])
                                ->options(function ($get) {
                                    $hotelId = Session::get('active_hotel_id');

                                    $arrival = Carbon::parse($get('../../expected_arrival') ?? now())->startOfDay();
                                    $nights  = max(1, (int) ($get('../../nights') ?: 1));
                                    $end     = ($get('../../expected_departure'))
                                        ? Carbon::parse($get('../../expected_departure'))->setTime(12, 0)
                                        : $arrival->copy()->addDays($nights)->setTime(12, 0);

                                    $rows  = collect($get('../../reservationGuests') ?? []);
                                    $used  = $rows->pluck('room_id')->filter()->all();
                                    $self  = $get('room_id');
                                    $blockLocal = array_values(array_diff($used, array_filter([$self])));

                                    $q = Room::query()->where('hotel_id', $hotelId);

                                    // fallback sederhana: exclude room yg di-block pada window tsb
                                    $q->whereDoesntHave('blocks', function ($b) use ($arrival, $end) {
                                        $b->where(function ($w) use ($arrival, $end) {
                                            $w->where('start_at', '<', $end)
                                                ->where('end_at',   '>', $arrival);
                                        });
                                    });

                                    return $q->when($blockLocal, fn($qq) => $qq->whereNotIn('id', $blockLocal))
                                        ->orderBy('room_no')
                                        ->pluck('room_no', 'id')
                                        ->toArray();
                                })
                                ->columnSpan(4),

                            // Pilih guest (create option mengikuti skema id_card)
                            FSelect::make('guest_id')
                                ->label('Guest')
                                ->placeholder('Select')
                                ->searchable()
                                ->preload()
                                ->reactive()
                                ->rules(['distinct'])
                                ->options(function ($get) {
                                    $hotelId = Session::get('active_hotel_id');

                                    $rows  = collect($get('../../reservationGuests') ?? []);
                                    $used  = $rows->pluck('guest_id')->filter()->all();
                                    $self  = $get('guest_id');
                                    $block = array_values(array_diff($used, array_filter([$self])));

                                    return Guest::query()
                                        ->where('hotel_id', $hotelId)
                                        ->when($block, fn($q) => $q->whereNotIn('id', $block))
                                        ->orderBy('name')
                                        ->pluck('name', 'id')
                                        ->toArray();
                                })
                                ->createOptionForm([
                                    Section::make('Guest Info')->schema([
                                        Grid::make(12)->schema([
                                            FSelect::make('salutation')
                                                ->label('Title')
                                                ->options(['MR' => 'MR', 'MRS' => 'MRS', 'MISS' => 'MISS'])
                                                ->native(false)
                                                ->columnSpan(3),

                                            TextInput::make('name')
                                                ->label('Name')
                                                ->required()
                                                ->columnSpan(9),

                                            TextInput::make('address')->label('Address')->columnSpan(12),

                                            TextInput::make('city')->label('City')->columnSpan(4),
                                            TextInput::make('profession')->label('Profession')->columnSpan(4),

                                            // Skema baru: id_type + id_card + id_card_file
                                            FSelect::make('id_type')
                                                ->label('Identity Type')
                                                ->options([
                                                    'KTP'      => 'KTP',
                                                    'PASSPORT' => 'Passport',
                                                    'SIM'      => 'SIM',
                                                    'OTHER'    => 'Other',
                                                ])
                                                ->native(false)
                                                ->columnSpan(3),

                                            TextInput::make('id_card')
                                                ->label('Identity Number')
                                                ->columnSpan(5),

                                            FileUpload::make('id_card_file')
                                                ->label('Attach ID (JPG/PNG/PDF)')
                                                ->directory('guests/id')
                                                ->disk('public')
                                                ->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])
                                                ->maxSize(4096)
                                                ->downloadable()
                                                ->openable()
                                                ->columnSpan(4),

                                            // Dates
                                            DatePicker::make('birth_date')->label('Birth Date')->native(false)->columnSpan(6),
                                            DatePicker::make('issued_date')->label('Issued Date')->native(false)->columnSpan(6),

                                            // Contacts
                                            TextInput::make('phone')->label('Phone No')->columnSpan(6),
                                            TextInput::make('email')->label('Email')->email()->columnSpan(6),

                                            Hidden::make('hotel_id')
                                                ->default(fn() => Session::get('active_hotel_id'))
                                                ->dehydrated(true)
                                                ->required(),
                                        ]),
                                    ]),
                                ])
                                ->columnSpan(8),
                        ]),
                ]),

            Section::make('Period')
                ->schema([
                    Grid::make(12)->schema([
                        DateTimePicker::make('expected_arrival')
                            ->label('Arrival')
                            ->native(false)
                            ->seconds(false)
                            ->dehydrated(false) // UI only
                            ->default(fn(Get $get) => \Illuminate\Support\Carbon::parse(
                                $get('expected_arrival') ?: now()
                            )->startOfDay()->addDays(max(1, (int) ($get('nights') ?: 1)))->setTime(12, 0))
                            ->afterStateHydrated(function (Set $set, Get $get, $state) {
                                if (blank($state)) {
                                    $arr = \App\Filament\Resources\Reservations\Schemas\ReservationForm::parseDt($get('expected_arrival') ?: now())->startOfDay();
                                    $set('expected_departure', $arr->copy()->addDays(max(1, (int) ($get('nights') ?: 1)))->setTime(12, 0));
                                }
                            })
                            ->columnSpan(4),

                        TextInput::make('nights')
                            ->label('Nights')
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->dehydrated(false)
                            ->live()
                            ->required()
                            ->afterStateHydrated(function ($set, $get) {
                                $arr = $get('expected_arrival');
                                $dep = $get('expected_departure');
                                if ($arr && $dep) {
                                    $arrival   = Carbon::parse($arr)->startOfDay();
                                    $departure = Carbon::parse($dep)->startOfDay();
                                    $set('nights', max(1, $arrival->diffInDays($departure)));
                                }
                            })
                            ->afterStateUpdated(function ($set, $get, $state) {
                                $nights  = max(1, (int) $state);
                                $arrival = Carbon::parse($get('expected_arrival') ?? now())->startOfDay();
                                $dep     = $arrival->copy()->addDays($nights)->setTime(12, 0);

                                $set('expected_departure', $dep);
                                self::syncGridWithPeriod($set, $get);
                            })
                            ->columnSpan(4),

                        DateTimePicker::make('expected_departure')
                            ->label('Departure')
                            ->disabled()
                            ->native(false)
                            ->seconds(false)
                            ->readonly()
                            ->columnSpan(4)
                            ->default(fn($get) => Carbon::parse($get('expected_arrival') ?? now())
                                ->startOfDay()
                                ->addDays(max(1, (int) ($get('nights') ?: 1)))
                                ->setTime(12, 0))
                            ->minDate(fn($get) => $get('expected_arrival')
                                ? Carbon::parse($get('expected_arrival'))->startOfDay()
                                : null)
                            ->live()
                            // NOTE: JANGAN simpan ke DB; ini hanya kontrol UI untuk sinkron grid
                            ->dehydrated(false),
                    ]),
                ]),

            // === Grid preview (tidak disimpan ke DB) ===
            Section::make('Information Guest & Room Assignment')
                ->schema([
                    Repeater::make('reservationGrid')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->columns(12)
                        ->dehydrated(false) // preview only (diproses manual ke reservation_guests saat save)
                        ->addable(false)
                        ->reorderable(false)
                        ->deletable(false)
                        ->schema([
                            Hidden::make('room_id')
                                ->reactive(), // agar afterStateHydrated/Updated di room_rate bisa baca room_id terbaru
                            Hidden::make('guest_id'),

                            TextInput::make('room_no')->label('ROOM')->disabled()->columnSpan(2),
                            TextInput::make('category')->label('CATEGORY')->disabled()->columnSpan(2),

                            // RATE: auto dari harga kamar, tapi tetap bisa di-edit
                            TextInput::make('room_rate')
                                ->label('RATE')
                                ->numeric()
                                ->mask(RawJs::make('$money($input)'))
                                ->stripCharacters([','])
                                ->reactive()
                                ->live()
                                // isi saat pertama render (kalau kosong) dari harga kamar terpilih
                                ->afterStateHydrated(function ($set, $get, $state) {
                                    if (blank($state) && $get('room_id')) {
                                        $price = \App\Models\Room::find($get('room_id'))?->price;
                                        if (! is_null($price)) {
                                            $set('room_rate', $price);
                                        }
                                    }
                                })
                                // jika room_id diubah (karena repeater utama berubah & grid rehydrate), isi ulang bila kosong
                                ->afterStateUpdated(function ($set, $get, $state) {
                                    if (blank($state) && $get('room_id')) {
                                        $price = \App\Models\Room::find($get('room_id'))?->price;
                                        if (! is_null($price)) {
                                            $set('room_rate', $price);
                                        }
                                    }
                                })
                                ->columnSpan(2),

                            // PAX: read-only, auto = male + female + children
                            TextInput::make('jumlah_orang')
                                ->label('PAX')
                                ->disabled()
                                ->numeric()
                                ->default(1)
                                ->minValue(1)
                                // hitung saat pertama render
                                ->afterStateHydrated(function ($set, $get) {
                                    $sum = (int)($get('male') ?? 0) + (int)($get('female') ?? 0) + (int)($get('children') ?? 0);
                                    $set('jumlah_orang', max(1, $sum));
                                })
                                ->columnSpan(1),

                            TextInput::make('guest_name')->label('GUEST NAME')->disabled()->columnSpan(3),

                            DateTimePicker::make('dept')
                                ->label('DEPT')
                                ->native(false)
                                ->seconds(false)
                                ->reactive()
                                ->live()
                                ->default(fn($get) => \Illuminate\Support\Carbon::parse($get('../../expected_departure') ?? now())->setTime(12, 0))
                                ->afterStateHydrated(function ($set, $get, $state) {
                                    if (blank($state)) {
                                        $set('dept', \Illuminate\Support\Carbon::parse($get('../../expected_departure') ?? now())->setTime(12, 0));
                                    }
                                })
                                ->dehydrated(false)
                                ->columnSpan(2),

                            // ====== Tambahan sesuai model ReservationGuest ======
                            TextInput::make('person')
                                ->label('PERSON IN CHARGE')
                                ->columnSpan(3),

                            TextInput::make('male')
                                ->label('MALE')
                                ->numeric()
                                ->default(1)
                                ->minValue(0)
                                ->reactive()
                                ->live()
                                ->afterStateUpdated(function ($set, $get) {
                                    $sum = (int)($get('male') ?? 0) + (int)($get('female') ?? 0) + (int)($get('children') ?? 0);
                                    $set('jumlah_orang', max(1, $sum));
                                })
                                ->columnSpan(1),

                            TextInput::make('female')
                                ->label('FEMALE')
                                ->numeric()
                                ->default(0)
                                ->minValue(0)
                                ->reactive()
                                ->live()
                                ->afterStateUpdated(function ($set, $get) {
                                    $sum = (int)($get('male') ?? 0) + (int)($get('female') ?? 0) + (int)($get('children') ?? 0);
                                    $set('jumlah_orang', max(1, $sum));
                                })
                                ->columnSpan(1),

                            TextInput::make('children')
                                ->label('CHILDREN')
                                ->numeric()
                                ->default(0)
                                ->minValue(0)
                                ->reactive()
                                ->live()
                                ->afterStateUpdated(function ($set, $get) {
                                    $sum = (int)($get('male') ?? 0) + (int)($get('female') ?? 0) + (int)($get('children') ?? 0);
                                    $set('jumlah_orang', max(1, $sum));
                                })
                                ->columnSpan(1),

                            Select::make('charge_to')
                                ->label('CHARGE TO')
                                ->placeholder('Select')
                                ->default('GUEST')
                                ->options([
                                    'GUEST'   => 'GUEST',
                                    'COMPANY' => 'COMPANY',
                                    'AGENCY'  => 'AGENCY',
                                    'OTHER'   => 'OTHER',
                                ])
                                ->native(false)
                                ->columnSpan(2),

                            Textarea::make('note')
                                ->label('NOTE')
                                ->rows(1)
                                ->columnSpan(4),
                        ]),
                ])->columnSpanFull(),
        ]);
    }

    /**
     * Sinkronkan preview grid dengan periode header (UI only).
     * NOTE: tidak menyimpan apa pun ke DB.
     */
    private static function syncGridWithPeriod($set, $get): void
    {
        $arrival = Carbon::parse($get('expected_arrival') ?? now())->startOfDay();
        $nights  = max(1, (int) ($get('nights') ?: 1));
        $dept    = ($get('expected_departure'))
            ? Carbon::parse($get('expected_departure'))->setTime(12, 0)
            : $arrival->copy()->addDays($nights)->setTime(12, 0);

        $rows = $get('reservationGrid') ?? [];

        foreach (array_keys($rows) as $i) {
            $set("reservationGrid.$i.arrival", $arrival->copy());
            $set("reservationGrid.$i.dept",    $dept->copy());

            if (isset($rows[$i]['time'])) {
                $set("reservationGrid.$i.time", $arrival->format('H:i'));
            }
        }
    }
    // taruh di dalam class ReservationForm (mis. di bawah syncGridWithPeriod)
    public static function parseDt($value): \Illuminate\Support\Carbon
    {
        // Carbon instance
        if ($value instanceof \Carbon\CarbonInterface) {
            return \Illuminate\Support\Carbon::parse($value->toDateTimeString());
        }

        // Array dari Livewire/Filament: ['2025-09-13T12:00:00+08:00', {...}]
        if (is_array($value) && isset($value[0])) {
            return \Illuminate\Support\Carbon::parse($value[0]);
        }

        // String / null
        return \Illuminate\Support\Carbon::parse($value ?: now());
    }
}
