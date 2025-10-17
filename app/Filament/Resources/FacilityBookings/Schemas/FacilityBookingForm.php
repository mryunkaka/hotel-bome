<?php

declare(strict_types=1);

namespace App\Filament\Resources\FacilityBookings\Schemas;

use App\Models\Facility;
use App\Models\TaxSetting;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
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
                    Select::make('facility_id')
                        ->label('Facility')
                        ->relationship(name: 'facility', titleAttribute: 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->live()
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
                            $set('unit_price', (string) $f->base_price);

                            self::normalizeDates($get, $set);
                            self::autoQuantity($get, $set);

                            // pasang tax default jika perlu
                            self::ensureDefaultTax($get, $set);

                            self::recalcTotals($get, $set);
                        }),

                    TextInput::make('title')
                        ->label('Event / Notes (short)')
                        ->maxLength(150),

                    DateTimePicker::make('start_at')
                        ->seconds(false)
                        ->required()
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Get $get, Set $set) {
                            self::normalizeDates($get, $set);
                            self::autoQuantity($get, $set);
                        }),

                    DateTimePicker::make('end_at')
                        ->seconds(false)
                        ->required()
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Get $get, Set $set) {
                            self::normalizeDates($get, $set);
                            self::autoQuantity($get, $set);
                        }),

                    Textarea::make('notes')
                        ->rows(2)
                        ->columnSpanFull(),
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

                    // Ikut facility & terkunci
                    TextInput::make('unit_price')
                        ->numeric()->prefix('Rp')->required()
                        ->disabled()->dehydrated(true),

                    // Qty auto dari durasi (read-only)
                    TextInput::make('quantity')
                        ->numeric()->minValue(0.5)->step('0.5')->required()
                        ->helperText('Auto by duration (hours/days)')
                        ->disabled()->dehydrated(true),

                    // ===== DISCOUNT: PERSEN SAJA =====
                    TextInput::make('discount_percent')
                        ->label('Discount (%)')
                        ->numeric()->minValue(0)->maxValue(100)
                        ->default(0)
                        ->helperText('Persentase dari base (unit_price × quantity)')
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn(Get $g, Set $s) => self::recalcTotals($g, $s))
                        ->dehydrated(false),

                    // discount nominal tidak ditampilkan, tapi tetap disimpan ke DB
                    Hidden::make('discount_amount')->dehydrated(true),

                    // ===== TAX dari TaxSetting (otomatis default & hitung amount) =====
                    Select::make('id_tax')
                        ->label('Tax')
                        ->placeholder('Select')
                        ->native(true)
                        ->nullable()
                        ->options(function () {
                            $hid = Session::get('active_hotel_id')
                                ?? Auth::user()?->hotel_id;
                            return TaxSetting::query()
                                ->where('hotel_id', $hid)
                                ->orderBy('is_active', 'desc')
                                ->orderBy('name')
                                ->limit(200)
                                ->pluck('name', 'id');
                        })
                        ->live()
                        ->afterStateHydrated(function ($state, Get $get, Set $set) {
                            self::ensureDefaultTax($get, $set);
                            self::recalcTotals($get, $set);
                        })
                        ->afterStateUpdated(function ($id, Get $get, Set $set) {
                            $percent = $id ? (float) (TaxSetting::query()->whereKey($id)->value('percent') ?? 0) : 0.0;
                            $set('tax_percent', $percent);
                            self::recalcTotals($get, $set);
                        })
                        ->dehydrated(false),

                    Hidden::make('tax_percent')->dehydrated(false),

                    // ringkasan catering (read-only; tidak disimpan)
                    TextInput::make('catering_total_amount_view')
                        ->label('Catering Amount (summary)')
                        ->readOnly()->dehydrated(false)
                        ->formatStateUsing(fn(Get $get) => 'Rp ' . number_format((float) ($get('catering_total_amount') ?? 0), 0, ',', '.')),

                    // tax amount auto (read-only, tersimpan)
                    TextInput::make('tax_amount')
                        ->numeric()->prefix('Rp')
                        ->readOnly()->dehydrated(true),

                    TextInput::make('subtotal_amount')
                        ->numeric()->prefix('Rp')
                        ->readOnly()->dehydrated(true),

                    TextInput::make('total_amount')
                        ->numeric()->prefix('Rp')
                        ->readOnly()->dehydrated(true),
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
                        ->required(),

                    Toggle::make('is_blocked')
                        ->label('Schedule Blocked')
                        ->disabled()
                        ->default(false),

                    Hidden::make('hotel_id')
                        ->default(fn() => Session::get('active_hotel_id') ?? Auth::user()?->hotel_id)
                        ->dehydrated(true)
                        ->required()
                        ->afterStateHydrated(function ($state, Set $set) {
                            if (empty($state)) {
                                $set('hotel_id', Session::get('active_hotel_id') ?? Auth::user()?->hotel_id);
                            }
                        }),
                    Hidden::make('catering_total_amount')->default(0)->dehydrated(true),
                    Hidden::make('catering_total_pax')->default(0)->dehydrated(true),
                ]),
        ]);
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
}
