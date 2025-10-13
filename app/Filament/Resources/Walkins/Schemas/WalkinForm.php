<?php

namespace App\Filament\Resources\Walkins\Schemas;

use Carbon\Carbon;
use App\Models\Room;
use App\Models\Guest;
use Filament\Support\RawJs;
use Filament\Schemas\Schema;
use App\Models\ReservationGroup;
use Illuminate\Support\Facades\Auth;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Grid;
use Illuminate\Database\Query\Builder;
use Filament\Forms\Components\Textarea;
use Illuminate\Support\Facades\Session;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Schemas\Components\Utilities\Get;

class WalkinForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make('Walk Info')
                ->schema([
                    Grid::make(12)->schema([
                        // ========== Guest (tetap dengan tombol Tambah) ==========
                        Select::make('guest_id')
                            ->label('Name')
                            ->native(false)
                            ->searchable()
                            ->required()
                            ->preload()
                            ->live() // preview langsung terisi
                            ->options(function () {
                                $hid = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;
                                $rows = Guest::query()
                                    ->where('hotel_id', $hid)
                                    ->whereNotExists(function ($sub) use ($hid) {
                                        $sub->from('reservation_guests as rg')
                                            ->whereColumn('rg.guest_id', 'guests.id')
                                            ->where('rg.hotel_id', $hid)
                                            ->whereNull('rg.actual_checkout');
                                    })
                                    ->orderBy('name')
                                    ->limit(200)
                                    ->get(['id', 'name', 'id_card']);

                                return $rows->mapWithKeys(fn($g) => [
                                    $g->id => $g->name . (($ic = trim((string) ($g->id_card ?? ''))) && $ic !== '-' ? " ({$ic})" : ''),
                                ])->toArray();
                            })
                            ->getSearchResultsUsing(function (string $search) {
                                $hid = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;
                                $s = trim(preg_replace('/\s+/', ' ', $search));
                                $rows = Guest::query()
                                    ->where('hotel_id', $hid)
                                    ->where(fn($q) => $q->where('name', 'like', "%{$s}%")
                                        ->orWhere('phone', 'like', "%{$s}%")
                                        ->orWhere('id_card', 'like', "%{$s}%"))
                                    ->whereNotExists(function ($sub) use ($hid) {
                                        $sub->from('reservation_guests as rg')
                                            ->whereColumn('rg.guest_id', 'guests.id')
                                            ->where('rg.hotel_id', $hid)
                                            ->whereNull('rg.actual_checkout');
                                    })
                                    ->orderBy('name')->limit(50)->get(['id', 'name', 'id_card']);

                                return $rows->mapWithKeys(fn($g) => [
                                    $g->id => $g->name . (($ic = trim((string) ($g->id_card ?? ''))) && $ic !== '-' ? " ({$ic})" : ''),
                                ])->toArray();
                            })
                            ->getOptionLabelUsing(
                                fn($value) =>
                                $value ? (function ($g) {
                                    if (! $g) return null;
                                    $ic = trim((string) ($g->id_card ?? ''));
                                    return $g->name . ($ic !== '' && $ic !== '-' ? " ({$ic})" : '');
                                })(Guest::query()->select('name', 'id_card')->find($value)) : null
                            )
                            ->createOptionForm([
                                Section::make('Guest Info')->schema([
                                    Grid::make(12)->schema([
                                        Select::make('salutation')->label('Title')
                                            ->options(['MR' => 'MR', 'MRS' => 'MRS', 'MISS' => 'MISS'])
                                            ->default('MR')->native(false)->columnSpan(2),

                                        TextInput::make('name')->label('Name')->required()->maxLength(150)->columnSpan(4),

                                        Select::make('guest_type')->label('Guest Type')
                                            ->options(['DOMESTIC' => 'Domestic', 'INTERNATIONAL' => 'International'])
                                            ->default('DOMESTIC')->native(false)->columnSpan(3),

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
                            ->createOptionUsing(fn(array $data) => Guest::create($data)->id)
                            ->afterStateHydrated(function ($state, callable $set) {
                                if ($state && ($g = Guest::find($state))) self::fillGuestPreviewState($g, $set);
                            })
                            ->afterStateUpdated(function ($state, callable $set) {
                                if ($state && ($g = Guest::find($state))) self::fillGuestPreviewState($g, $set);
                                else self::clearGuestPreviewState($set);
                            })
                            ->columnSpan(12),

                        // ===== PREVIEW (display only; tidak dehydrated) =====
                        TextInput::make('pv_address')->label('Address')->disabled()->dehydrated(false)->columnSpan(12),

                        // Row: Guest Type + Nationality + Profession (hemat 1 baris)
                        Select::make('pv_guest_type')->label('Check In')
                            ->options(['DOMESTIC' => 'DOMESTIC', 'INTERNATIONAL' => 'INTERNATIONAL'])
                            ->disabled()->dehydrated(false)->columnSpan(3),
                        TextInput::make('pv_nationality')->label('Nationality')->disabled()->dehydrated(false)->columnSpan(3),
                        TextInput::make('pv_profession')->label('Profession')->disabled()->dehydrated(false)->columnSpan(6),

                        // Row: City / Country
                        TextInput::make('pv_city')->label('City/Country')->placeholder('City')
                            ->disabled()->dehydrated(false)->columnSpan(6),
                        TextInput::make('pv_country')->label('')->placeholder('Country')
                            ->disabled()->dehydrated(false)->columnSpan(6),

                        // Row: ID Type + ID Card
                        Select::make('pv_id_type')->label('ID Type')->options([
                            'ID' => 'National ID',
                            'PASSPORT' => 'Passport',
                            'DRIVER_LICENSE' => 'Driver License',
                            'OTHER' => 'Other',
                        ])->disabled()->dehydrated(false)->columnSpan(3),

                        TextInput::make('pv_id_card')->label('ID Card')->disabled()->dehydrated(false)->columnSpan(9),

                    ]),
                ]),

            Section::make('Walk Detail')
                ->schema([
                    Grid::make(12)->schema([
                        // Row: Group + preview phone/email
                        Select::make('group_id')
                            ->label('Group')
                            ->relationship(
                                name: 'group',
                                titleAttribute: 'name',
                                modifyQueryUsing: function (Builder $q) {
                                    $hid = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;
                                    $q->when($hid, fn($qq) => $qq->where('hotel_id', $hid));
                                }
                            )
                            ->native(false)
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateHydrated(function ($state, callable $set) {
                                if ($state && ($grp = ReservationGroup::find($state))) {
                                    $set('pv_group_phone', $grp->phone ?: null);
                                    $set('pv_group_email', $grp->email ?: null);
                                }
                            })
                            ->afterStateUpdated(function ($state, callable $set) {
                                if ($state && ($grp = ReservationGroup::find($state))) {
                                    $set('pv_group_phone', $grp->phone ?: null);
                                    $set('pv_group_email', $grp->email ?: null);
                                } else {
                                    $set('pv_group_phone', null);
                                    $set('pv_group_email', null);
                                }
                            })
                            ->createOptionForm([
                                \Filament\Forms\Components\TextInput::make('name')->label('Group Name')->required(),
                                \Filament\Forms\Components\TextInput::make('address')->label('Address'),
                                \Filament\Forms\Components\TextInput::make('city')->label('City'),
                                \Filament\Forms\Components\TextInput::make('phone')->label('Phone'),
                                \Filament\Forms\Components\TextInput::make('email')->label('Email')->email(),
                                Hidden::make('hotel_id')->default(fn() => Session::get('active_hotel_id') ?? Auth::user()?->hotel_id),
                            ])
                            ->createOptionUsing(fn(array $data) => ReservationGroup::create($data)->id)
                            ->columnSpan(12),

                        TextInput::make('pv_group_phone')->label('Group Phone')
                            ->disabled()->dehydrated(false)->columnSpan(6),

                        TextInput::make('pv_group_email')->label('Group Email')
                            ->disabled()->dehydrated(false)->columnSpan(6),
                        // Row: POV + Charge To
                        Select::make('pov')->label('Purpose of Visit')
                            ->options([
                                'BUSINESS' => 'Business',
                                'OFFICIAL' => 'Official',
                                'TRANSIENT' => 'Transient',
                                'VACATION' => 'Vacation',
                            ])->default('BUSINESS')->columnSpan(12),

                        // Row: Nights + Arrival
                        DateTimePicker::make('expected_arrival')->label('Arrival')
                            ->required()->default(fn() => now()->setTime(13, 0))->seconds(false)
                            ->columnSpan(8),

                        TextInput::make('nights')->label('Nights')
                            ->numeric()->minValue(1)->default(1)->required()
                            ->live(onBlur: true)
                            ->afterStateHydrated(fn($s, $set, $get) => self::syncDeparture($set, $get))
                            ->afterStateUpdated(fn($s, $set, $get) => self::syncDeparture($set, $get))
                            ->columnSpan(4),

                        Hidden::make('expected_departure')->dehydrated()
                            ->afterStateHydrated(fn($s, $set, $get) => self::syncDeparture($set, $get)),

                        // Row: C/I Option
                        Select::make('option')->label('C/I Option')
                            ->options([
                                'WALKIN' => 'WALK-IN',
                                'GOVERNMENT' => 'GOVERNMENT',
                                'CORPORATE' => 'CORPORATE',
                                'TRAVEL' => 'TRAVEL',
                                'OTA' => 'OTA',
                                'WEEKLY' => 'WEEKLY',
                                'MONTHLY' => 'MONTHLY',
                            ])->default('WALKIN')->required()->columnSpan(6),

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
                            ->columnSpan(6),
                    ]),
                ]),

            Section::make('Information Rate')
                ->schema([
                    Grid::make(12)->schema([
                        TextInput::make('discount_percent')
                            ->label('Discount (%)')
                            ->numeric()
                            ->step('0.01')
                            ->minValue(0)
                            ->maxValue(100)
                            ->default(0)
                            ->live(onBlur: true)
                            ->afterStateHydrated(function ($state, callable $set, Get $get) {
                                if (($get('person') ?? '') === 'COMPLIMENTARY') return;
                                $roomId = (int) ($get('room_id') ?? 0);
                                if ($roomId <= 0) return;

                                $calc = \App\Support\ReservationMath::rateDepositFromRoom(
                                    $roomId,
                                    (float) ($state ?? 0),
                                    (string) ($get('person') ?? '')
                                );

                                // ⬇️ Guard: hanya set kalau beda
                                $newRate = (int) $calc['rate'];
                                $newDep  = (int) $calc['deposit'];
                                if ((int) ($get('room_rate') ?? 0) !== $newRate) {
                                    $set('room_rate', $newRate);
                                }
                                if ((int) ($get('deposit_card') ?? 0) !== $newDep) {
                                    $set('deposit_card', $newDep);
                                }
                            })
                            ->afterStateUpdated(function ($state, callable $set, Get $get) {
                                if (($get('person') ?? '') === 'COMPLIMENTARY') return;
                                $roomId = (int) ($get('room_id') ?? 0);
                                if ($roomId <= 0) return;

                                $calc = \App\Support\ReservationMath::rateDepositFromRoom(
                                    $roomId,
                                    (float) ($state ?? 0),
                                    (string) ($get('person') ?? '')
                                );

                                // ⬇️ Guard: hanya set kalau beda
                                $newRate = (int) $calc['rate'];
                                $newDep  = (int) $calc['deposit'];
                                if ((int) ($get('room_rate') ?? 0) !== $newRate) {
                                    $set('room_rate', $newRate);
                                }
                                if ((int) ($get('deposit_card') ?? 0) !== $newDep) {
                                    $set('deposit_card', $newDep);
                                }
                            })
                            ->columnSpan(4),
                        TextInput::make('extra_bed')
                            ->label('Ektra Bed')
                            ->numeric()
                            ->default(0)
                            ->columnSpan(4),

                        TextInput::make('deposit_card_rg')
                            ->visible(false) // hanya supaya tidak bentrok dengan field header 'deposit_card'
                            ->dehydrated(false),

                        // Section: Information Rate
                        TextInput::make('deposit_card')
                            ->label('Deposit Card')
                            ->numeric()
                            ->minValue(0)
                            ->default(
                                fn(Get $get) => ($get('person') === 'COMPLIMENTARY')
                                    ? 0
                                    : (float) ($get('room_rate') ?? 0) * 0.5
                            )
                            ->dehydrated(false)
                            ->columnSpan(4),
                        // ROOM PICKER
                        Select::make('room_id')
                            ->label('Room')
                            ->native(false)
                            ->placeholder('Select Room')
                            ->searchable()
                            ->required()
                            ->preload()
                            ->live()
                            ->options(function () {
                                $hid = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;

                                return Room::query()
                                    ->where('hotel_id', $hid)
                                    ->whereIn('status', [Room::ST_VC, Room::ST_VCI]) // ready only
                                    ->whereNotExists(function ($sub) use ($hid) {
                                        $sub->from('reservation_guests as rg')
                                            ->whereColumn('rg.room_id', 'rooms.id')
                                            ->where('rg.hotel_id', $hid)
                                            ->whereNotNull('rg.actual_checkin')
                                            ->whereNull('rg.actual_checkout');
                                    })
                                    ->orderBy('room_no')
                                    ->limit(200)
                                    ->pluck('room_no', 'id');
                            })
                            ->getSearchResultsUsing(function (string $search) {
                                $hid = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;

                                return Room::query()
                                    ->where('hotel_id', $hid)
                                    ->whereIn('status', [Room::ST_VC, Room::ST_VCI])
                                    ->where('room_no', 'like', "%{$search}%")
                                    ->whereNotExists(function ($sub) use ($hid) {
                                        $sub->from('reservation_guests as rg')
                                            ->whereColumn('rg.room_id', 'rooms.id')
                                            ->where('rg.hotel_id', $hid)
                                            ->whereNotNull('rg.actual_checkin')
                                            ->whereNull('rg.actual_checkout');
                                    })
                                    ->orderBy('room_no')
                                    ->limit(50)
                                    ->pluck('room_no', 'id');
                            })
                            ->getOptionLabelUsing(function ($value): ?string {
                                if (! $value) return null;
                                $room = Room::query()->select('room_no', 'type')->find($value);
                                return $room ? ($room->room_no . ' • ' . ($room->type ?? '-')) : null;
                            })
                            ->afterStateHydrated(function ($state, callable $set, Get $get) {
                                if (! $state) return;

                                // Preview type/status tetap
                                if ($room = \App\Models\Room::find($state)) {
                                    $set('pv_room_type',   $room->type   ?: '-');
                                    $set('pv_room_status', $room->status ?: null);
                                }

                                // Hitung rate & deposit dari room + diskon
                                if (($get('person') ?? '') !== 'COMPLIMENTARY') {
                                    $calc = \App\Support\ReservationMath::rateDepositFromRoom(
                                        (int) $state,
                                        (float) ($get('discount_percent') ?? 0),
                                        (string) ($get('person') ?? '')
                                    );
                                    $set('room_rate', $calc['rate']);
                                    $set('deposit_card', $calc['deposit']);
                                }
                            })
                            ->afterStateUpdated(function ($state, callable $set, Get $get) {
                                if (! $state) {
                                    if (($get('person') ?? '') !== 'COMPLIMENTARY') {
                                        $set('room_rate', null);
                                        $set('deposit_card', null);
                                    }
                                    $set('pv_room_type', null);
                                    $set('pv_room_status', null);
                                    return;
                                }

                                if ($room = \App\Models\Room::find($state)) {
                                    $set('pv_room_type',   $room->type   ?: '-');
                                    $set('pv_room_status', $room->status ?: null);
                                }

                                if (($get('person') ?? '') !== 'COMPLIMENTARY') {
                                    $calc = \App\Support\ReservationMath::rateDepositFromRoom(
                                        (int) $state,
                                        (float) ($get('discount_percent') ?? 0),
                                        (string) ($get('person') ?? '')
                                    );
                                    $set('room_rate', $calc['rate']);
                                    $set('deposit_card', $calc['deposit']);
                                }
                            })
                            ->columnSpan(12),

                        // RATE (DISIMPAN) — dikunci kecuali Charge To = COMPLIMENTARY
                        TextInput::make('room_rate')
                            ->label('Rate')
                            ->numeric()
                            ->minValue(0)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, callable $set) {
                                $rate = (float) ($state ?? 0);
                                $set('deposit_card', $rate * 0.5);
                            })
                            ->disabled(fn(Get $get) => ($get('person') ?? '') !== 'COMPLIMENTARY')
                            ->dehydrated(true)
                            ->columnSpan(4),

                        // TYPE (PREVIEW, TIDAK DI-SAVE)
                        TextInput::make('pv_room_type')
                            ->label('Type')
                            ->disabled()
                            ->dehydrated(false)
                            ->columnSpan(4),

                        // STATUS (PREVIEW, TIDAK DI-SAVE)
                        TextInput::make('pv_room_status')
                            ->label('Status')
                            ->disabled()
                            ->dehydrated(false)
                            ->columnSpan(4),

                        Select::make('person')->label('Charge To')
                            ->options([
                                'PERSONAL ACCOUNT' => 'Personal Account',
                                'COMPANY ACCOUNT'  => 'Company Account',
                                'TRAVEL AGENT'     => 'Travel Agent',
                                'OL TRAVEL AGENT'  => 'OL Travel Agent',
                                'COMPLIMENTARY'    => 'Complimentary',
                            ])
                            ->default('PERSONAL ACCOUNT')
                            ->live()
                            ->afterStateHydrated(function ($state, callable $set, Get $get) {
                                $calc = \App\Support\ReservationMath::rateDepositFromRoom(
                                    (int) ($get('room_id') ?? 0),
                                    (float) ($get('discount_percent') ?? 0),
                                    (string) ($state ?? '')
                                );
                                $set('room_rate', $calc['rate']);
                                $set('deposit_card', $calc['deposit']);
                            })
                            ->afterStateUpdated(function ($state, callable $set, Get $get) {
                                $calc = \App\Support\ReservationMath::rateDepositFromRoom(
                                    (int) ($get('room_id') ?? 0),
                                    (float) ($get('discount_percent') ?? 0),
                                    (string) ($state ?? '')
                                );
                                $set('room_rate', $calc['rate']);
                                $set('deposit_card', $calc['deposit']);
                            })
                            ->columnSpan(6),

                        Select::make('breakfast')
                            ->label('Breakfast')
                            ->options(['Yes' => 'Yes', 'No' => 'No'])
                            ->default('Yes')
                            ->columnSpan(6),

                        TextInput::make('male')
                            ->label('Male')
                            ->numeric()
                            ->default(1)
                            ->minValue(0)
                            ->columnSpan(4),

                        TextInput::make('female')
                            ->label('Female')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->columnSpan(4),

                        TextInput::make('children')
                            ->label('Children')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->columnSpan(4),

                        Hidden::make('jumlah_orang')
                            ->default(1)
                            ->dehydrated(),

                        Textarea::make('note')
                            ->label('Note')
                            ->placeholder('Catatan khusus tamu / permintaan...')
                            ->autosize()
                            ->rows(3)
                            ->maxLength(500)
                            ->columnSpan(12),
                    ]),
                ]),

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
                    ]),
                ]),
        ]);
    }

    /* ========================= Helpers ========================= */

    protected static function syncDeparture(callable $set, callable $get): void
    {
        $arrival = $get('expected_arrival');
        $nights  = (int) ($get('nights') ?: 1);

        if (!$arrival) {
            $set('expected_departure', null);
            return;
        }

        if (!($arrival instanceof Carbon)) {
            try {
                $arrival = Carbon::parse($arrival);
            } catch (\Throwable $e) {
                $arrival = null;
            }
        }
        if (!$arrival) {
            $set('expected_departure', null);
            return;
        }

        $nights    = max(1, $nights);
        $departure = $arrival->copy()->startOfDay()->addDays($nights)->setTime(12, 0);
        $set('expected_departure', $departure);
    }

    // ===== Helper kecil agar hook singkat (logika tetap) =====
    protected static function fillGuestPreviewState(\App\Models\Guest $g, callable $set): void
    {
        $f = fn($v) => $v ?? null;

        $set('pv_address',      $f($g->address));
        $set('pv_guest_type',   $f($g->guest_type ?: 'DOMESTIC'));
        $set('pv_city',         $f($g->city));
        $set('pv_country',      $f($g->nationality));
        $set('pv_nationality',  $f($g->nationality));
        $set('pv_profession',   $f($g->profession));
        $set('pv_id_type',      $f($g->id_type ?: 'ID'));
        $set('pv_id_card',      $f($g->id_card));
        $set('pv_birth_place',  $f($g->birth_place));
        $set('pv_birth_date',   $g->birth_date  ? $g->birth_date->format('Y-m-d')  : null);
        $set('pv_issued_place', $f($g->issued_place));
        $set('pv_issued_date',  $g->issued_date ? $g->issued_date->format('Y-m-d') : null);
    }

    protected static function clearGuestPreviewState(callable $set): void
    {
        foreach (
            [
                'pv_address',
                'pv_guest_type',
                'pv_city',
                'pv_country',
                'pv_nationality',
                'pv_profession',
                'pv_id_type',
                'pv_id_card',
                'pv_birth_place',
                'pv_birth_date',
                'pv_issued_place',
                'pv_issued_date',
            ] as $k
        ) $set($k, null);
    }
}
