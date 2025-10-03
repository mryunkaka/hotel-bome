<?php

namespace App\Filament\Resources\Reservations\Schemas;

use App\Models\Room;
use App\Models\Reservation;
use Filament\Support\RawJs;
use Illuminate\Support\Arr;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Filament\Forms\Components\Radio;
use Illuminate\Support\Facades\Auth;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Repeater;
use Illuminate\Support\Facades\Session;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\DateTimePicker;
use Filament\Schemas\Components\Utilities\Get;

class ReservationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            // ===========================
            // Reservation Info
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
                            ->columnSpan(3),

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
                            ->columnSpan(3),

                        Select::make('status')
                            ->label('Status')
                            ->options([
                                'CONFIRM'   => 'Confirm',
                                'TENTATIVE' => 'Tentative',
                                'CANCELLED' => 'Cancelled',
                            ])
                            ->default('CONFIRM')
                            ->required()
                            ->columnSpan(3),

                        TextInput::make('deposit_room')
                            ->label('Room Deposit')
                            ->mask(RawJs::make('$money($input)'))
                            ->stripCharacters(',')
                            ->numeric()
                            ->default(0)
                            ->columnSpan(3)
                            ->helperText('DP saat membuat reservasi.'),
                    ]),
                ])
                ->columnSpanFull(),

            // ===========================
            // Information
            // ===========================
            Section::make('Information')
                ->schema([
                    Grid::make(12)->schema([
                        Select::make('created_by')
                            ->label('Created By')
                            ->relationship('creator', 'name')
                            ->default(fn() => Auth::id())
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
                            ->default(fn() => now()->setTime(13, 0))
                            ->seconds(false)
                            ->columnSpan(5),

                        TextInput::make('nights')
                            ->label('Nights')
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->required()
                            ->columnSpan(3),

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
            // Reserved By
            // ===========================
            Section::make('Reserved By')
                ->schema([
                    Grid::make(12)->schema([
                        Radio::make('reserved_by_type')
                            ->label('Reserved By Type')
                            ->options(['GUEST' => 'Guest', 'GROUP' => 'Group'])
                            ->default('GUEST')
                            ->columnSpan(12),

                        // GUEST Section
                        Select::make('guest_id')
                            ->required(fn(Get $get) => $get('reserved_by_type') === 'GUEST')
                            ->visible(fn(Get $get) => $get('reserved_by_type') === 'GUEST')
                            ->label('Guest')
                            ->native(false)
                            ->searchable()
                            ->options(function (Get $get) {
                                $hid = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;

                                // === AMBIL ID YANG SUDAH DIPILIH DI REPEATER ===
                                $currentGuestId = (int) ($get('guest_id') ?? 0);
                                $idsFromState = $get('reservationGuests.*.guest_id') ?? []; // bisa kosong di konteks header
                                //ini yang ditambahkan: fallback baca dari request data.* lalu flatten
                                if (empty($idsFromState)) {
                                    $idsFromState = \Illuminate\Support\Arr::flatten(
                                        (array) \Illuminate\Support\Arr::get(request()->input(), 'data.reservationGuests.*.guest_id', [])
                                    );
                                }
                                $selectedGuestIds = array_filter(
                                    array_map('intval', $idsFromState),
                                    fn($id) => $id > 0 && $id !== $currentGuestId
                                );

                                // === QUERY: EXCLUDE yg sudah dipilih & yg masih aktif; sertakan current value agar tidak hilang saat edit ===
                                $rows = \App\Models\Guest::query()
                                    ->where(function ($q) use ($hid, $selectedGuestIds, $currentGuestId) {
                                        $q->where('hotel_id', $hid)
                                            ->when(!empty($selectedGuestIds), fn($qq) => $qq->whereNotIn('id', $selectedGuestIds))
                                            ->whereNotExists(function ($sub) use ($hid) {
                                                $sub->from('reservation_guests as rg')
                                                    ->whereColumn('rg.guest_id', 'guests.id')
                                                    ->where('rg.hotel_id', $hid)
                                                    //ini yang ditambahkan: cukup cek belum checkout → aktif (termasuk masih reservasi)
                                                    ->whereNull('rg.actual_checkout');
                                            });

                                        //ini yang ditambahkan: selalu tampilkan current guest saat edit
                                        if ($currentGuestId > 0) {
                                            $q->orWhere('id', $currentGuestId);
                                        }
                                    })
                                    ->orderBy('name')
                                    ->limit(200)
                                    ->get(['id', 'name', 'id_card']);

                                return $rows->mapWithKeys(function ($g) {
                                    $idCard = trim((string) ($g->id_card ?? ''));
                                    $label = $g->name . ($idCard !== '' && $idCard !== '-' ? " ({$idCard})" : '');
                                    return [$g->id => $label];
                                })->toArray();
                            })
                            ->getSearchResultsUsing(function (string $search, Get $get) { //ini yang ditambahkan
                                $hid = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;

                                $currentGuestId = (int) ($get('guest_id') ?? 0); //ini yang ditambahkan
                                $idsFromState = $get('reservationGuests.*.guest_id') ?? []; //ini yang ditambahkan
                                if (empty($idsFromState)) { //ini yang ditambahkan
                                    $idsFromState = \Illuminate\Support\Arr::flatten(
                                        (array) \Illuminate\Support\Arr::get(request()->input(), 'data.reservationGuests.*.guest_id', [])
                                    );
                                }
                                $selectedGuestIds = array_filter( //ini yang ditambahkan
                                    array_map('intval', $idsFromState),
                                    fn($id) => $id > 0 && $id !== $currentGuestId
                                );

                                $s = trim(preg_replace('/\s+/', ' ', $search)); //ini yang ditambahkan

                                $rows = \App\Models\Guest::query() //ini yang ditambahkan
                                    ->where(function ($q) use ($hid, $selectedGuestIds, $currentGuestId, $s) {
                                        $q->where('hotel_id', $hid)
                                            ->when(!empty($selectedGuestIds), fn($qq) => $qq->whereNotIn('id', $selectedGuestIds))
                                            ->where(function ($qq) use ($s) {
                                                $qq->where('name', 'like', "%{$s}%")
                                                    ->orWhere('phone', 'like', "%{$s}%")
                                                    ->orWhere('id_card', 'like', "%{$s}%");
                                            })
                                            ->whereNotExists(function ($sub) use ($hid) {
                                                $sub->from('reservation_guests as rg')
                                                    ->whereColumn('rg.guest_id', 'guests.id')
                                                    ->where('rg.hotel_id', $hid)
                                                    //ini yang ditambahkan: exclude semua yang belum checkout
                                                    ->whereNull('rg.actual_checkout');
                                            });

                                        if ($currentGuestId > 0) { //ini yang ditambahkan
                                            $q->orWhere('id', $currentGuestId);
                                        }
                                    })
                                    ->orderBy('name')
                                    ->limit(50)
                                    ->get(['id', 'name', 'id_card']);

                                return $rows->mapWithKeys(function ($g) { //ini yang ditambahkan
                                    $idCard = trim((string) ($g->id_card ?? ''));
                                    $label = $g->name . ($idCard !== '' && $idCard !== '-' ? " ({$idCard})" : '');
                                    return [$g->id => $label];
                                })->toArray();
                            })
                            //ini yang ditambahkan: jaga label saat value sudah terisi
                            ->getOptionLabelUsing(function ($value): ?string {
                                if (! $value) return null;
                                $g = \App\Models\Guest::query()->select('name', 'id_card')->find($value);
                                if (! $g) return null;
                                $idCard = trim((string) ($g->id_card ?? ''));
                                return $g->name . ($idCard !== '' && $idCard !== '-' ? " ({$idCard})" : '');
                            })
                            ->createOptionForm([
                                Section::make('Guest Info')->schema([
                                    Grid::make(12)->schema([
                                        Select::make('salutation')->label('Title')
                                            ->options(['MR' => 'MR', 'MRS' => 'MRS', 'MISS' => 'MISS'])
                                            ->default("MR")
                                            ->native(false)->columnSpan(2),
                                        TextInput::make('name')->label('Name')->required()->maxLength(150)->columnSpan(4),
                                        Select::make('guest_type')->label('Guest Type')
                                            ->options(['DOMESTIC' => 'Domestic', 'INTERNATIONAL' => 'International'])
                                            ->default("DOMESTIC")
                                            ->native(false)->columnSpan(3),
                                        TextInput::make('nationality')->label('Nationality')->maxLength(50)->default('Indonesia')->columnSpan(3),
                                        TextInput::make('address')->label('Address')->maxLength(255)->columnSpan(12),
                                        TextInput::make('city')->label('City')->maxLength(50)->columnSpan(4),
                                        TextInput::make('profession')->label('Profession')->maxLength(50)->columnSpan(4),
                                        Select::make('id_type')->label('Identity Type')->options([
                                            'ID' => 'National ID',
                                            'PASSPORT' => 'Passport',
                                            'DRIVER_LICENSE' => 'Driver License',
                                            'OTHER' => 'Other',
                                        ])->native(false)->default('ID')->columnSpan(4),
                                        TextInput::make('id_card')->label('Identity Number')->maxLength(100)->columnSpan(4),
                                        TextInput::make('phone')->label('Phone No')->maxLength(50)->columnSpan(4),
                                        TextInput::make('email')->label('Email')->email()->maxLength(150)->columnSpan(4),
                                        // ⬇️ Tambahan: Birth & Issued
                                        TextInput::make('birth_place')->label('Birth Place')->maxLength(100)->columnSpan(6),
                                        DatePicker::make('birth_date')->label('Birth Date')->columnSpan(6),

                                        TextInput::make('issued_place')->label('Issued Place')->maxLength(100)->columnSpan(6),
                                        DatePicker::make('issued_date')->label('Issued Date')->columnSpan(6),
                                        Hidden::make('hotel_id')->default(fn() => Session::get('active_hotel_id') ?? Auth::user()?->hotel_id),
                                    ]),
                                ]),
                            ])
                            ->createOptionUsing(fn(array $data) => \App\Models\Guest::create($data)->id)
                            ->columnSpan(12),

                        // GROUP Section
                        Select::make('group_id')
                            ->required(fn(Get $get) => $get('reserved_by_type') === 'GROUP')
                            ->visible(fn(Get $get) => $get('reserved_by_type') === 'GROUP')
                            ->label('Group')
                            ->native(false)
                            ->searchable()
                            ->options(function () {
                                $hotelId = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;
                                $groups = \App\Models\ReservationGroup::query()
                                    ->when($hotelId, fn($q) => $q->where('hotel_id', $hotelId))
                                    ->orderBy('name')
                                    ->limit(200)
                                    ->get(['id', 'name', 'city']);
                                return $groups->mapWithKeys(function ($g) {
                                    $city = $g->city ? " ({$g->city})" : '';
                                    return [$g->id => $g->name . $city];
                                })->toArray();
                            })
                            ->createOptionForm([
                                TextInput::make('name')->label('Group Name')->required(),
                                TextInput::make('address')->label('Address'),
                                TextInput::make('city')->label('City'),
                                TextInput::make('phone')->label('Phone'),
                                TextInput::make('email')->label('Email')->email(),
                                Hidden::make('hotel_id')->default(fn() => Session::get('active_hotel_id') ?? Auth::user()?->hotel_id),
                            ])
                            ->createOptionUsing(fn(array $data) => \App\Models\ReservationGroup::create($data)->id)
                            ->columnSpan(12),
                    ]),
                ]),

            // ===========================
            // Room Assignment & Guest Details (PERBAIKAN HOOK)
            // ===========================
            Section::make('Room Assignment & Guest Details')
                ->schema([
                    Repeater::make('reservationGuests')
                        ->relationship('reservationGuests')
                        ->columnSpanFull()
                        ->columns(12)

                        // PERBAIKAN: Gunakan KEDUA hook untuk cover semua kasus
                        ->mutateRelationshipDataBeforeCreateUsing(function (array $data): array {
                            // Hotel ID
                            $data['hotel_id'] = $data['hotel_id'] ?? Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;

                            // Sync dates from header (server-side; no live)
                            $parentArrival    = \Illuminate\Support\Arr::get(request()->input(), 'data.expected_arrival');
                            $parentDeparture  = \Illuminate\Support\Arr::get(request()->input(), 'data.expected_departure');
                            $parentNights     = \Illuminate\Support\Arr::get(request()->input(), 'data.nights');

                            // Jika header belum menyuplai departure, hitung dari arrival + nights
                            if (!$parentDeparture && $parentArrival && $parentNights) {
                                $parentDeparture = \Illuminate\Support\Carbon::parse($parentArrival)
                                    ->copy()->startOfDay()
                                    ->addDays(max(1, (int) $parentNights))
                                    ->setTime(12, 0);
                            }

                            // Isi ke baris repeater bila kosong
                            if (empty($data['expected_checkin']) && $parentArrival) {
                                $data['expected_checkin'] = \Illuminate\Support\Carbon::parse($parentArrival);
                            }
                            if (empty($data['expected_checkout']) && $parentDeparture) {
                                $data['expected_checkout'] = \Illuminate\Support\Carbon::parse($parentDeparture);
                            }


                            // Auto room rate
                            if (empty($data['room_rate']) && !empty($data['room_id'])) {
                                $price = Room::whereKey($data['room_id'])->value('price');
                                $data['room_rate'] = (int) ($price ?? 0);
                            }

                            // Normalize numeric fields
                            $data['room_rate'] = (int) ($data['room_rate'] ?? 0);
                            $data['discount_percent'] = (float) ($data['discount_percent'] ?? 0);
                            $data['service'] = (int) ($data['service'] ?? 0);
                            $data['extra_bed'] = (int) ($data['extra_bed'] ?? 0);

                            return $data;
                        })

                        ->mutateRelationshipDataBeforeSaveUsing(function (array $data): array {
                            // Hotel ID
                            $data['hotel_id'] = $data['hotel_id'] ?? Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;

                            // Sync dates from header
                            $parentArrival = \Illuminate\Support\Arr::get(request()->input(), 'data.expected_arrival');
                            $parentDeparture = \Illuminate\Support\Arr::get(request()->input(), 'data.expected_departure');

                            if (empty($data['expected_checkin']) && $parentArrival) {
                                $data['expected_checkin'] = Carbon::parse($parentArrival);
                            }
                            if (empty($data['expected_checkout']) && $parentDeparture) {
                                $data['expected_checkout'] = Carbon::parse($parentDeparture);
                            }

                            // Default dates
                            if (empty($data['expected_checkin'])) {
                                $data['expected_checkin'] = now()->setTime(12, 0);
                            }
                            if (empty($data['expected_checkout'])) {
                                $data['expected_checkout'] = Carbon::parse($data['expected_checkin'])->addDay()->setTime(12, 0);
                            }

                            // Auto room rate
                            if (empty($data['room_rate']) && !empty($data['room_id'])) {
                                $price = Room::whereKey($data['room_id'])->value('price');
                                $data['room_rate'] = (int) ($price ?? 0);
                            }

                            // Normalize numeric fields
                            $data['room_rate'] = (int) ($data['room_rate'] ?? 0);
                            $data['discount_percent'] = (float) ($data['discount_percent'] ?? 0);
                            $data['service'] = (int) ($data['service'] ?? 0);
                            $data['extra_bed'] = (int) ($data['extra_bed'] ?? 0);

                            return $data;
                        })

                        ->schema([
                            Grid::make(12)->schema([
                                Hidden::make('id'),

                                // Room (3/12) - ENHANCED dengan smart filtering
                                Select::make('room_id')
                                    ->label('Room')
                                    ->native(false)
                                    ->placeholder('Select Room')
                                    ->searchable()
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                        // Auto fill room rate
                                        if ($state && empty($get('room_rate'))) {
                                            $price = Room::whereKey($state)->value('price');
                                            if ($price !== null) {
                                                $set('room_rate', (int) $price);
                                            }
                                        }
                                    })
                                    ->options(function (Get $get) {
                                        $hid = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;
                                        $currentRoomId = (int) ($get('room_id') ?? 0);

                                        // Ambil semua room_id yang sudah dipilih di repeater ini (kecuali current)
                                        $selectedRoomIds = array_filter(
                                            array_map('intval', $get('../../reservationGuests.*.room_id') ?? []),
                                            fn($id) => $id > 0 && $id !== $currentRoomId
                                        );

                                        return \App\Models\Room::query()
                                            ->where(function ($q) use ($hid, $selectedRoomIds, $currentRoomId) {
                                                // Cabang 1: daftar normal (VCI + tidak terpilih + tidak sedang ditempati)
                                                $q->where('hotel_id', $hid)
                                                    ->where('status', 'VCI')
                                                    ->when(!empty($selectedRoomIds), fn($qq) => $qq->whereNotIn('id', $selectedRoomIds))
                                                    ->whereNotExists(function ($sub) use ($hid) {
                                                        $sub->from('reservation_guests as rg')
                                                            ->whereColumn('rg.room_id', 'rooms.id')
                                                            ->where('rg.hotel_id', $hid)
                                                            ->whereNotNull('rg.actual_checkin')
                                                            ->whereNull('rg.actual_checkout');
                                                    });

                                                // Cabang 2: SELALU sertakan kamar yang sedang dipakai (apa pun statusnya)
                                                if ($currentRoomId > 0) {
                                                    $q->orWhere('id', $currentRoomId);
                                                }
                                            })
                                            ->orderBy('room_no')
                                            ->limit(200)
                                            ->pluck('room_no', 'id');
                                    })
                                    ->getSearchResultsUsing(function (string $search, Get $get) {
                                        $hid = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;
                                        $currentRoomId = (int) ($get('room_id') ?? 0);

                                        $selectedRoomIds = array_filter(
                                            array_map('intval', $get('../../reservationGuests.*.room_id') ?? []),
                                            fn($id) => $id > 0 && $id !== $currentRoomId
                                        );

                                        return \App\Models\Room::query()
                                            ->where(function ($q) use ($hid, $selectedRoomIds, $currentRoomId, $search) {
                                                // Cabang 1: hasil cari untuk VCI
                                                $q->where('hotel_id', $hid)
                                                    ->where('status', 'VCI')
                                                    ->where('room_no', 'like', "%{$search}%")
                                                    ->when(!empty($selectedRoomIds), fn($qq) => $qq->whereNotIn('id', $selectedRoomIds))
                                                    ->whereNotExists(function ($sub) use ($hid) {
                                                        $sub->from('reservation_guests as rg')
                                                            ->whereColumn('rg.room_id', 'rooms.id')
                                                            ->where('rg.hotel_id', $hid)
                                                            ->whereNotNull('rg.actual_checkin')
                                                            ->whereNull('rg.actual_checkout');
                                                    });

                                                // Cabang 2: tetap tampilkan kamar yang sedang dipakai
                                                if ($currentRoomId > 0) {
                                                    $q->orWhere('id', $currentRoomId);
                                                }
                                            })
                                            ->orderBy('room_no')
                                            ->limit(50)
                                            ->pluck('room_no', 'id');
                                    })
                                    ->getOptionLabelUsing(function ($value): ?string {
                                        return $value ? \App\Models\Room::whereKey($value)->value('room_no') : null;
                                    })
                                    ->columnSpan(3),

                                // Guest (5/12) - ENHANCED dengan smart filtering
                                Select::make('guest_id')
                                    ->label('Guest')
                                    ->placeholder('Select Guest')
                                    ->native(false)
                                    ->searchable()
                                    ->required()
                                    ->options(function (Get $get) {
                                        $hid = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;

                                        // id yang sedang terpilih di item ini
                                        $currentGuestId = (int) ($get('guest_id') ?? 0);

                                        // 🔸 ambil semua rows repeater lalu pluck guest_id
                                        $rows = $get('../../reservationGuests') ?? [];
                                        $idsFromState = array_map(
                                            'intval',
                                            array_filter(array_column(is_array($rows) ? $rows : [], 'guest_id') ?? [])
                                        );

                                        // fallback awal render (saat state belum terhydrate)
                                        if (empty($idsFromState)) {
                                            $idsFromReq = Arr::flatten((array) Arr::get(request()->input(), 'data.reservationGuests.*.guest_id', []));
                                            $idsFromState = array_map('intval', array_filter($idsFromReq ?? []));
                                        }

                                        // exclude id yang sudah dipilih di baris lain
                                        $selectedGuestIds = array_filter($idsFromState, fn($id) => $id > 0 && $id !== $currentGuestId);

                                        $rows = \App\Models\Guest::query()
                                            ->where(function ($q) use ($hid, $selectedGuestIds, $currentGuestId) {
                                                $q->where('hotel_id', $hid)
                                                    // jangan tampilkan yang sudah dipilih di item lain
                                                    ->when(!empty($selectedGuestIds), fn($qq) => $qq->whereNotIn('id', $selectedGuestIds))
                                                    // jangan tampilkan guest yang punya RG BELUM checkout (aktif / in-house / masih reserved)
                                                    ->whereNotExists(function ($sub) use ($hid) {
                                                        $sub->from('reservation_guests as rg')
                                                            ->whereColumn('rg.guest_id', 'guests.id')
                                                            ->where('rg.hotel_id', $hid)
                                                            ->whereNull('rg.actual_checkout');
                                                    });

                                                // tetap tampilkan value saat ini agar tidak hilang ketika edit
                                                if ($currentGuestId > 0) {
                                                    $q->orWhere('id', $currentGuestId);
                                                }
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
                                        $hid = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;

                                        $currentGuestId = (int) ($get('guest_id') ?? 0);

                                        // 🔸 sama seperti di options(): kumpulkan guest_id dari semua baris repeater
                                        $rows = $get('../../reservationGuests') ?? [];
                                        $idsFromState = array_map(
                                            'intval',
                                            array_filter(array_column(is_array($rows) ? $rows : [], 'guest_id') ?? [])
                                        );
                                        if (empty($idsFromState)) {
                                            $idsFromReq = \Illuminate\Support\Arr::flatten(
                                                (array) \Illuminate\Support\Arr::get(request()->input(), 'data.reservationGuests.*.guest_id', [])
                                            );
                                            $idsFromState = array_map('intval', array_filter($idsFromReq ?? []));
                                        }
                                        $selectedGuestIds = array_filter($idsFromState, fn($id) => $id > 0 && $id !== $currentGuestId);

                                        $s = trim(preg_replace('/\s+/', ' ', $search));

                                        $rows = \App\Models\Guest::query()
                                            ->where(function ($q) use ($hid, $selectedGuestIds, $currentGuestId, $s) {
                                                $q->where('hotel_id', $hid)
                                                    ->when(!empty($selectedGuestIds), fn($qq) => $qq->whereNotIn('id', $selectedGuestIds))
                                                    ->where(function ($qq) use ($s) {
                                                        $qq->where('name', 'like', "%{$s}%")
                                                            ->orWhere('phone', 'like', "%{$s}%")
                                                            ->orWhere('id_card', 'like', "%{$s}%");
                                                    })
                                                    ->whereNotExists(function ($sub) use ($hid) {
                                                        $sub->from('reservation_guests as rg')
                                                            ->whereColumn('rg.guest_id', 'guests.id')
                                                            ->where('rg.hotel_id', $hid)
                                                            ->whereNull('rg.actual_checkout');
                                                    });

                                                if ($currentGuestId > 0) {
                                                    $q->orWhere('id', $currentGuestId);
                                                }
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
                                    ->getOptionLabelUsing(function ($value): ?string {
                                        if (!$value) return null;
                                        $g = \App\Models\Guest::query()->select('name', 'id_card')->find($value);
                                        if (!$g) return null;
                                        $idCard = trim((string) ($g->id_card ?? ''));
                                        return $g->name . ($idCard !== '' && $idCard !== '-' ? " ({$idCard})" : '');
                                    })
                                    ->columnSpan(5),

                                // Rate (2/12)
                                TextInput::make('room_rate')
                                    ->label('Rate')
                                    ->numeric()
                                    ->mask(RawJs::make('$money($input)'))
                                    ->stripCharacters(',')
                                    ->placeholder('Auto from room')
                                    ->minValue(0)
                                    ->columnSpan(2),

                                TextInput::make('extra_bed')
                                    ->label('Ektra Bed')
                                    ->numeric()
                                    ->default(0)
                                    ->columnSpan(2),

                                Select::make('breakfast')
                                    ->label('Breakfast')
                                    ->options(['Yes' => 'Yes', 'No' => 'No'])
                                    ->default('Yes')
                                    ->columnSpan(2),

                                Select::make('person')
                                    ->label('Charge To')
                                    ->options([
                                        'PERSONAL ACCOUNT'   => 'Personal Account',
                                        'COMPANY ACCOUNT' => 'Company Account',
                                        'TRAVEL AGENT' => 'Travel Agent',
                                        'OL TRAVEL AGENT' => 'OL Travel Agent',
                                        'COMPLIMENTARY' => 'Complimentary',
                                    ])
                                    ->default('PERSONAL ACCOUNT')
                                    ->columnSpan(2),

                                // Pax - TAMBAHKAN live() untuk auto-calculate jumlah_orang
                                // Pax (TIDAK live)
                                TextInput::make('male')
                                    ->label('Male')
                                    ->numeric()
                                    ->default(1)
                                    ->minValue(0)
                                    ->columnSpan(2),

                                TextInput::make('female')
                                    ->label('Female')
                                    ->numeric()
                                    ->default(0)
                                    ->minValue(0)
                                    ->columnSpan(2),

                                TextInput::make('children')
                                    ->label('Children')
                                    ->numeric()
                                    ->default(0)
                                    ->minValue(0)
                                    ->columnSpan(2),

                                Hidden::make('jumlah_orang')
                                    ->default(1)
                                    ->dehydrated(),

                                Select::make('pov')
                                    ->label('Purpose of Visit')
                                    ->options([
                                        'BUSINESS'   => 'Business',
                                        'OFFICIAL' => 'Official',
                                        'TRANSIENT' => 'Transient',
                                        'VACATION' => 'Vacation',
                                    ])
                                    ->default('BUSINESS')
                                    ->columnSpan(4),

                                TextInput::make('note')
                                    ->label('Note')
                                    ->maxLength(150)
                                    ->columnSpan(8),
                            ])->columnSpanFull(),
                        ])

                        ->deletable(false)
                        ->addActionLabel('Add Room Assignment')
                        ->itemLabel(
                            fn(array $state): ?string =>
                            !empty($state['room_id'])
                                ? 'Room: ' . \App\Models\Room::find($state['room_id'])?->room_no
                                : 'New Assignment'
                        )

                        // TAMBAHAN: Action buttons untuk Check-in dan Delete
                        ->extraItemActions([
                            // Check-in Button

                            Action::make('check_in')
                                ->label('Check In')
                                ->color('success')
                                ->icon('heroicon-o-arrow-right-on-rectangle')
                                ->visible(function (array $arguments, $livewire) {
                                    $itemKey = data_get($arguments, 'item');
                                    $id = (int) data_get($livewire, "data.reservationGuests.{$itemKey}.id");

                                    if ($id <= 0) {
                                        return false;
                                    }

                                    // Ambil dari state repeater apakah sudah ada checkout
                                    $actualCheckout = data_get($livewire, "data.reservationGuests.{$itemKey}.actual_checkout");

                                    // Hanya tampil jika BELUM checkout
                                    return empty($actualCheckout);
                                })
                                ->action(function (array $arguments, $livewire) {
                                    // Ambil ID dari state repeater
                                    $itemKey = data_get($arguments, 'item');
                                    $id = (int) data_get($livewire, "data.reservationGuests.{$itemKey}.id");

                                    if (!$id) {
                                        Notification::make()
                                            ->title('Error')
                                            ->body('Record belum tersimpan. Silakan save terlebih dahulu.')
                                            ->danger()
                                            ->send();
                                        return;
                                    }

                                    // Redirect ke halaman check-in (DI TAB YANG SAMA)
                                    $url = "https://hotel-bome.test/admin/reservation-guests/{$id}/edit";

                                    // ⬇️ GANTI: jangan open tab baru; pakai SPA navigate kalau ada, fallback ke in-tab redirect
                                    if (method_exists($livewire, 'redirect')) {
                                        $livewire->redirect($url, navigate: true);
                                    } else {
                                        $livewire->js("window.location.href = '{$url}';");
                                    }
                                }),

                            // Delete Button (sudah ada default, tapi kita custom untuk konfirmasi)
                            Action::make('delete_assignment')
                                ->label('Delete')
                                ->color('danger')
                                ->icon('heroicon-o-trash')
                                ->requiresConfirmation()
                                ->modalHeading('Delete Room Assignment?')
                                ->modalDescription('This action cannot be undone.')
                                ->action(function (array $arguments, $livewire) {
                                    $itemKey = data_get($arguments, 'item');
                                    $id = (int) data_get($livewire, "data.reservationGuests.{$itemKey}.id");

                                    if ($id) {
                                        // Hapus dari database jika sudah tersimpan
                                        \App\Models\ReservationGuest::find($id)?->delete();
                                    }

                                    // Hapus dari state repeater
                                    $items = (array) data_get($livewire, 'data.reservationGuests', []);
                                    unset($items[$itemKey]);
                                    data_set($livewire, 'data.reservationGuests', $items);

                                    Notification::make()
                                        ->title('Deleted')
                                        ->body('Room assignment has been deleted.')
                                        ->success()
                                        ->send();
                                }),
                        ]),
                ])
                ->columnSpanFull(),
        ]);
    }
}
