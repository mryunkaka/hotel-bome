<?php

declare(strict_types=1);

namespace App\Filament\Resources\FacilityBookings\Schemas;

use App\Models\Facility;
use App\Models\TaxSetting;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use App\Models\CateringPackage;
use App\Models\FacilityBooking;
use Illuminate\Support\Facades\Auth;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Textarea;
use Illuminate\Support\Facades\Session;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\DateTimePicker;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

final class FacilityBookingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Booking Info')
                ->columns(2)
                ->components([
                    DateTimePicker::make('start_at')
                        ->seconds(false)
                        ->required()
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Get $get, Set $set) {
                            self::normalizeDates($get, $set);
                            self::autoQuantity($get, $set);
                            self::ensureFacilityStillAvailable($get, $set); // ⬅️ cek ulang facility
                        }),

                    DateTimePicker::make('end_at')
                        ->seconds(false)
                        ->required()
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Get $get, Set $set) {
                            self::normalizeDates($get, $set);
                            self::autoQuantity($get, $set);
                            self::ensureFacilityStillAvailable($get, $set); // ⬅️ cek ulang facility
                        }),

                    Hidden::make('record_id')
                        ->dehydrated(false)
                        ->afterStateHydrated(fn(Set $set, ?FacilityBooking $record) => $set('record_id', $record?->id)),

                    Select::make('facility_id')
                        ->label('Facility')
                        ->placeholder('— pilih tanggal dulu —')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->reactive() // supaya re-render options saat start/end berubah
                        ->disabled(fn(Get $g) => blank($g('start_at')) || blank($g('end_at')))
                        ->helperText('Pilih tanggal mulai & selesai terlebih dahulu.')
                        ->options(function (Get $get) {
                            $hid   = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;
                            $start = $get('start_at');
                            $end   = $get('end_at');
                            $selfId = (int) ($get('record_id') ?? 0);

                            if (blank($start) || blank($end)) {
                                return []; // belum pilih tanggal → kosongkan daftar
                            }

                            $startAt = self::toCarbon($start)->format('Y-m-d H:i:s');
                            $endAt   = self::toCarbon($end)->format('Y-m-d H:i:s');

                            // Status booking yang dianggap memblokir (terpakai / terbooking)
                            $blocking = [FacilityBooking::STATUS_CONFIRM, FacilityBooking::STATUS_PAID];

                            // Ambil semua facility yang TIDAK ada booking aktif & TIDAK sedang diblok
                            $rows = Facility::query()
                                ->when($hid, fn($q) => $q->where('hotel_id', $hid))
                                // tidak ada booking aktif yang overlap
                                ->whereNotExists(function ($q) use ($startAt, $endAt, $blocking, $selfId) {
                                    $q->from('facility_bookings as fb')
                                        ->whereColumn('fb.facility_id', 'facilities.id')
                                        ->whereNull('fb.deleted_at')
                                        ->when($selfId > 0, fn($qq) => $qq->where('fb.id', '<>', $selfId))
                                        ->where(function ($qq) use ($blocking) {
                                            $qq->whereIn('fb.status', $blocking)
                                                ->orWhere('fb.is_blocked', true);
                                        })
                                        // overlap rule: (start < End) AND (end > Start)
                                        ->where('fb.start_at', '<', $endAt)
                                        ->where('fb.end_at', '>', $startAt);
                                })
                                // tidak sedang diblok (FacilityBlock aktif)
                                ->whereNotExists(function ($q) use ($startAt, $endAt) {
                                    $q->from('facility_blocks as fb2')
                                        ->whereColumn('fb2.facility_id', 'facilities.id')
                                        ->where('fb2.start_at', '<', $endAt)
                                        ->where('fb2.end_at', '>', $startAt)
                                        ->where('fb2.active', true);
                                })
                                ->orderBy('name')
                                ->limit(200)
                                ->pluck('name', 'id');

                            return $rows;
                        })
                        ->live() // biar afterStateUpdated jalan cepat saat pilih facility
                        ->afterStateUpdated(function ($state, Get $get, Set $set) {
                            if (!$state) return;

                            $f = Facility::query()
                                ->select('id', 'hotel_id', 'name', 'base_pricing_mode', 'base_price')
                                ->find($state);

                            if (!$f) return;

                            // follow facility
                            $set('hotel_id', $f->hotel_id);
                            $set('pricing_mode', $f->base_pricing_mode);
                            $set('pricing_mode_view', self::labelMode($f->base_pricing_mode));
                            $set('unit_price', (float) $f->base_price); // ← simpan numeric

                            self::normalizeDates($get, $set);
                            self::autoQuantity($get, $set);
                            // self::ensureDefaultTax($get, $set);
                            self::recalcTotals($get, $set);
                        }),

                    TextInput::make('title')
                        ->label('Event / Notes (short)')
                        ->maxLength(150),

                    Textarea::make('notes')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),

            Section::make('Status & Audit')
                ->columns(3)
                ->components([
                    Select::make('status')
                        ->options([
                            FacilityBooking::STATUS_DRAFT     => 'DRAFT',
                            FacilityBooking::STATUS_CONFIRM   => 'CONFIRM',
                            FacilityBooking::STATUS_PAID      => 'PAID',
                            FacilityBooking::STATUS_COMPLETED => 'COMPLETED',
                            FacilityBooking::STATUS_CANCELLED => 'CANCELLED',
                        ])
                        ->default(FacilityBooking::STATUS_DRAFT)
                        ->required()
                        ->live()
                        ->afterStateHydrated(function ($state, Get $get, Set $set) {
                            $set('is_blocked', in_array($state, [FacilityBooking::STATUS_CONFIRM, FacilityBooking::STATUS_PAID], true));
                        })
                        ->afterStateUpdated(function ($state, Get $get, Set $set) {
                            $set('is_blocked', in_array($state, [FacilityBooking::STATUS_CONFIRM, FacilityBooking::STATUS_PAID], true));
                        }),

                    Toggle::make('is_blocked')
                        ->label('Schedule Blocked')
                        ->disabled()
                        ->default(false),

                    // ===== Reserved By (Guest / Group) - tanpa nested Section =====
                    \Filament\Forms\Components\Radio::make('reserved_by_type')
                        ->label('Reserved By Type')
                        ->options(['GUEST' => 'Guest', 'GROUP' => 'Group'])
                        ->default(null)
                        ->live()
                        ->dehydrated(false)
                        ->afterStateHydrated(function (Get $get, Set $set, ?\App\Models\FacilityBooking $record) {
                            // Saat EDIT: ambil dari record
                            if ($record && $record->exists) {
                                $set('reserved_by_type', $record->group_id ? 'GROUP' : 'GUEST');
                                return;
                            }
                            // Saat CREATE atau fallback: infer dari state sementara
                            $set('reserved_by_type', $get('group_id') ? 'GROUP' : 'GUEST');
                        })
                        ->afterStateUpdated(function ($state, Get $get, Set $set) {
                            if ($state === 'GUEST') {
                                $set('group_id', null);
                            } else {
                                $set('guest_id', null);
                            }
                        })
                        ->columnSpan(3), // full width pada Section 3 kolom

                    // --- GUEST ---
                    Select::make('guest_id')
                        ->required(fn(Get $get) => $get('reserved_by_type') === 'GUEST')
                        ->visible(fn(Get $get) => $get('reserved_by_type') === 'GUEST')
                        ->label('Guest')
                        ->placeholder('— pilih / tambah tamu —')
                        ->native(false)
                        ->searchable()
                        ->preload()
                        ->options(function (Get $get) {
                            $hid = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;

                            // id guest yang sedang terpilih
                            $currentGuestId = (int) ($get('guest_id') ?? 0);

                            // kumpulkan id dari repeater jika ada
                            $idsFromState = $get('reservationGuests.*.guest_id') ?? [];
                            if (empty($idsFromState)) {
                                $idsFromState = \Illuminate\Support\Arr::flatten(
                                    (array) \Illuminate\Support\Arr::get(request()->input(), 'data.reservationGuests.*.guest_id', [])
                                );
                            }
                            $selectedGuestIds = array_filter(
                                array_map('intval', $idsFromState),
                                fn($id) => $id > 0 && $id !== $currentGuestId
                            );

                            $rows = \App\Models\Guest::query()
                                ->where(function ($q) use ($hid, $selectedGuestIds, $currentGuestId) {
                                    $q->where('hotel_id', $hid)
                                        ->when(!empty($selectedGuestIds), fn($qq) => $qq->whereNotIn('id', $selectedGuestIds))
                                        ->whereNotExists(function ($sub) use ($hid) {
                                            $sub->from('reservation_guests as rg')
                                                ->whereColumn('rg.guest_id', 'guests.id')
                                                ->where('rg.hotel_id', $hid)
                                                ->whereNull('rg.actual_checkout'); // exclude yang belum checkout
                                        });

                                    // sertakan current saat edit
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
                            $idsFromState   = $get('reservationGuests.*.guest_id') ?? [];
                            if (empty($idsFromState)) {
                                $idsFromState = \Illuminate\Support\Arr::flatten(
                                    (array) \Illuminate\Support\Arr::get(request()->input(), 'data.reservationGuests.*.guest_id', [])
                                );
                            }
                            $selectedGuestIds = array_filter(
                                array_map('intval', $idsFromState),
                                fn($id) => $id > 0 && $id !== $currentGuestId
                            );

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
                        ->createOptionForm([
                            \Filament\Forms\Components\TextInput::make('name')->label('Name')->required()->maxLength(150),
                            \Filament\Forms\Components\TextInput::make('phone')->label('Phone No')->maxLength(50),
                            \Filament\Forms\Components\TextInput::make('email')->label('Email')->email()->maxLength(150),
                            \Filament\Forms\Components\Hidden::make('hotel_id')->default(fn() => Session::get('active_hotel_id') ?? Auth::user()?->hotel_id),
                        ])
                        ->createOptionUsing(fn(array $data) => \App\Models\Guest::create($data)->id)
                        ->columnSpan(3),

                    // --- GROUP ---
                    Select::make('group_id')
                        ->required(fn(Get $get) => $get('reserved_by_type') === 'GROUP')
                        ->visible(fn(Get $get) => $get('reserved_by_type') === 'GROUP')
                        ->label('Group')
                        ->placeholder('— pilih / tambah group —')
                        ->native(false)
                        ->searchable()
                        ->preload()
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
                            \Filament\Forms\Components\TextInput::make('name')->label('Group Name')->required(),
                            \Filament\Forms\Components\TextInput::make('address')->label('Address'),
                            \Filament\Forms\Components\TextInput::make('city')->label('City'),
                            \Filament\Forms\Components\TextInput::make('phone')->label('Phone'),
                            \Filament\Forms\Components\TextInput::make('email')->label('Email')->email(),
                            \Filament\Forms\Components\Hidden::make('hotel_id')->default(fn() => Session::get('active_hotel_id') ?? Auth::user()?->hotel_id),
                        ])
                        ->createOptionUsing(fn(array $data) => \App\Models\ReservationGroup::create($data)->id)
                        ->columnSpan(3),

                    // ===== Hidden fields tetap =====
                    Hidden::make('hotel_id')
                        ->default(fn() => Session::get('active_hotel_id') ?? Auth::user()?->hotel_id)
                        ->dehydrated(true)
                        ->required()
                        ->afterStateHydrated(function ($state, Set $set) {
                            if (empty($state)) {
                                $set('hotel_id', Session::get('active_hotel_id') ?? Auth::user()?->hotel_id);
                            }
                        }),

                    // Di section Status & Audit (atau mana saja)
                    Hidden::make('include_catering')
                        ->default(false)
                        ->dehydrated(true)
                        ->afterStateHydrated(function (Get $get, Set $set) {
                            // kalau belum pernah di-set, nolkan supaya konsisten
                            if ($get('catering_total_amount') === null) $set('catering_total_amount', 0);
                            if ($get('catering_total_pax') === null)    $set('catering_total_pax', 0);
                            $set('include_catering', (float) ($get('catering_total_amount') ?? 0) > 0);
                        }),

                    Hidden::make('created_by')
                        ->dehydrated(true)
                        ->afterStateHydrated(function (Get $get, Set $set, ?\App\Models\FacilityBooking $record) {
                            if ($record && $record->exists) {
                                // Backfill kalau dulu sempat null
                                $set('created_by', $record->created_by ?: Auth::id());
                            } else {
                                // Create baru → set ke user saat ini
                                $set('created_by', Auth::id());
                            }
                        }),
                ]),

            Section::make('Catering')
                ->columns(2)
                ->components([
                    Select::make('catering_package_id')
                        ->label('Package')
                        ->placeholder('Select')
                        ->searchable()
                        ->preload()
                        ->options(function (Get $get) {
                            $hid  = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;
                            $curr = $get('catering_package_id');

                            return CateringPackage::query()
                                ->when($hid, fn($q) => $q->where('hotel_id', $hid))
                                ->where(function ($q) use ($curr) {
                                    $q->where('is_active', true);
                                    if ($curr) $q->orWhere('id', $curr);
                                })
                                ->orderBy('name')
                                ->limit(200)
                                ->pluck('name', 'id');
                        })
                        ->live(debounce: 300)
                        ->getOptionLabelUsing(fn($value) => $value ? CateringPackage::query()->whereKey($value)->value('name') : null)
                        ->afterStateUpdated(function ($id, Get $get, Set $set) {
                            self::refreshCatering($get, $set);
                            self::recalcTotals($get, $set);
                        }),

                    TextInput::make('catering_pax')
                        ->label('Jumlah Orang')
                        ->numeric()
                        ->nullable()               // ← boleh kosong
                        ->step(1)
                        ->live(debounce: 300)
                        // Jangan pasang ->minValue(1) agar browser tidak memaksa
                        // Validasi kondisional (server-side) saja:
                        ->rule(function (Get $get) {
                            return $get('catering_package_id')
                                ? ['nullable', 'integer', 'min:1']
                                : ['nullable', 'integer', 'min:0'];
                        })
                        // Atribut HTML 'min' ikut kondisional (hilangkan tooltip kuning)
                        ->extraInputAttributes(function (Get $get) {
                            return $get('catering_package_id') ? ['min' => 1] : ['min' => 0];
                        })
                        ->afterStateHydrated(function (Get $get, Set $set, ?FacilityBooking $record) {
                            $curr = $get('catering_pax');
                            if ($curr === null || (int) $curr === 0) {
                                $set('catering_pax', (int) ($get('catering_total_pax') ?? 0));
                            }

                            $raw = (float) ($get('catering_total_amount') ?? 0);
                            $set('catering_total_amount_view', number_format($raw, 0, ',', '.'));
                        })
                        ->afterStateUpdated(function ($pax, Get $get, Set $set) {
                            self::refreshCatering($get, $set);
                            self::recalcTotals($get, $set);
                        })
                        ->helperText('Opsional. Jika memilih paket, jumlah otomatis dinaikkan ke minimum paket.'),

                    // HANYA TAMPILAN
                    TextInput::make('catering_unit_price')
                        ->label('Harga')
                        ->readOnly()
                        ->dehydrated(false)
                        ->reactive()   // ⬅️ opsional tapi bagus
                        ->formatStateUsing(fn(Get $get) => number_format((float) ($get('catering_unit_price') ?? 0), 0, ',', '.')),

                    TextInput::make('catering_total_amount_view')
                        ->label('Total Harga Catering')
                        ->readOnly()
                        ->reactive()
                        ->dehydrated(false)
                        ->afterStateHydrated(function (Get $get, Set $set) {
                            $raw = (float) ($get('catering_total_amount') ?? 0);
                            $set('catering_total_amount_view', number_format($raw, 0, ',', '.'));
                        }),

                    Hidden::make('catering_total_amount')
                        ->default(0)
                        ->dehydrated(true)
                        // pastikan yang dikirim selalu numeric 0 jika null
                        ->dehydrateStateUsing(fn($state) => (float) ($state ?? 0))
                        // sinkronkan state di UI agar tidak null
                        ->afterStateHydrated(fn($state, Set $set) => $set('catering_total_amount', (float) ($state ?? 0))),

                    Hidden::make('catering_total_pax')
                        ->default(0)
                        ->dehydrated(true)
                        ->dehydrateStateUsing(fn($state) => (int) ($state ?? 0))
                        ->afterStateHydrated(fn($state, Set $set) => $set('catering_total_pax', (int) ($state ?? 0))),
                ]),

            Section::make('Pricing')
                ->columns(3)
                ->components([
                    // View-only label mode
                    TextInput::make('pricing_mode_view')
                        ->label('Pricing Mode')
                        ->readOnly()
                        ->dehydrated(false)
                        ->afterStateHydrated(function (Get $get, Set $set) {
                            $mode = $get('pricing_mode');
                            $set('pricing_mode_view', self::labelMode($mode));
                        })
                        ->placeholder('Auto from Facility'),

                    // Nilai sebenarnya disimpan
                    Hidden::make('pricing_mode')->dehydrated(true),

                    TextInput::make('unit_price')
                        ->numeric()->required()
                        ->disabled()->dehydrated(true)
                        ->reactive(),

                    // Qty auto dari durasi (read-only)
                    TextInput::make('quantity')
                        ->numeric()->minValue(0.5)->step('0.5')->required()
                        ->helperText('Auto by duration (hours/days)')
                        ->disabled()->dehydrated(true)
                        ->reactive(),

                    TextInput::make('discount_percent')
                        ->label('Discount (%)')
                        ->numeric()->minValue(0)->maxValue(100)
                        ->helperText('Persentase dari base (unit_price × quantity)')
                        ->live(debounce: 300)             // ⬅️ dulu onBlur, ganti agar realtime
                        ->reactive()                      // ⬅️ pastikan rerender
                        ->afterStateUpdated(fn(Get $g, Set $s) => self::recalcTotals($g, $s))
                        ->afterStateHydrated(function (Get $g, Set $s) {
                            if ($g('discount_percent') === null) {
                                $base = (float) ($g('unit_price') ?? 0) * (float) ($g('quantity') ?? 0);
                                $disc = (float) ($g('discount_amount') ?? 0);
                                $s('discount_percent', $base > 0 ? round(($disc / $base) * 100, 2) : 0);
                            }
                        })
                        ->dehydrated(true),

                    // discount nominal tidak ditampilkan, tapi tetap disimpan ke DB
                    Hidden::make('discount_amount')->dehydrated(true),

                    Select::make('id_tax')
                        ->label('Tax')
                        ->placeholder('Select')      // tampil "Select" dulu
                        ->native(false)              // biar placeholder pasti muncul
                        ->searchable()
                        ->nullable()
                        ->preload()
                        ->reactive()
                        ->default(null)              // paksa default null
                        ->options(function () {
                            $hid = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;

                            return TaxSetting::query()
                                ->where('hotel_id', $hid)
                                ->orderBy('is_active', 'desc')
                                ->orderBy('name')
                                ->limit(200)
                                ->pluck('name', 'id');
                        })
                        // ⬇️ Pada halaman CREATE pastikan tetap null + tax_percent=0
                        ->afterStateHydrated(function ($state, Get $get, Set $set, ?\App\Models\FacilityBooking $record) {
                            if (! $record || ! $record->exists) {       // create page
                                if (blank($state)) {
                                    $set('id_tax', null);
                                    $set('tax_percent', 0);
                                }
                            }
                            // hitung ulang ringkasan
                            self::recalcTotals($get, $set);
                        })
                        // jika user memilih tax → isi persen, kalau di-clear → 0
                        ->afterStateUpdated(function ($id, Get $get, Set $set) {
                            $percent = $id ? (float) (TaxSetting::query()->whereKey($id)->value('percent') ?? 0) : 0.0;
                            $set('tax_percent', $percent);
                            self::recalcTotals($get, $set);
                        })
                        ->dehydrated(false),

                    Hidden::make('tax_percent')
                        ->default(0)
                        ->dehydrated(true)
                        ->afterStateHydrated(fn($state, Set $set) => $state === null ? $set('tax_percent', 0) : null),

                    TextInput::make('dp')
                        ->label('DP (50% dari Total)')
                        ->readOnly()
                        ->dehydrated(true)
                        ->reactive(),

                    TextInput::make('tax_amount')
                        ->numeric()
                        ->readOnly()->dehydrated(true)
                        ->reactive(),

                    TextInput::make('subtotal_amount')
                        ->numeric()
                        ->readOnly()->dehydrated(true)
                        ->reactive(),

                    TextInput::make('total_amount')
                        ->numeric()
                        ->readOnly()->dehydrated(true)
                        ->reactive(),
                ]),
        ]);
    }

    /** Hitung dan set ulang nilai catering (unit_price, pax, total, include) */
    private static function refreshCatering(Get $get, Set $set): void
    {
        $id   = $get('catering_package_id');
        $pax  = (int) ($get('catering_pax') ?? 0);

        if (!$id) {
            $set('catering_unit_price', 0);
            // pax dibiarkan apa adanya (jangan di-nolkan)
            $set('catering_total_pax', max(0, $pax));
            $set('catering_total_amount', 0);
            $set('catering_total_amount_view', '0');
            $set('include_catering', false);
            return;
        }

        $min   = (int) (CateringPackage::query()->whereKey($id)->value('min_pax') ?? 1);
        $price = (float) (CateringPackage::query()->whereKey($id)->value('price_per_pax') ?? 0);

        // pax kosong/<=0 → pakai min. Jika user sudah isi, hormati dan hanya naikan ke min jika kurang.
        $pax   = ($pax <= 0) ? $min : max($pax, $min);

        $total = $pax * $price;

        $set('catering_unit_price', $price);
        $set('catering_pax', $pax);
        $set('catering_total_pax', $pax);
        $set('catering_total_amount', $total);
        $set('catering_total_amount_view', number_format($total, 0, ',', '.'));
        $set('include_catering', $total > 0);
    }

    /** Jika end kosong / <= start, set end minimal +1 jam/hari sesuai mode. */
    private static function normalizeDates(Get $get, Set $set): void
    {
        $mode = $get('pricing_mode') ?: FacilityBooking::PRICING_PER_HOUR;
        $s = $get('start_at');
        $e = $get('end_at');

        if (!$s) return;

        $start = self::toCarbon($s);
        $end   = $e ? self::toCarbon($e) : null;

        if (!$end || $end->lessThanOrEqualTo($start)) {
            $end = $start->copy();
            $mode === FacilityBooking::PRICING_PER_DAY ? $end->addDay() : $end->addHour();
            $set('end_at', $end->format('Y-m-d\TH:i')); // html-datetime-local
        }
    }

    /** Hitung qty otomatis sesuai mode. */
    private static function autoQuantity(Get $get, Set $set): void
    {
        $mode = $get('pricing_mode') ?: FacilityBooking::PRICING_PER_HOUR;
        $s = $get('start_at');
        $e = $get('end_at');
        if (!$s || !$e) return;

        $start = self::toCarbon($s);
        $end   = self::toCarbon($e);
        if ($end->lessThanOrEqualTo($start)) return;

        if ($mode === FacilityBooking::PRICING_PER_DAY) {
            $hours = $start->floatDiffInRealHours($end);
            $days  = max(1, (int) ceil($hours / 24));
            $set('quantity', $days);
        } elseif ($mode === FacilityBooking::PRICING_PER_HOUR) {
            $hours = max(1, round($start->floatDiffInRealHours($end), 1));
            $set('quantity', $hours);
        } else {
            $set('quantity', 1);
        }

        self::recalcTotals($get, $set);
    }

    private static function recalcTotals(Get $get, Set $set): void
    {
        $unit = (float) ($get('unit_price') ?? 0);
        $qty  = (float) ($get('quantity') ?? 0);
        $discPct = (float) ($get('discount_percent') ?? 0); // persen diskon
        $cat  = (float) ($get('catering_total_amount') ?? 0);
        $pct  = (float) ($get('tax_percent') ?? 0);         // persen pajak

        $base = max(0, $unit * $qty);

        // diskon nominal = % dari base
        $discAmt = round($base * max(0, min($discPct, 100)) / 100, 2);

        // subtotal sebelum pajak
        $subtotal = max(0, $base - $discAmt);

        // DPP pajak = subtotal + catering
        $beforeTax = $subtotal + $cat;

        // pajak = persen × DPP
        $tax = round($beforeTax * max(0, $pct) / 100, 2);

        $total = $beforeTax + $tax;

        // simpan state
        $set('discount_amount', $discAmt);
        $set('subtotal_amount', $subtotal);
        $set('tax_amount', $tax);
        $set('total_amount', $total);
        $set('dp', round($total * 0.5, 2));
        $set('include_catering', $cat > 0);
    }

    /** Parser aman untuk format html-datetime-local / ISO. */
    private static function toCarbon(string $val): Carbon
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $val)) {
            return Carbon::createFromFormat('Y-m-d\TH:i', $val, config('app.timezone'));
        }
        return Carbon::parse($val, config('app.timezone'));
    }

    private static function labelMode(?string $mode): string
    {
        return match ($mode) {
            FacilityBooking::PRICING_PER_HOUR => 'Per Hour',
            FacilityBooking::PRICING_PER_DAY  => 'Per Day',
            FacilityBooking::PRICING_FIXED    => 'Fixed',
            default => ucfirst((string) $mode),
        };
    }

    private static function ensureDefaultTax(Get $get, Set $set): void
    {
        // kalau id_tax sudah ada, isi persen-nya lalu keluar
        if ($get('id_tax')) {
            $pct = (float) (TaxSetting::query()->whereKey($get('id_tax'))->value('percent') ?? 0);
            $set('tax_percent', $pct);
            return;
        }

        $hid = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;

        if (!$hid) {
            $set('tax_percent', 0);
            return;
        }

        $row = TaxSetting::query()
            ->where('hotel_id', $hid)
            ->orderBy('is_active', 'desc')
            ->orderBy('name')
            ->first();

        if ($row) {
            $set('id_tax', $row->id);
            $set('tax_percent', (float) $row->percent);
        } else {
            $set('tax_percent', 0);
        }
    }
    /** True jika facility available (tidak ada overlap blocking pada rentang) */
    private static function isFacilityAvailable(int $facilityId, Carbon $start, Carbon $end, ?int $ignoreId = null): bool
    {
        $blocking = [FacilityBooking::STATUS_CONFIRM, FacilityBooking::STATUS_PAID];

        $conflictCount = FacilityBooking::query()
            ->where('facility_id', $facilityId)
            ->whereNull('deleted_at')
            ->when($ignoreId, fn($q) => $q->where('id', '<>', $ignoreId))
            ->where(function ($q) use ($blocking) {
                $q->whereIn('status', $blocking)
                    ->orWhere('is_blocked', true);
            })
            ->where('start_at', '<', $end->format('Y-m-d H:i:s'))
            ->where('end_at',   '>', $start->format('Y-m-d H:i:s'))
            ->count();

        return $conflictCount === 0;
    }

    /** Jika facility terpilih tapi tidak lagi available setelah tanggal diubah → kosongkan */
    private static function ensureFacilityStillAvailable(Get $get, Set $set): void
    {
        $facilityId = (int) ($get('facility_id') ?? 0);
        if ($facilityId <= 0) return;

        $s = $get('start_at');
        $e = $get('end_at');
        if (blank($s) || blank($e)) return;

        $start = self::toCarbon($s);
        $end   = self::toCarbon($e);
        if ($end->lessThanOrEqualTo($start)) return;

        $ignoreId = (int) ($get('record_id') ?? 0);

        if (! self::isFacilityAvailable($facilityId, $start, $end, $ignoreId)) {
            // kosongkan pilihan + reset harga/qty agar jelas ke user
            $set('facility_id', null);
            $set('unit_price', 0);
            $set('quantity', 0);
            $set('pricing_mode', FacilityBooking::PRICING_PER_HOUR);
            $set('pricing_mode_view', self::labelMode(FacilityBooking::PRICING_PER_HOUR));
            self::recalcTotals($get, $set);
        }
    }
}
