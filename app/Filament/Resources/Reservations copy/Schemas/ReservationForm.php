<?php

namespace App\Filament\Resources\Reservations\Schemas;

use App\Models\Room;
use App\Models\Guest;
use App\Models\Reservation;
use Filament\Support\RawJs;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
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
    /** Set hanya jika berubah → mencegah re-render berulang saat mengetik cepat */
    private static function setIfChanged(Set $set, Get $get, string $path, $value): void
    {
        if ($get($path) !== $value) {
            $set($path, $value);
            Log::info('[setIfChanged] updated', ['path' => $path, 'value' => $value]);
        }
    }

    public static function configure(Schema $schema): Schema
    {
        if (! function_exists('buildGridFromRows')) {
            /**
             * Bangun isi grid info (preview) dari repeater `reservationGuests`.
             * - Baris kosong total diabaikan.
             * - RATE selalu integer rupiah.
             * - Depart default dari header (jam 12:00).
             */
            function buildGridFromRows(Get $get, array $rows): array
            {
                $rows = collect($rows)->filter(function ($r) {
                    $male       = (int) ($r['male'] ?? 0);
                    $female     = (int) ($r['female'] ?? 0);
                    $children   = (int) ($r['children'] ?? 0);
                    $chargeTo   = $r['charge_to'] ?? null;
                    $rateEmpty  = ($r['room_rate'] ?? null) === null || $r['room_rate'] === '';

                    return !(
                        empty($r['room_id']) &&
                        empty($r['guest_id']) &&
                        ! filled($r['person']) &&
                        $male === 0 && $female === 0 && $children === 0 &&
                        (empty($chargeTo) || $chargeTo === 'GUEST') &&
                        empty($r['note']) &&
                        $rateEmpty
                    );
                })->values()->all();

                if (empty($rows)) {
                    Log::info('[GridBuilder] rows kosong setelah filter');
                    return [];
                }

                $prev = $get('../../reservationGrid') ?? [];

                $roomIds  = collect($rows)->pluck('room_id')->filter()->unique()->values();
                $guestIds = collect($rows)->pluck('guest_id')->filter()->unique()->values();

                $rooms  = $roomIds->isNotEmpty()  ? Room::whereIn('id', $roomIds)->get()->keyBy('id')  : collect();
                $guests = $guestIds->isNotEmpty() ? Guest::whereIn('id', $guestIds)->get()->keyBy('id') : collect();

                $arrival   = ReservationForm::parseDt($get('../../expected_arrival') ?? now())->startOfDay();
                $nights    = max(1, (int) ($get('../../nights') ?: 1));
                $depState  = $get('../../expected_departure');
                $defaultDt = $depState
                    ? ReservationForm::parseDt($depState)->setTime(12, 0)
                    : $arrival->copy()->addDays($nights)->setTime(12, 0);

                return collect($rows)->values()->map(function ($r, $idx) use ($rooms, $guests, $arrival, $defaultDt, $prev) {
                    $room    = $rooms[$r['room_id'] ?? 0] ?? null;
                    $guest   = $guests[$r['guest_id'] ?? 0] ?? null;
                    $prevRow = $prev[$idx] ?? [];

                    $male     = (int)($r['male']     ?? $prevRow['male']     ?? 0);
                    $female   = (int)($r['female']   ?? $prevRow['female']   ?? 0);
                    $children = (int)($r['children'] ?? $prevRow['children'] ?? 0);
                    $jumlah   = max(1, $male + $female + $children);

                    // RATE → integer rupiah
                    $src      = 'from_room';
                    $roomRate = null;
                    if (($r['room_rate'] ?? null) !== null && $r['room_rate'] !== '') {
                        $src      = 'from_repeater';
                        $roomRate = ReservationForm::toIntMoney($r['room_rate']);
                    } elseif (($prevRow['room_rate'] ?? null) !== null && $prevRow['room_rate'] !== '') {
                        $src      = 'from_prev';
                        $roomRate = ReservationForm::toIntMoney($prevRow['room_rate']);
                    } elseif ($room?->price !== null) {
                        $roomRate = ReservationForm::toIntMoney($room->price);
                    }

                    Log::info('[GridBuilder] set room_rate', [
                        'row_idx'       => $idx,
                        'source'        => $src,
                        'from_repeater' => $r['room_rate']       ?? null,
                        'from_prev'     => $prevRow['room_rate'] ?? null,
                        'from_room'     => $room?->price,
                        'final'         => $roomRate,
                    ]);

                    $deptRaw = $r['expected_checkout'] ?? ($prevRow['dept'] ?? null);

                    return [
                        'room_id'      => $r['room_id'] ?? null,
                        'guest_id'     => $r['guest_id'] ?? null,
                        'room_no'      => $room?->room_no,
                        'category'     => $room?->type,
                        'room_rate'    => $roomRate,

                        'jumlah_orang' => $jumlah,
                        'guest_name'   => $guest?->name ?? ($prevRow['guest_name'] ?? null),

                        'person'       => $prevRow['person'] ?? ($r['person'] ?? null),
                        'male'         => $male,
                        'female'       => $female,
                        'children'     => $children,
                        'charge_to'    => $prevRow['charge_to'] ?? ($r['charge_to'] ?? null),
                        'note'         => $prevRow['note'] ?? ($r['note'] ?? null),

                        'arrival'      => $prevRow['arrival'] ?? $arrival->copy(),
                        'dept'         => $deptRaw ? ReservationForm::parseDt($deptRaw) : $defaultDt->copy(),
                    ];
                })->all();
            }
        }

        return $schema->components([
            // ===========================
            // Reservation Info
            // ===========================
            Section::make('Reservation Info')
                ->schema([
                    Grid::make(12)->schema([
                        Hidden::make('reservation_no')
                            ->dehydrated(true)
                            ->default(fn() => Reservation::nextReservationNo(Session::get('active_hotel_id'))),

                        Hidden::make('created_by')
                            ->default(fn() => Auth::id())
                            ->dehydrated(true)
                            ->required(),

                        Hidden::make('entry_date')
                            ->default(fn($record) => $record?->entry_date ?? now())
                            ->dehydrated(true)
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
                            ->default('WALKIN')->required()->native(false)->columnSpan(2),

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
                            ->default('PERSONAL')->required()->native(false)->columnSpan(2),

                        Select::make('status')
                            ->label('Status')
                            ->options([
                                'CONFIRM'   => 'Confirm',
                                'TENTATIVE' => 'Tentative',
                                'CANCELLED' => 'Cancelled',
                            ])
                            ->default('CONFIRM')->required()->native(false)->columnSpan(2),

                        Radio::make('deposit_type')
                            ->label('Deposit Type')
                            ->options(['DP' => 'Room Deposit', 'CARD' => 'Deposit Card'])
                            ->inline()->default('DP')->live()->columnSpan(3),

                        TextInput::make('deposit')
                            ->label(fn(Get $get) => $get('deposit_type') === 'CARD' ? 'Deposit Card' : 'Room Deposit')
                            ->numeric()->default(0)->columnSpan(3)->mask(RawJs::make('$money($input)'))
                            ->stripCharacters(','),
                    ]),
                ])
                ->columnSpanFull(),

            // ===========================
            // Information ringkasan
            // ===========================
            Section::make('Information')
                ->schema([
                    Grid::make(12)->schema([
                        TextInput::make('reservation_no_view')
                            ->label('Reservation No')->disabled()->dehydrated(false)
                            ->afterStateHydrated(function (Set $set, Get $get) {
                                $no = $get('reservation_no') ?? Reservation::nextReservationNo(Session::get('active_hotel_id'));
                                self::setIfChanged($set, $get, 'reservation_no_view', $no);
                            })
                            ->extraAttributes(['class' => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200'])
                            ->columnSpan(4),

                        TextInput::make('created_by_view')
                            ->label('Entry By')->disabled()->dehydrated(false)
                            ->afterStateHydrated(function (Set $set, Get $get) {
                                $uid  = $get('created_by');
                                $name = optional(\App\Models\User::find($uid))->name ?? Auth::user()?->name;
                                self::setIfChanged($set, $get, 'created_by_view', $name);
                            })
                            ->extraAttributes(['class' => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200'])
                            ->columnSpan(4),

                        DateTimePicker::make('entry_date_view')
                            ->label('Entry Date')->seconds(false)->native(false)->disabled()->dehydrated(false)
                            ->afterStateHydrated(function (Set $set, Get $get) {
                                $val = $get('entry_date') ?? now();
                                self::setIfChanged($set, $get, 'entry_date_view', $val);
                            })
                            ->extraAttributes(['class' => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200'])
                            ->columnSpan(4),

                        DateTimePicker::make('expected_arrival')
                            ->label('Arrival')
                            ->native(false)->seconds(false)
                            ->required()
                            ->default(fn() => now())
                            ->live()
                            ->afterStateHydrated(function (Set $set, Get $get, $state) {
                                if (blank($state)) {
                                    self::setIfChanged($set, $get, 'expected_arrival', now());
                                }
                                $arr    = ReservationForm::parseDt($get('expected_arrival') ?: now())->startOfDay();
                                $nights = max(1, (int) ($get('nights') ?: 1));
                                self::setIfChanged($set, $get, 'expected_departure', $arr->copy()->addDays($nights)->setTime(12, 0));
                            })
                            ->afterStateUpdated(function (Set $set, Get $get, $state) {
                                $arr    = ReservationForm::parseDt($state)->startOfDay();
                                $nights = max(1, (int) ($get('nights') ?: 1));
                                self::setIfChanged($set, $get, 'expected_departure', $arr->copy()->addDays($nights)->setTime(12, 0));
                                self::syncGridWithPeriod($set, $get);
                            })
                            ->columnSpan(4),

                        TextInput::make('nights')
                            ->label('Nights')->numeric()->minValue(1)
                            ->default(1)->dehydrated(false)->live(onBlur: true)->required()
                            ->afterStateHydrated(function (Set $set, Get $get) {
                                $arr = $get('expected_arrival');
                                $dep = $get('expected_departure');
                                if ($arr && $dep) {
                                    $arrival   = ReservationForm::parseDt($arr)->startOfDay();
                                    $departure = ReservationForm::parseDt($dep)->startOfDay();
                                    self::setIfChanged($set, $get, 'nights', max(1, $arrival->diffInDays($departure)));
                                }
                            })
                            ->afterStateUpdated(function (Set $set, Get $get, $state) {
                                $nights  = max(1, (int) $state);
                                $arrival = ReservationForm::parseDt($get('expected_arrival') ?? now())->startOfDay();
                                $dep     = $arrival->copy()->addDays($nights)->setTime(12, 0);
                                self::setIfChanged($set, $get, 'expected_departure', $dep);
                                self::syncGridWithPeriod($set, $get);
                            })
                            ->columnSpan(4),

                        DateTimePicker::make('expected_departure')
                            ->label('Departure')
                            ->disabled()->native(false)->seconds(false)->readonly()
                            ->columnSpan(4)
                            ->default(function (Get $get) {
                                return ReservationForm::parseDt($get('expected_arrival') ?: now())
                                    ->startOfDay()
                                    ->addDays(max(1, (int) ($get('nights') ?: 1)))
                                    ->setTime(12, 0);
                            })
                            ->minDate(fn(Get $get) => $get('expected_arrival')
                                ? ReservationForm::parseDt($get('expected_arrival'))->startOfDay()
                                : null)
                            ->live()
                            ->dehydrated(true)
                            ->dehydrateStateUsing(fn($state) => ReservationForm::parseDt($state)->setTime(12, 0)),
                    ]),
                ]),

            // ===========================
            // Reserved By
            // ===========================
            Section::make('Reserved By')
                ->schema([
                    Grid::make(12)->schema([
                        Select::make('reserved_title')
                            ->label('Title')
                            ->options(['MR' => 'MR', 'MRS' => 'MRS', 'MISS' => 'MISS'])
                            ->native(false)
                            ->visible(fn(Get $get) => ($get('reserved_by_type') ?? 'GUEST') !== 'GROUP')
                            ->required(fn(Get $get) => ($get('reserved_by_type') ?? 'GUEST') === 'GUEST')
                            ->columnSpan(6),

                        TextInput::make('reserved_by')
                            ->label('Name')
                            ->visible(fn(Get $get) => ($get('reserved_by_type') ?? 'GUEST') === 'GUEST')
                            ->required(fn(Get $get) => ($get('reserved_by_type') ?? 'GUEST') === 'GUEST')
                            ->live(onBlur: true)
                            ->columnSpan(6),

                        TextInput::make('reserved_number')
                            ->label('No. HP/WA')
                            ->visible(fn(Get $get) => ($get('reserved_by_type') ?? 'GUEST') === 'GUEST')
                            ->required(fn(Get $get) => ($get('reserved_by_type') ?? 'GUEST') === 'GUEST')
                            ->live(onBlur: true)
                            ->columnSpan(12),

                        Radio::make('reserved_by_type')
                            ->label('Reserved By Type')
                            ->options(['GUEST' => 'Guest', 'GROUP' => 'Group'])
                            ->default('GUEST')->inline()->live()
                            ->afterStateHydrated(function (Set $set, $state, Get $get) {
                                if (blank($state)) {
                                    self::setIfChanged($set, $get, 'reserved_by_type', $get('group_id') ? 'GROUP' : 'GUEST');
                                }
                            })
                            ->afterStateUpdated(function (Set $set, $state) {
                                if ($state === 'GROUP') {
                                    $set('reserved_title', null);
                                    $set('reserved_by', null);
                                    $set('reserved_number', null);
                                } else {
                                    $set('group_id', null);
                                }
                            })
                            ->columnSpan(12),

                        FSelect::make('group_id')
                            ->label('Group')
                            ->relationship(
                                name: 'group',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn($q) => $q->where('hotel_id', Session::get('active_hotel_id'))
                            )
                            ->hidden(fn() => ! self::hasGroupSupport())
                            ->visible(fn(Get $get) => self::hasGroupSupport() && (
                                ($get('reserved_by_type') === 'GROUP') || filled($get('group_id'))
                            ))
                            ->required(fn(Get $get) => self::hasGroupSupport() && $get('reserved_by_type') === 'GROUP')
                            ->searchable()->preload()
                            ->createOptionForm([
                                Section::make('Group Details')
                                    ->schema([
                                        Grid::make(12)->schema([
                                            TextInput::make('name')->label('Group Name')->required()->live(onBlur: true)->columnSpan(6),
                                            TextInput::make('phone')->label('Phone')->live(onBlur: true)->columnSpan(3),
                                            TextInput::make('handphone')->label('Handphone')->live(onBlur: true)->columnSpan(3),

                                            TextInput::make('address')->label('Address')->live(onBlur: true)->columnSpan(12),
                                            TextInput::make('city')->label('City')->live(onBlur: true)->columnSpan(4),
                                            TextInput::make('fax')->label('Fax')->live(onBlur: true)->columnSpan(4),
                                            TextInput::make('email')->label('Email')->email()->live(onBlur: true)->columnSpan(4),

                                            TextInput::make('remark_ci')->label('Remark CI')->live(onBlur: true)->columnSpan(12),
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

            // ===========================
            // Assign Room & Guest
            // ===========================
            Section::make('Assign Room & Guest')
                ->schema([
                    Hidden::make('hotel_id')
                        ->default(fn() => Session::get('active_hotel_id'))
                        ->dehydrated(true)
                        ->required(),

                    Repeater::make('reservationGuests')
                        ->relationship('reservationGuests')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->columns(12)
                        ->reorderable(false)
                        ->addActionLabel('Tambah tamu')
                        ->live()
                        ->mutateRelationshipDataBeforeCreateUsing(function (array $data): array {
                            Log::info('[Repeater][BeforeCreate]', $data);
                            $data['hotel_id'] = $data['hotel_id'] ?? (Session::get('active_hotel_id') ?? Auth::user()?->hotel_id);
                            return $data;
                        })
                        ->mutateRelationshipDataBeforeSaveUsing(function (array $data): array {
                            Log::info('[Repeater][BeforeSave]', $data);
                            $data['hotel_id'] = $data['hotel_id'] ?? (Session::get('active_hotel_id') ?? Auth::user()?->hotel_id);
                            return $data;
                        })
                        ->afterStateHydrated(function (Set $set, Get $get, ?array $state) {
                            $rows = $state ?? [];

                            $isRowEmpty = function ($r): bool {
                                if (! is_array($r)) return true;
                                $male     = (int) ($r['male'] ?? 0);
                                $female   = (int) ($r['female'] ?? 0);
                                $children = (int) ($r['children'] ?? 0);
                                $rateEmpty = ($r['room_rate'] ?? null) === null || $r['room_rate'] === '';
                                return empty($r['room_id']) && empty($r['guest_id']) && ! filled($r['person'])
                                    && $male === 0 && $female === 0 && $children === 0
                                    && (empty($r['charge_to']) || $r['charge_to'] === 'GUEST')
                                    && empty($r['note']) && $rateEmpty;
                            };

                            if (count($rows) === 0) {
                                $rows = [[]];
                                $set('reservationGuests', $rows);
                            }
                            if (count($rows) > 1 && collect($rows)->every($isRowEmpty)) {
                                $rows = [$rows[0]];
                                $set('reservationGuests', $rows);
                            }

                            if (blank($get('../../reservationGrid'))) {
                                $grid = buildGridFromRows($get, $rows);
                                $set('reservationGrid', $grid);
                                Log::info('[Repeater][Hydrated → BuildGrid]', ['rows' => count($rows), 'grid' => $grid]);
                            }
                        })
                        ->afterStateUpdated(function (Set $set, Get $get, ?array $state) {
                            $grid = buildGridFromRows($get, $state ?? []);
                            $set('reservationGrid', $grid);
                            Log::info('[Repeater][Updated → BuildGrid]', ['rows' => is_array($state) ? count($state) : null, 'grid' => $grid]);
                        })
                        ->schema([
                            Hidden::make('hotel_id')
                                ->default(fn() => Session::get('active_hotel_id') ?? Auth::user()?->hotel_id)
                                ->dehydrated(true)
                                ->required(),

                            Hidden::make('expected_checkin')->dehydrated(true),
                            Hidden::make('expected_checkout')->dehydrated(true),

                            Hidden::make('person')->dehydrated(true),
                            Hidden::make('male')->default(0)->dehydrated(true),
                            Hidden::make('female')->default(0)->dehydrated(true),
                            Hidden::make('children')->default(0)->dehydrated(true),
                            Hidden::make('jumlah_orang')->default(1)->dehydrated(true),
                            Hidden::make('charge_to')->default('GUEST')->dehydrated(true),

                            Hidden::make('room_rate')->dehydrated(true),

                            Hidden::make('note')->dehydrated(true),
                            Hidden::make('actual_checkin')->dehydrated(true),
                            Hidden::make('actual_checkout')->dehydrated(true),

                            FSelect::make('room_id')
                                ->label('Room')->placeholder('Select')
                                ->searchable()->preload()->reactive()
                                ->rules(['distinct'])
                                ->options(function (Get $get) {
                                    $hotelId = Session::get('active_hotel_id');

                                    $arrival = ReservationForm::parseDt(
                                        $get('../../expected_arrival') ?? now()
                                    )->startOfDay();

                                    $nights = max(1, (int) ($get('../../nights') ?: 1));
                                    $end    = ($get('../../expected_departure'))
                                        ? ReservationForm::parseDt($get('../../expected_departure'))->setTime(12, 0)
                                        : $arrival->copy()->addDays($nights)->setTime(12, 0);

                                    $rows  = collect($get('../../reservationGuests') ?? []);
                                    $used  = $rows->pluck('room_id')->filter()->all();
                                    $self  = $get('room_id');
                                    $blockLocal = array_values(array_diff($used, array_filter([$self])));

                                    $q = Room::query()->where('hotel_id', $hotelId);

                                    if (method_exists(Room::class, 'blocks')) {
                                        $q->whereDoesntHave('blocks', function ($b) use ($arrival, $end) {
                                            $b->where(function ($w) use ($arrival, $end) {
                                                $w->where('start_at', '<', $end)
                                                    ->where('end_at',   '>', $arrival);
                                            });
                                        });
                                    }

                                    return $q->when($blockLocal, fn($qq) => $qq->whereNotIn('id', $blockLocal))
                                        ->orderBy('room_no')->pluck('room_no', 'id')->toArray();
                                })
                                ->afterStateUpdated(function (Set $set, Get $get) {
                                    $rows = $get('../../reservationGuests') ?? [];
                                    $grid = buildGridFromRows($get, $rows);
                                    $set('../../reservationGrid', $grid);
                                    Log::info('[RoomSelect][Updated → BuildGrid]', ['grid' => $grid]);
                                })
                                ->columnSpan(4),

                            FSelect::make('guest_id')
                                ->label('Guest')->placeholder('Select')
                                ->searchable()->preload()->reactive()
                                ->rules(['distinct'])
                                ->options(function (Get $get) {
                                    $hotelId = Session::get('active_hotel_id');

                                    $rows  = collect($get('../../reservationGuests') ?? []);
                                    $used  = $rows->pluck('guest_id')->filter()->all();
                                    $self  = $get('guest_id');
                                    $block = array_values(array_diff($used, array_filter([$self])));

                                    return Guest::query()
                                        ->where('hotel_id', $hotelId)
                                        ->when($block, fn($q) => $q->whereNotIn('id', $block))
                                        ->orderBy('name')->pluck('name', 'id')->toArray();
                                })
                                ->createOptionForm([
                                    Section::make('Guest Info')
                                        ->schema([
                                            Grid::make(12)->schema([
                                                FSelect::make('salutation')->label('Title')
                                                    ->options(['MR' => 'MR', 'MRS' => 'MRS', 'MISS' => 'MISS'])
                                                    ->native(false)->columnSpan(3),

                                                TextInput::make('name')->label('Name')->required()->live(onBlur: true)->columnSpan(9),
                                                TextInput::make('address')->label('Address')->live(onBlur: true)->columnSpan(12),

                                                TextInput::make('city')->label('City')->live(onBlur: true)->columnSpan(4),
                                                TextInput::make('profession')->label('Profession')->live(onBlur: true)->columnSpan(4),

                                                FSelect::make('id_type')->label('Identity Type')
                                                    ->options(['KTP' => 'KTP', 'PASSPORT' => 'Passport', 'SIM' => 'SIM', 'OTHER' => 'Other'])
                                                    ->native(false)->columnSpan(3),

                                                TextInput::make('id_card')->label('Identity Number')->live(onBlur: true)->columnSpan(5),

                                                FileUpload::make('id_card_file')->label('Attach ID (JPG/PNG/PDF)')
                                                    ->directory('guests/id')->disk('public')
                                                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])
                                                    ->maxSize(4096)->downloadable()->openable()->columnSpan(4),

                                                DatePicker::make('birth_date')->label('Birth Date')->native(false)->columnSpan(6),
                                                DatePicker::make('issued_date')->label('Issued Date')->native(false)->columnSpan(6),

                                                TextInput::make('phone')->label('Phone No')->live(onBlur: true)->columnSpan(6),
                                                TextInput::make('email')->label('Email')->email()->live(onBlur: true)->columnSpan(6),

                                                Hidden::make('hotel_id')
                                                    ->default(fn() => Session::get('active_hotel_id'))
                                                    ->dehydrated(true)->required(),
                                            ]),
                                        ]),
                                ])
                                ->columnSpan(8),
                        ]),
                ])
                ->columnSpanFull(),

            // ===========================
            // Information Guest & Room Assignment (preview / editable)
            // ===========================
            Section::make('Information Guest & Room Assignment')
                ->schema([
                    Repeater::make('reservationGrid')
                        ->hiddenLabel()
                        ->columns(12)
                        ->dehydrated(false)
                        ->addable(false)
                        ->reorderable(false)
                        ->deletable(false)
                        ->afterStateHydrated(function (Set $set, Get $get, ?array $state) {
                            self::syncGridToGuests($set, $get);
                        })
                        ->afterStateUpdated(function (Set $set, Get $get, ?array $state) {
                            self::syncGridToGuests($set, $get);
                        })
                        ->schema([
                            Hidden::make('room_id')->reactive(),
                            Hidden::make('guest_id'),

                            TextInput::make('room_no')->label('ROOM')->disabled()->columnSpan(2),
                            TextInput::make('category')->label('CATEGORY')->disabled()->columnSpan(2),

                            TextInput::make('room_rate')
                                ->label('RATE')
                                ->numeric()->rule('integer')->extraInputAttributes(['step' => 1])
                                ->live(onBlur: true)
                                ->afterStateHydrated(function (Set $set, Get $get, $state) {
                                    Log::info('[RATE][Hydrated]', ['state' => $state, 'room_id' => $get('room_id')]);
                                    if (($state === null || $state === '') && $get('room_id')) {
                                        $price = Room::find($get('room_id'))?->price;
                                        Log::info('[RATE][Hydrated][DefaultFromRoom]', ['room_id' => $get('room_id'), 'price' => $price]);
                                        if ($price !== null && $price !== '') {
                                            self::setIfChanged($set, $get, 'room_rate', ReservationForm::toIntMoney($price));
                                        }
                                    }
                                })
                                ->afterStateUpdated(function (Set $set, Get $get, $state) {
                                    Log::info('[RATE][Updated]', ['new' => $state]);
                                    self::syncGridToGuests($set, $get);
                                })
                                ->mask(RawJs::make('$money($input)'))
                                ->stripCharacters(',')
                                ->columnSpan(2),

                            TextInput::make('jumlah_orang')
                                ->label('PAX')->disabled()->numeric()->default(1)->minValue(1)
                                ->afterStateHydrated(function (Set $set, Get $get) {
                                    $sum = (int)($get('male') ?? 0) + (int)($get('female') ?? 0) + (int)($get('children') ?? 0);
                                    self::setIfChanged($set, $get, 'jumlah_orang', max(1, $sum));
                                })
                                ->columnSpan(1),

                            TextInput::make('guest_name')->label('GUEST NAME')->disabled()->columnSpan(3),

                            DateTimePicker::make('dept')
                                ->label('DEPT')->native(false)->seconds(false)
                                ->reactive()->live()
                                ->default(fn(Get $get) => Carbon::parse($get('../../expected_departure') ?? now())->setTime(12, 0))
                                ->afterStateHydrated(function (Set $set, Get $get, $state) {
                                    if (blank($state)) {
                                        self::setIfChanged($set, $get, 'dept', Carbon::parse($get('../../expected_departure') ?? now())->setTime(12, 0));
                                    }
                                    self::syncGridToGuests($set, $get);
                                })
                                ->afterStateUpdated(fn(Set $set, Get $get) => self::syncGridToGuests($set, $get))
                                ->dehydrated(false)
                                ->columnSpan(2),

                            TextInput::make('person')->label('PERSON IN CHARGE')
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn(Set $set, Get $get) => self::syncGridToGuests($set, $get))
                                ->columnSpan(3),

                            TextInput::make('male')->label('MALE')->numeric()->default(1)->minValue(0)
                                ->extraInputAttributes(['step' => 1])
                                ->live(onBlur: true)
                                ->afterStateUpdated(function (Set $set, Get $get) {
                                    $sum = (int)($get('male') ?? 0) + (int)($get('female') ?? 0) + (int)($get('children') ?? 0);
                                    self::setIfChanged($set, $get, 'jumlah_orang', max(1, $sum));
                                    self::syncGridToGuests($set, $get);
                                })
                                ->columnSpan(1),

                            TextInput::make('female')->label('FEMALE')->numeric()->default(0)->minValue(0)
                                ->extraInputAttributes(['step' => 1])
                                ->live(onBlur: true)
                                ->afterStateUpdated(function (Set $set, Get $get) {
                                    $sum = (int)($get('male') ?? 0) + (int)($get('female') ?? 0) + (int)($get('children') ?? 0);
                                    self::setIfChanged($set, $get, 'jumlah_orang', max(1, $sum));
                                    self::syncGridToGuests($set, $get);
                                })
                                ->columnSpan(1),

                            TextInput::make('children')->label('CHILDREN')->numeric()->default(0)->minValue(0)
                                ->extraInputAttributes(['step' => 1])
                                ->live(onBlur: true)
                                ->afterStateUpdated(function (Set $set, Get $get) {
                                    $sum = (int)($get('male') ?? 0) + (int)($get('female') ?? 0) + (int)($get('children') ?? 0);
                                    self::setIfChanged($set, $get, 'jumlah_orang', max(1, $sum));
                                    self::syncGridToGuests($set, $get);
                                })
                                ->columnSpan(1),

                            Select::make('charge_to')->label('CHARGE TO')->placeholder('Select')->default('GUEST')
                                ->options(['GUEST' => 'GUEST', 'COMPANY' => 'COMPANY', 'AGENCY' => 'AGENCY', 'OTHER' => 'OTHER'])
                                ->native(false)->columnSpan(2)
                                ->reactive()->live()
                                ->afterStateUpdated(fn(Set $set, Get $get) => self::syncGridToGuests($set, $get)),

                            Textarea::make('note')->label('NOTE')->rows(1)->columnSpan(4)
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn(Set $set, Get $get) => self::syncGridToGuests($set, $get)),
                        ]),
                ])->columnSpanFull(),
        ]);
    }

    /** Sinkron periode header → grid preview (tanpa ubah ukuran grid) */
    private static function syncGridWithPeriod(Set $set, Get $get): void
    {
        $arrival = self::parseDt($get('expected_arrival') ?? now())->startOfDay();
        $nights  = max(1, (int) ($get('nights') ?: 1));
        $dept    = ($get('expected_departure'))
            ? self::parseDt($get('expected_departure'))->setTime(12, 0)
            : $arrival->copy()->addDays($nights)->setTime(12, 0);

        $rows = $get('reservationGrid') ?? [];
        foreach (array_keys($rows) as $i) {
            self::setIfChanged($set, $get, "reservationGrid.$i.arrival", $arrival->copy());
            self::setIfChanged($set, $get, "reservationGrid.$i.dept",    $dept->copy());
        }
        Log::info('[syncGridWithPeriod] applied', ['rows' => count($rows), 'dept' => (string) $dept]);
    }

    /** Tentukan key repeater yang cocok untuk satu baris grid */
    private static function findRepeaterKeyForRow(array $guests, array $row): string|int|null
    {
        $gid = (int) ($row['guest_id'] ?? 0);
        $rid = (int) ($row['room_id']  ?? 0);

        foreach ($guests as $k => $g) {
            if ((int)($g['guest_id'] ?? 0) === $gid && (int)($g['room_id'] ?? 0) === $rid) {
                return $k;
            }
        }
        if ($gid) {
            foreach ($guests as $k => $g) {
                if ((int)($g['guest_id'] ?? 0) === $gid) {
                    return $k;
                }
            }
        }
        return array_key_first($guests);
    }

    /** Dorong nilai dari grid (UI) → repeater relasi agar ikut tersimpan */
    private static function syncGridToGuests(Set $set, Get $get): void
    {
        $grid   = $get('reservationGrid') ?? [];
        $guests = $get('reservationGuests') ?? [];

        Log::info('[SyncGrid] START', [
            'grid_count'   => is_array($grid) ? count($grid) : null,
            'guests_count' => is_array($guests) ? count($guests) : null,
            'guest_keys'   => is_array($guests) ? array_keys($guests) : null,
        ]);

        foreach ($grid as $i => $row) {
            if (! is_array($guests) || empty($guests)) {
                Log::info('[SyncGrid] No repeater rows');
                break;
            }

            $targetKey = self::findRepeaterKeyForRow($guests, (array) $row);
            if ($targetKey === null || ! array_key_exists($targetKey, $guests)) {
                Log::info('[SyncGrid] Cannot find repeater key', ['grid_index' => $i, 'row' => $row]);
                continue;
            }

            Log::info('[SyncGrid] Map row -> repeater', ['grid_index' => $i, 'target_key' => $targetKey, 'row' => $row]);

            $rateEmpty = !array_key_exists('room_rate', (array) $row) || $row['room_rate'] === null || $row['room_rate'] === '';
            $isEmpty = (
                empty($row['room_id']) &&
                empty($row['guest_id']) &&
                ! filled($row['person']) &&
                (int)($row['male'] ?? 0) === 0 &&
                (int)($row['female'] ?? 0) === 0 &&
                (int)($row['children'] ?? 0) === 0 &&
                (empty($row['charge_to']) || $row['charge_to'] === 'GUEST') &&
                empty($row['note']) &&
                $rateEmpty
            );
            if ($isEmpty) {
                Log::info('[SyncGrid] Row considered empty, skip', ['target_key' => $targetKey]);
                continue;
            }

            foreach (['person', 'male', 'female', 'children', 'jumlah_orang', 'charge_to', 'note'] as $f) {
                if (array_key_exists($f, (array) $row)) {
                    self::setIfChanged($set, $get, "reservationGuests.$targetKey.$f", $row[$f]);
                }
            }

            if (!$rateEmpty) {
                $intVal = ReservationForm::toIntMoney($row['room_rate']);
                self::setIfChanged($set, $get, "reservationGuests.$targetKey.room_rate", $intVal);
                Log::info('[SyncGrid] room_rate write', ['target_key' => $targetKey, 'raw' => $row['room_rate'], 'stored' => $intVal]);
            } else {
                Log::info('[SyncGrid] room_rate skipped (empty)', ['target_key' => $targetKey]);
            }

            if (! empty($row['dept'])) {
                $dt = self::parseDt($row['dept'])->setTime(12, 0);
                self::setIfChanged($set, $get, "reservationGuests.$targetKey.expected_checkout", $dt);
                Log::info('[SyncGrid] Map DEPT -> expected_checkout', ['target_key' => $targetKey, 'dept' => (string) $dt]);
            }

            foreach (['room_id', 'guest_id'] as $key) {
                if (! empty($row[$key])) {
                    self::setIfChanged($set, $get, "reservationGuests.$targetKey.$key", $row[$key]);
                }
            }

            $gg = $get('reservationGuests');
            Log::info('[SyncGrid] AFTER MAP', ['target_key' => $targetKey, 'repeater_row' => $gg[$targetKey] ?? null]);
        }

        Log::info('[SyncGrid] END snapshot', ['repeater' => $get('reservationGuests')]);
    }

    /** Normalisasi tanggal dari state (Carbon|string|array Livewire) */
    public static function parseDt($value): \Illuminate\Support\Carbon
    {
        if ($value instanceof \Carbon\CarbonInterface) {
            return Carbon::parse($value->toDateTimeString());
        }
        if (is_array($value) && isset($value[0])) {
            return Carbon::parse($value[0]);
        }
        return Carbon::parse($value ?: now());
    }

    /**
     * Konversi nilai uang ke integer rupiah.
     * - Hapus spasi & koma (koma dianggap ribuan)
     * - Titik dianggap desimal → round
     * - Sisa non digit dibersihkan
     */
    public static function toIntMoney($value): ?int
    {
        if ($value === null || $value === '') return null;
        if (is_int($value))   return $value;
        if (is_float($value)) return (int) round($value);

        $s = (string) $value;
        $s = str_replace([' ', ','], ['', ''], $s);

        if (str_contains($s, '.')) {
            return (int) round((float) $s);
        }
        if (!ctype_digit($s)) {
            $s = preg_replace('/\D+/', '', $s);
        }
        return $s === '' ? null : (int) $s;
    }

    /** Guard dukungan group */
    private static function hasGroupSupport(): bool
    {
        try {
            return \Illuminate\Support\Facades\Schema::hasColumn('reservations', 'group_id')
                && method_exists(\App\Models\Reservation::class, 'group');
        } catch (\Throwable $e) {
            return false;
        }
    }
}
