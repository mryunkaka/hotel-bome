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
use Illuminate\Support\Facades\Log;
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
            // Information
            // ===========================
            Section::make('Information')
                ->schema([
                    Grid::make(12)->schema([
                        Select::make('created_by')
                            ->label('Entry By')
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
                            ->default(fn() => now()->setTime(12, 0))
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
                            ->live()
                            ->columnSpan(12),

                        // GUEST Section
                        Select::make('guest_id')
                            ->required(fn(Get $get) => $get('reserved_by_type') === 'GUEST')
                            ->visible(fn(Get $get) => $get('reserved_by_type') === 'GUEST')
                            ->live()
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
                            ->live()
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

                            // PENTING: Calculate total people - PASTIKAN TIDAK NULL
                            $male = (int) preg_replace('/\D+/', '', (string) ($data['male'] ?? '0'));
                            $female = (int) preg_replace('/\D+/', '', (string) ($data['female'] ?? '0'));
                            $children = (int) preg_replace('/\D+/', '', (string) ($data['children'] ?? '0'));
                            $data['jumlah_orang'] = max(1, $male + $female + $children);

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

                        ->mutateRelationshipDataBeforeSaveUsing(function (array $data): array {
                            // Hotel ID
                            $data['hotel_id'] = $data['hotel_id'] ?? Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;

                            // PENTING: Calculate total people - PASTIKAN TIDAK NULL
                            $male = (int) preg_replace('/\D+/', '', (string) ($data['male'] ?? '0'));
                            $female = (int) preg_replace('/\D+/', '', (string) ($data['female'] ?? '0'));
                            $children = (int) preg_replace('/\D+/', '', (string) ($data['children'] ?? '0'));
                            $data['jumlah_orang'] = max(1, $male + $female + $children);

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
                                        $currentGuestId = (int) ($get('guest_id') ?? 0);

                                        // Ambil semua guest_id yang sudah dipilih di repeater ini (kecuali current)
                                        $selectedGuestIds = array_filter(
                                            array_map('intval', $get('../../reservationGuests.*.guest_id') ?? []),
                                            fn($id) => $id > 0 && $id !== $currentGuestId
                                        );

                                        $rows = \App\Models\Guest::query()
                                            ->where(function ($q) use ($hid, $selectedGuestIds, $currentGuestId) {   // <-- ditambahkan wrapper where()
                                                $q->where('hotel_id', $hid)
                                                    ->when(!empty($selectedGuestIds), fn($qq) => $qq->whereNotIn('id', $selectedGuestIds)) // exclude yang sudah dipilih
                                                    ->whereNotExists(function ($sub) use ($hid) {
                                                        // exclude yang sedang menginap (checkin ada, checkout belum)
                                                        $sub->from('reservation_guests as rg')
                                                            ->whereColumn('rg.guest_id', 'guests.id')
                                                            ->where('rg.hotel_id', $hid)
                                                            ->whereNotNull('rg.actual_checkin')
                                                            ->whereNull('rg.actual_checkout');
                                                    });

                                                if ($currentGuestId > 0) {                                         // <-- ini yang ditambahkan
                                                    $q->orWhere('id', $currentGuestId);                             // selalu sertakan value saat ini
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
                                    ->getSearchResultsUsing(function (string $search, Get $get) {
                                        $hid = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;
                                        $currentGuestId = (int) ($get('guest_id') ?? 0);

                                        $selectedGuestIds = array_filter(
                                            array_map('intval', $get('../../reservationGuests.*.guest_id') ?? []),
                                            fn($id) => $id > 0 && $id !== $currentGuestId
                                        );

                                        $s = trim(preg_replace('/\s+/', ' ', $search));

                                        $rows = \App\Models\Guest::query()
                                            ->where(function ($q) use ($hid, $selectedGuestIds, $currentGuestId, $s) { // <-- wrapper where()
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
                                                            ->whereNotNull('rg.actual_checkin')
                                                            ->whereNull('rg.actual_checkout');
                                                    });

                                                if ($currentGuestId > 0) {                                         // <-- ini yang ditambahkan
                                                    $q->orWhere('id', $currentGuestId);                             // tetap tampilkan value saat ini
                                                }
                                            })
                                            ->orderBy('name')
                                            ->limit(50)
                                            ->get(['id', 'name', 'id_card']);

                                        return $rows->mapWithKeys(function ($g) {
                                            $idCard = trim((string) ($g->id_card ?? ''));
                                            $label = $g->name . ($idCard !== '' && $idCard !== '-' ? " ({$idCard})" : '');
                                            return [$g->id => $label];
                                        })->toArray();
                                    })
                                    ->getOptionLabelUsing(function ($value): ?string {                                // <-- ini yang ditambahkan
                                        if (! $value) return null;
                                        $g = \App\Models\Guest::query()->select('name', 'id_card')->find($value);
                                        if (! $g) return null;
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

                                // Discount (2/12)
                                TextInput::make('discount_percent')
                                    ->label('Discount (%)')
                                    ->numeric()
                                    ->step('0.01')
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->default(0)
                                    ->columnSpan(2),

                                // Service & Extra Bed
                                TextInput::make('service')
                                    ->label('Service')
                                    ->numeric()
                                    ->default(0)
                                    ->columnSpan(3),

                                TextInput::make('extra_bed')
                                    ->label('E. Bed')
                                    ->numeric()
                                    ->default(0)
                                    ->columnSpan(2),

                                Select::make('breakfast')
                                    ->label('Breakfast')
                                    ->options(['Yes' => 'Yes', 'No' => 'No'])
                                    ->default('Yes')
                                    ->columnSpan(2),

                                TextInput::make('person')
                                    ->label('Person Charge')
                                    ->columnSpan(3),

                                // Pax - TAMBAHKAN live() untuk auto-calculate jumlah_orang
                                TextInput::make('male')
                                    ->label('Male')
                                    ->numeric()
                                    ->default(1)
                                    ->minValue(0)
                                    ->live()
                                    ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                        // Auto calculate jumlah_orang
                                        $male = (int) ($get('male') ?? 0);
                                        $female = (int) ($get('female') ?? 0);
                                        $children = (int) ($get('children') ?? 0);
                                        $set('jumlah_orang', max(1, $male + $female + $children));
                                    })
                                    ->columnSpan(2),

                                TextInput::make('female')
                                    ->label('Female')
                                    ->numeric()
                                    ->default(0)
                                    ->minValue(0)
                                    ->live()
                                    ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                        // Auto calculate jumlah_orang
                                        $male = (int) ($get('male') ?? 0);
                                        $female = (int) ($get('female') ?? 0);
                                        $children = (int) ($get('children') ?? 0);
                                        $set('jumlah_orang', max(1, $male + $female + $children));
                                    })
                                    ->columnSpan(2),

                                TextInput::make('children')
                                    ->label('Children')
                                    ->numeric()
                                    ->default(0)
                                    ->minValue(0)
                                    ->live()
                                    ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                        // Auto calculate jumlah_orang
                                        $male = (int) ($get('male') ?? 0);
                                        $female = (int) ($get('female') ?? 0);
                                        $children = (int) ($get('children') ?? 0);
                                        $set('jumlah_orang', max(1, $male + $female + $children));
                                    })
                                    ->columnSpan(2),

                                // HIDDEN jumlah_orang
                                Hidden::make('jumlah_orang')
                                    ->default(1)
                                    ->dehydrated(),

                                TextInput::make('pov')
                                    ->label('Purpose of Visit')
                                    ->maxLength(150)
                                    ->columnSpan(4),

                                TextInput::make('note')
                                    ->label('Note')
                                    ->maxLength(150)
                                    ->columnSpan(4),
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

                                    // Redirect ke halaman check-in
                                    $url = "https://hotel-bome.test/admin/reservation-guests/{$id}/edit";

                                    // JavaScript untuk membuka di tab baru
                                    $livewire->js(<<<JS
                                        window.open("{$url}", "_blank", "noopener,noreferrer");
                                    JS);
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
