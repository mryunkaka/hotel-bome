<?php

namespace App\Filament\Resources\Reservations\Schemas;

use App\Models\Room;
use App\Models\Guest;
use App\Models\Reservation;
use Filament\Support\RawJs;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Filament\Forms\Components\Radio;
use Illuminate\Support\Facades\Auth;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Illuminate\Support\Facades\Session;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Illuminate\Database\Eloquent\Builder;
use Filament\Forms\Components\DateTimePicker;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Forms\Components\Select as FSelect;

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
                            ->default(fn() => now())
                            ->columnSpan(6),

                        TextInput::make('nights')
                            ->label('Nights')
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->required()
                            ->columnSpan(6),
                    ]),
                ]),

            // ===========================
            // Reserved By (sederhana)
            // ===========================
            Section::make('Reserved By')
                ->schema([
                    Grid::make(12)->schema([
                        // Pilihan mode (cukup satu yang live untuk toggle visibilitas)
                        Radio::make('reserved_by_type')
                            ->label('Reserved By Type')
                            ->options(['GUEST' => 'Guest', 'GROUP' => 'Group'])
                            ->default('GUEST')
                            // ->live() 
                            ->columnSpan(12),

                        // --- Mode Guest: pilih Guest (dengan tombol + buat baru)
                        // GUEST
                        FSelect::make('guest_id')              // <— langsung guest_id
                            ->label('Guest')
                            ->options(function () {
                                $hid = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;
                                return Guest::query()
                                    ->when($hid, fn($q) => $q->where('hotel_id', $hid))
                                    ->orderBy('name')->limit(50)
                                    ->pluck('name', 'id')->toArray();
                            })
                            ->searchable()
                            ->getSearchResultsUsing(function (string $search) {
                                $hid = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;
                                return Guest::query()
                                    ->when($hid, fn($q) => $q->where('hotel_id', $hid))
                                    ->where(function ($q) use ($search) {
                                        $q->where('name', 'like', "%{$search}%")
                                            ->orWhere('phone', 'like', "%{$search}%");
                                    })
                                    ->orderBy('name')->limit(50)
                                    ->pluck('name', 'id')->toArray();
                            })
                            ->getOptionLabelUsing(fn($value) => optional(Guest::find($value))->name)
                            ->createOptionForm([

                                Section::make('Guest Info')->schema([
                                    Grid::make(12)->schema([
                                        // ENUM salutation (sesuai enum & kolom string(10))
                                        FSelect::make('salutation')
                                            ->label('Title')
                                            ->options(['MR' => 'MR', 'MRS' => 'MRS', 'MISS' => 'MISS'])
                                            ->native(false)
                                            ->columnSpan(3),

                                        TextInput::make('name')
                                            ->label('Name')
                                            ->required()
                                            ->maxLength(150)
                                            ->columnSpan(9),

                                        // OPTIONAL: jenis tamu (kolom string(20))
                                        FSelect::make('guest_type')
                                            ->label('Guest Type')
                                            ->options([
                                                'DOMESTIC' => 'Domestic',
                                                'INTERNATIONAL'  => 'International',
                                            ])
                                            ->native(false)
                                            ->columnSpan(4),

                                        TextInput::make('nationality')
                                            ->label('Nationality')
                                            ->maxLength(50)
                                            ->columnSpan(4),

                                        // Alamat & data umum (sesuai panjang kolom)
                                        TextInput::make('address')->label('Address')->maxLength(255)->columnSpan(12),

                                        TextInput::make('city')->label('City')->maxLength(50)->columnSpan(4),
                                        TextInput::make('profession')->label('Profession')->maxLength(50)->columnSpan(4),

                                        // Identitas
                                        FSelect::make('id_type')
                                            ->label('Identity Type')
                                            ->options([
                                                'KTP'      => 'KTP',
                                                'PASSPORT' => 'Passport',
                                                'SIM'      => 'SIM',
                                                'OTHER'    => 'Other',
                                            ])
                                            ->native(false)
                                            ->columnSpan(4),

                                        TextInput::make('id_card')
                                            ->label('Identity Number')
                                            ->maxLength(100)
                                            ->rule('not_in:-') // cegah '-' jadi nilai
                                            ->rules([
                                                // unik per-hotel & ramah soft-deletes (deleted_at NULL)
                                                Rule::unique('guests', 'id_card')
                                                    ->where(
                                                        fn($q) =>
                                                        $q->where('hotel_id', Session::get('active_hotel_id') ?? Auth::user()?->hotel_id)
                                                            ->whereNull('deleted_at')
                                                    ),
                                            ])
                                            ->nullable()
                                            ->columnSpan(6),

                                        FileUpload::make('id_card_file')
                                            ->label('Attach ID (JPG/PNG/PDF)')
                                            ->directory('guests/id')
                                            ->disk('public')
                                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])
                                            ->maxSize(4096)
                                            ->downloadable()
                                            ->openable()
                                            ->columnSpan(6),

                                        // Tempat & tanggal (issued_place STRING(100), birth_date/issued_date DATE)
                                        TextInput::make('issued_place')
                                            ->label('Issued Place')
                                            ->maxLength(100)
                                            ->columnSpan(6),

                                        DatePicker::make('issued_date')->label('Issued Date')->native(false)->columnSpan(6),

                                        TextInput::make('birth_place')->label('Birth Place')->maxLength(50)->columnSpan(6),
                                        DatePicker::make('birth_date')->label('Birth Date')->native(false)->columnSpan(6),

                                        // Kontak
                                        TextInput::make('phone')
                                            ->label('Phone No')
                                            ->maxLength(50)
                                            ->rule('regex:/^\\+?\\d{6,20}$/') // format sederhana, +opsional
                                            ->rules([
                                                Rule::unique('guests', 'phone')
                                                    ->where(
                                                        fn($q) =>
                                                        $q->where('hotel_id', Session::get('active_hotel_id') ?? Auth::user()?->hotel_id)
                                                            ->whereNull('deleted_at')
                                                    )->ignore(null), // create-option form → tidak perlu ignore
                                            ])
                                            ->nullable()
                                            ->columnSpan(6),

                                        TextInput::make('email')
                                            ->label('Email')
                                            ->email()
                                            ->maxLength(150)
                                            ->rules([
                                                Rule::unique('guests', 'email')
                                                    ->where(
                                                        fn($q) =>
                                                        $q->where('hotel_id', Session::get('active_hotel_id') ?? Auth::user()?->hotel_id)
                                                            ->whereNull('deleted_at')
                                                    ),
                                            ])
                                            ->nullable()
                                            ->columnSpan(6),

                                        Hidden::make('hotel_id')
                                            ->default(fn() => Session::get('active_hotel_id') ?? Auth::user()?->hotel_id),
                                    ]),
                                ]),


                            ])
                            ->createOptionUsing(function (array $data) {
                                return Guest::create($data)->id;
                            })
                            ->visible(fn(callable $get) => ($get('reserved_by_type') ?? 'GUEST') === 'GUEST')
                            ->required(fn(callable $get) => ($get('reserved_by_type') ?? 'GUEST') === 'GUEST')
                            ->columnSpan(12),

                        // --- Mode Group: pilih Group (dengan tombol + buat baru)
                        FSelect::make('group_id')
                            ->label('Group')
                            ->relationship(
                                name: 'group',
                                titleAttribute: 'name',
                                modifyQueryUsing: function ($q) {
                                    // Guard agar tidak error saat $q null
                                    if (! $q instanceof Builder) return;

                                    $hotelId = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;
                                    if ($hotelId) {
                                        $q->where('hotel_id', $hotelId);
                                    }
                                }
                            )
                            ->searchable()
                            ->preload()
                            ->createOptionForm([
                                TextInput::make('name')->label('Group Name')->required(),
                                TextInput::make('phone')->label('Phone'),
                                TextInput::make('handphone')->label('Handphone'),
                                TextInput::make('email')->label('Email')->email(),
                                Textarea::make('long_remark')->label('Remark')->rows(2),
                                Hidden::make('hotel_id')->default(fn() => Session::get('active_hotel_id') ?? Auth::user()?->hotel_id),
                                Hidden::make('created_by')->default(fn() => Auth::id()),
                            ])
                            ->helperText('Wajib saat Reserved By Type = Group')
                            ->visible(fn(callable $get) => ($get('reserved_by_type') ?? 'GUEST') === 'GROUP')
                            ->required(fn(callable $get) => ($get('reserved_by_type') ?? 'GUEST') === 'GROUP')
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
                        ->mutateRelationshipDataBeforeCreateUsing(function (array $data): array {
                            $data['hotel_id'] = $data['hotel_id']
                                ?? (Session::get('active_hotel_id') ?? Auth::user()?->hotel_id);

                            // Pax = male + female + children (min 1)
                            $male     = (int)($data['male'] ?? 0);
                            $female   = (int)($data['female'] ?? 0);
                            $children = (int)($data['children'] ?? 0);
                            $data['jumlah_orang'] = max(1, $male + $female + $children);

                            // Default waktu
                            if (empty($data['expected_checkin'])) {
                                $data['expected_checkin'] = now()->setTime(12, 0);
                            }
                            if (empty($data['expected_checkout'])) {
                                $data['expected_checkout'] = Carbon::parse($data['expected_checkin'])->addDay()->setTime(12, 0);
                            }

                            // Rate dari room kalau kosong; fallback 0 agar tidak NULL
                            if (empty($data['room_rate']) && ! empty($data['room_id'])) {
                                $price = Room::whereKey($data['room_id'])->value('price');
                                $data['room_rate'] = (int) ($price ?? 0);
                            } else {
                                $data['room_rate'] = (int) ($data['room_rate'] ?? 0);
                            }

                            return $data;
                        })
                        ->mutateRelationshipDataBeforeSaveUsing(function (array $data): array {
                            $data['hotel_id'] = $data['hotel_id']
                                ?? (Session::get('active_hotel_id') ?? Auth::user()?->hotel_id);

                            $male     = (int)($data['male'] ?? 0);
                            $female   = (int)($data['female'] ?? 0);
                            $children = (int)($data['children'] ?? 0);
                            $data['jumlah_orang'] = max(1, $male + $female + $children);

                            if (empty($data['expected_checkin'])) {
                                $data['expected_checkin'] = now()->setTime(12, 0);
                            }
                            if (empty($data['expected_checkout'])) {
                                $data['expected_checkout'] = Carbon::parse($data['expected_checkin'])->addDay()->setTime(12, 0);
                            }

                            if (empty($data['room_rate']) && ! empty($data['room_id'])) {
                                $price = Room::whereKey($data['room_id'])->value('price');
                                $data['room_rate'] = (int) ($price ?? 0);
                            } else {
                                $data['room_rate'] = (int) ($data['room_rate'] ?? 0);
                            }

                            return $data;
                        })
                        ->schema([
                            Select::make('room_id')
                                ->label('Room')
                                ->native(false)
                                ->searchable()
                                ->rules(['required', 'distinct']) // room_id
                                ->live() // supaya options re-render saat sibling berubah
                                ->reactive()
                                ->options(function (Get $get) {
                                    $hid     = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;
                                    $current = (int) ($get('room_id') ?? 0);

                                    // semua room yang sudah dipilih di repeater
                                    $picked = array_map('intval', array_filter($get('../../reservationGuests.*.room_id') ?? []));
                                    // keluarkan nilai baris sendiri agar labelnya tetap ada
                                    $exclude = array_diff($picked, [$current]);

                                    return \App\Models\Room::query()
                                        ->where('hotel_id', $hid)
                                        ->when(!empty($exclude), fn($q) => $q->whereNotIn('id', $exclude))
                                        ->orderBy('room_no')
                                        ->limit(200)
                                        ->pluck('room_no', 'id');
                                })
                                ->getSearchResultsUsing(function (string $search, Get $get) {
                                    $hid     = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;
                                    $current = (int) ($get('room_id') ?? 0);
                                    $picked  = array_map('intval', array_filter($get('../../reservationGuests.*.room_id') ?? []));
                                    $exclude = array_diff($picked, [$current]);

                                    return \App\Models\Room::query()
                                        ->where('hotel_id', $hid)
                                        ->where('room_no', 'like', "%{$search}%")
                                        ->when(!empty($exclude), fn($q) => $q->whereNotIn('id', $exclude))
                                        ->orderBy('room_no')
                                        ->limit(50)
                                        ->pluck('room_no', 'id');
                                })
                                // fallback jika nilai sudah terlanjur ada (edit mode)
                                ->getOptionLabelUsing(fn($value) => optional(\App\Models\Room::find($value))->room_no)
                                // opsional: auto isi rate saat pilih room
                                ->afterStateUpdated(function ($state, callable $set) {
                                    if ($state) {
                                        $price = \App\Models\Room::whereKey($state)->value('price');
                                        $set('room_rate', (int) ($price ?? 0));
                                    }
                                })
                                ->rules(['required', 'distinct'])
                                ->columnSpan(2),

                            // GUEST (tanpa relationship)
                            Select::make('guest_id')
                                ->label('Guest')
                                ->native(false)
                                ->rules(['nullable', 'distinct']) // guest_id
                                ->searchable()
                                ->live()
                                ->reactive()
                                ->options(function (Get $get) {
                                    $hid     = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;
                                    $current = (int) ($get('guest_id') ?? 0);

                                    $picked  = array_map('intval', array_filter($get('../../reservationGuests.*.guest_id') ?? []));
                                    $exclude = array_diff($picked, [$current]);

                                    return \App\Models\Guest::query()
                                        ->where('hotel_id', $hid) // kamu memang pakai hotel_id di guests
                                        ->when(!empty($exclude), fn($q) => $q->whereNotIn('id', $exclude))
                                        ->orderBy('name')
                                        ->limit(200)
                                        ->pluck('name', 'id');
                                })
                                ->getSearchResultsUsing(function (string $search, Get $get) {
                                    $hid     = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;
                                    $current = (int) ($get('guest_id') ?? 0);

                                    $picked  = array_map('intval', array_filter($get('../../reservationGuests.*.guest_id') ?? []));
                                    $exclude = array_diff($picked, [$current]);

                                    return \App\Models\Guest::query()
                                        ->where('hotel_id', $hid)
                                        ->where(function ($q) use ($search) {
                                            $q->where('name', 'like', "%{$search}%")
                                                ->orWhere('phone', 'like', "%{$search}%");
                                        })
                                        ->when(!empty($exclude), fn($q) => $q->whereNotIn('id', $exclude))
                                        ->orderBy('name')
                                        ->limit(50)
                                        ->pluck('name', 'id');
                                })
                                ->getOptionLabelUsing(fn($value) => optional(\App\Models\Guest::find($value))->name)
                                ->rules(['nullable', 'distinct'])
                                ->columnSpan(2),

                            TextInput::make('room_rate')
                                ->label('Rate')
                                ->numeric()
                                ->mask(RawJs::make('$money($input)'))
                                ->stripCharacters(',')
                                ->placeholder('Auto from room')
                                ->columnSpan(2),

                            Hidden::make('jumlah_orang'),

                            DateTimePicker::make('expected_checkin')
                                ->label('Check-in')
                                ->default(fn() => now()->setTime(12, 0))
                                ->columnSpan(2),

                            DateTimePicker::make('expected_checkout')
                                ->label('Check-out')
                                ->default(fn() => now()->addDay()->setTime(12, 0))
                                ->columnSpan(2),

                            Select::make('breakfast')
                                ->label('Breakfast')
                                ->options(['Yes' => 'Yes', 'No' => 'No'])
                                ->default('No')
                                ->columnSpan(2),
                            TextInput::make('person')->label('Person Charge')->columnSpan(2),
                            TextInput::make('male')->label('Male')->numeric()->default(1)->minValue(0)->columnSpan(1),
                            TextInput::make('female')->label('Female')->numeric()->default(0)->minValue(0)->columnSpan(1),
                            TextInput::make('children')->label('Children')->numeric()->default(0)->minValue(0)->columnSpan(1),

                            Select::make('charge_to')
                                ->label('Charge To')
                                ->options(['GUEST' => 'GUEST', 'COMPANY' => 'COMPANY', 'AGENCY' => 'AGENCY', 'OTHER' => 'OTHER'])
                                ->default('GUEST')
                                ->columnSpan(2),

                            TextInput::make('pov')->label('Purpose of Visit')->columnSpan(2),

                            TextInput::make('note')->label('Note')->columnSpan(3),
                        ])
                        ->extraItemActions([
                            Action::make('hapus')
                                ->requiresConfirmation()
                                ->action(fn($record, $livewire) => $record->delete())
                        ]),

                ])
                ->columnSpanFull(),
        ]);
    }
}
