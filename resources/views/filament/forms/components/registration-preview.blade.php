@php
    use App\Support\ReservationMath;
    use App\Support\ReservationView;
    use Illuminate\Support\Carbon;

    /** @var \App\Models\ReservationGuest|null $rg */
    $rg = $getRecord();
    $res = $rg?->reservation;
    $company = $rg?->reservation?->group; // <- perbaikan relasi group via reservation
    $guest = $rg?->guest;
    $room = $rg?->room;

    // helpers tampilan (konsisten dg helper)
    $fmt = fn($dt) => ReservationView::fmtDate($dt, true);
    $money = fn($v) => ReservationView::fmtMoney($v);

    // Lama inap fallback (hanya jika properti length_of_stay tidak tersedia)
    $arrivalRaw = $rg?->expected_checkin;
    $departureRaw = $rg?->expected_checkout;
    $nights = null;
    if ($arrivalRaw && $departureRaw) {
        $arr = Carbon::parse($arrivalRaw)->startOfDay();
        $dep = Carbon::parse($departureRaw)->startOfDay();
        $nights = $arr->diffInDays($dep); // 13/09 → 15/09 = 2 malam
    }

    // Hitungan Extra Bed
    $qtyExtraBed = (int) ($rg?->extra_bed ?? 0);
    $extraBedSub = $qtyExtraBed * 100_000;

    // Siapkan tax-lookup 1 baris ini agar calcFinalRate bisa resolve id_tax → percent
    $taxLookup = [];
    if ($rg?->id_tax && $rg?->tax?->percent !== null) {
        $taxLookup[(int) $rg->id_tax] = (float) $rg->tax->percent;
    }

    // Data untuk kalkulasi final (rate ++)
    $rowCalc = [
        'room_rate' => (float) ($rg?->room_rate ?? 0),
        'discount_percent' => (float) ($rg?->discount_percent ?? 0),
        'id_tax' => $rg?->id_tax,
        'tax_percent' => $rg?->tax?->percent, // opsional; id_tax akan ditarik via $taxLookup di atas
        'extra_bed' => (int) ($rg?->extra_bed ?? 0),
        'service' => (int) ($rg?->service ?? 0), // biaya tambahan service (nominal), bukan persen
    ];

    // hitungan denda keterlambatan checkin
    $finalRate = ReservationMath::calcFinalRate($rowCalc, [
        'tax_lookup' => $taxLookup,
        'extra_bed_price' => 100000,
        'service_taxable' => false, // ubah true jika service ikut kena pajak
    ]);

    // Ambil angka untuk ditampilkan di ringkasan
    $basicRate = (float) ($rg?->room_rate ?? 0);
    $discPct = (float) ($rg?->discount_percent ?? 0);
    $taxPct = $rg?->tax?->percent !== null ? (float) $rg->tax->percent : 0.0;
    $serviceRp = (int) ($rg?->service ?? 0);

    /**
     * ===========================
     *  Denda Keterlambatan (Late Arrival Penalty)
     * ===========================
     * Aturan default (bisa diubah):
     * - Rp 25.000 per jam, dibulatkan ke atas
     * - Maksimal 50% dari basicRate
     * - Berlaku jika (actual_checkin ATAU now) > expected_checkin
     */
    $LATE_PENALTY_PER_HOUR = 25000;
    $LATE_PENALTY_MAX_PERCENT_OF_BASE = 50; // batas penalty 50% dari room rate

    $penaltyHours = 0;
    $penaltyRp = 0;

    if ($rg?->expected_checkin) {
        $arrivalAt = \Carbon\Carbon::parse($rg->expected_checkin, 'Asia/Makassar');
        $refTime = $rg?->actual_checkin
            ? \Carbon\Carbon::parse($rg->actual_checkin, 'Asia/Makassar')
            : \Carbon\Carbon::now('Asia/Makassar');

        if ($refTime->greaterThan($arrivalAt)) {
            $lateMins = $arrivalAt->diffInMinutes($refTime);
            $penaltyHours = (int) ceil($lateMins / 60);
            $penaltyRp = $penaltyHours * $LATE_PENALTY_PER_HOUR;

            if ($LATE_PENALTY_MAX_PERCENT_OF_BASE > 0 && $basicRate > 0) {
                $cap = (int) round(($basicRate * $LATE_PENALTY_MAX_PERCENT_OF_BASE) / 100);
                $penaltyRp = min($penaltyRp, $cap);
            }
        }
    }

    // Total akhir termasuk denda
    $finalWithPenalty = $finalRate + $penaltyRp;
@endphp

@if (!$rg)
    <div class="p-4 text-sm text-gray-500">
        Tidak ada data yang bisa ditampilkan (record belum terset).
    </div>
@else
    <style>
        .reg-card {
            border: 1px solid #e5e7eb;
            border-radius: .75rem;
            padding: 1rem;
            background: #fff;
        }

        .reg-grid {
            display: grid;
            grid-template-columns: 1.3fr 1fr;
            gap: 1rem;
        }

        .reg-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 600;
            margin-bottom: .75rem;
        }

        .reg-row {
            display: grid;
            grid-template-columns: 160px 1fr;
            gap: .5rem;
            padding: .5rem .6rem;
            border-bottom: 1px solid #e5e7eb;
        }

        .reg-row:first-of-type {
            border-top: 1px solid #e5e7eb;
        }

        .box {
            border: 1px solid #e5e7eb;
            border-radius: .5rem;
            overflow: hidden;
            background: #fafafa;
        }

        .box>.titlebar {
            padding: .6rem .75rem;
            font-weight: 700;
            background: #f3f4f6;
            border-bottom: 1px solid #e5e7eb;
        }

        .muted {
            color: #6b7280;
            font-size: .9rem;
        }

        .big {
            font-size: 1.05rem;
            font-weight: 700;
        }

        .money {
            font-variant-numeric: tabular-nums;
        }

        .btns {
            display: flex;
            gap: .5rem;
            flex-wrap: wrap;
        }

        .tag {
            display: inline-block;
            padding: .15rem .6rem;
            border-radius: 999px;
            background: #eef2ff;
            color: #3730a3;
            font-size: .75rem;
            border: 1px solid #c7d2fe;
        }

        .sep {
            height: 1px;
            background: #e5e7eb;
            margin: .5rem 0;
        }

        /* Tabel ringkasan angka (kiri bawah) */
        .kv {
            width: 100%;
            border-collapse: collapse;
            margin-top: .25rem;
            background: #fff;
        }

        .kv tr {
            border-bottom: 1px solid #e5e7eb;
        }

        .kv tr:first-child {
            border-top: 1px solid #e5e7eb;
        }

        .kv td {
            padding: .5rem .6rem;
            vertical-align: top;
        }

        .kv td.k {
            width: 55%;
            color: #6b7280;
        }

        .kv td.v {
            width: 45%;
            text-align: right;
            font-variant-numeric: tabular-nums;
        }

        .ttl {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            padding: .6rem .75rem;
            background: #fff;
            border-top: 2px solid #d1d5db;
            border-bottom: 1px solid #e5e7eb;
        }

        .ttl .label {
            color: #374151;
            font-weight: 700;
        }

        .ttl .val {
            font-weight: 800;
            font-size: 1.05rem;
        }

        /* Kanan juga pakai garis pada setiap row */
        .box .group {
            background: #fff;
        }

        .box .group .reg-row {
            background: #fff;
        }
    </style>

    <div class="reg-card">
        {{-- Header --}}
        <div class="reg-head">
            <div>
                <div class="muted">Reg.No</div>
                <div class="big">{{ $res?->reservation_no ?? '-' }}</div>
            </div>
            <div class="btns">
                {{-- PRINT: arahkan ke route print kamu --}}
                @if ($rg?->actual_checkin)
                    <x-filament::button tag="a" :href="route('reservation-guests.print', ['guest' => $rg->id])" icon="heroicon-o-printer" target="_blank"
                        rel="noopener">
                        Print
                    </x-filament::button>
                @endif

                {{-- ROOM CHANGE: ke halaman edit --}}
                <x-filament::button color="warning" tag="a" :href="\App\Filament\Resources\Reservations\ReservationResource::getUrl('edit', [
                    'record' => $rg->reservation_id,
                ])" icon="heroicon-o-pencil-square">
                    Edit
                </x-filament::button>
            </div>
        </div>

        <div class="reg-grid">
            {{-- Kiri: Data Tamu & Ringkasan Tarif --}}
            <div class="box">
                <div class="titlebar">Guest Information</div>
                <div class="group">
                    <div class="reg-row">
                        <div>Name</div>
                        <div>{{ $guest?->display_name ?? ($guest?->name ?? '-') }}</div>
                    </div>
                    <div class="reg-row">
                        <div>Address</div>
                        <div>{{ $guest?->address ?? '-' }}</div>
                    </div>
                    <div class="reg-row">
                        <div>Guest Type</div>
                        <div>{{ $guest?->guest_type ?? '-' }}</div>
                    </div>
                    <div class="reg-row">
                        <div>City/Country</div>
                        <div>{{ $guest?->city ?? '-' }}</div>
                    </div>
                    <div class="reg-row">
                        <div>Nationality</div>
                        <div>{{ $guest?->nationality ?? '-' }}</div>
                    </div>
                    <div class="reg-row">
                        <div>Profession</div>
                        <div>{{ $guest?->profession ?? '-' }}</div>
                    </div>
                    <div class="reg-row">
                        <div>Identity Card</div>
                        <div>{{ $guest?->id_type ?? '-' }} {{ $guest?->id_card ?? '' }}</div>
                    </div>
                    <div class="reg-row">
                        <div>Birth</div>
                        <div>{{ $guest?->birth_place ? $guest->birth_place . ', ' : '' }}
                            {{ $guest?->birth_date?->translatedFormat('d F Y') ?? '-' }}</div>
                    </div>
                    <div class="reg-row">
                        <div>Issued</div>
                        <div>{{ $guest?->issued_place ? $guest->issued_place . ', ' : '' }}
                            {{ $guest?->issued_date?->translatedFormat('d F Y') ?? '-' }}</div>
                    </div>

                    <div class="reg-row">
                        <div>Room No.</div>
                        <div>{{ $room?->room_no ?? '-' }} {{ $room?->type ? '— ' . $room->type : '' }}</div>
                    </div>
                    <div class="reg-row">
                        <div>Rate Type</div>
                        <div>{{ $res?->method ?? '-' }}</div>
                    </div>
                    <div class="reg-row">
                        <div>Extra Bed</div>
                        <div>
                            {{ $qtyExtraBed > 0 ? $qtyExtraBed . ' — ' . $money($extraBedSub) : '-' }}
                        </div>
                    </div>
                    <div class="reg-row">
                        <div>Pax/Persons</div>
                        <div>
                            {{ (int) ($rg?->jumlah_orang ?? 0) }}
                            <span class="muted"> ( Male: {{ (int) ($rg?->male ?? 0) }}, Female:
                                {{ (int) ($rg?->female ?? 0) }}, Children: {{ (int) ($rg?->children ?? 0) }} )</span>
                        </div>
                    </div>
                </div>

                {{-- Ringkasan angka + garis tabel --}}
                <table class="kv">
                    <tr>
                        <td class="k">Basic Rate</td>
                        <td class="v money">{{ $money($basicRate) }}</td>
                    </tr>
                    <tr>
                        <td class="k">Room Discount</td>
                        <td class="v">{{ number_format($discPct, 2, ',', '.') }}%</td>
                    </tr>
                    <tr>
                        <td class="k">Room Tax</td>
                        <td class="v">{{ number_format($taxPct, 2, ',', '.') }}%</td>
                    </tr>
                    <tr>
                        <td class="k">Service (Rp)</td>
                        <td class="v money">{{ $money($serviceRp) }}</td>
                    </tr>
                    <tr>
                        <td class="k">Extra Bed</td>
                        <td class="v money">{{ $qtyExtraBed > 0 ? $money($extraBedSub) : $money(0) }}</td>
                    </tr>
                    <tr>
                        <td class="k">
                            Late Arrival Penalty{{ $penaltyHours ? ' (' . $penaltyHours . ' h)' : '' }}
                        </td>
                        <td class="v money">{{ $money($penaltyRp) }}</td>
                    </tr>
                </table>

                <div class="ttl">
                    <div class="label">RATE ++</div>
                    <div class="val money">{{ $money($finalWithPenalty) }}</div>
                </div>

            </div>

            {{-- Kanan: Data Reservasi --}}
            <div class="box">
                <div class="titlebar">Reservation Details</div>
                <div class="group">
                    <div class="reg-row">
                        <div>Purpose of Visit</div>
                        <div>{{ $rg?->pov ?? '-' }}</div>
                    </div>

                    <div class="reg-row">
                        <div>Length of Stay</div>
                        <div>
                            @if (!empty($res?->length_of_stay))
                                {{ $res->length_of_stay }} Night(s)
                            @elseif(!is_null($nights))
                                {{ $nights }} Night(s)
                            @else
                                -
                            @endif
                        </div>
                    </div>

                    <div class="reg-row">
                        <div>Arrival</div>
                        <div>{{ $fmt($rg?->expected_checkin) }}</div>
                    </div>
                    <div class="reg-row">
                        <div>Departure</div>
                        <div>{{ $fmt($rg?->expected_checkout) }}</div>
                    </div>
                    <div class="reg-row">
                        <div>Company</div>
                        <div>{{ $company?->name ?? '-' }}</div>
                    </div>
                    <div class="reg-row">
                        <div>Code</div>
                        <div>{{ $res?->option ?? '-' }}</div>
                    </div>
                    <div class="reg-row">
                        <div>Charge To</div>
                        <div>{{ $res?->method ?? '-' }}</div>
                    </div>
                    <div class="reg-row">
                        <div>Phone No</div>
                        <div>{{ $guest?->phone ?? '-' }}</div>
                    </div>
                    <div class="reg-row">
                        <div>Email</div>
                        <div>{{ $guest?->email ?? '-' }}</div>
                    </div>
                    <div class="reg-row">
                        <div>Status</div>
                        <div>
                            <span class="tag">{{ $res?->status ?? 'DRAFT' }}</span>
                            @if ($rg?->actual_checkin)
                                <span class="tag">Checked-In</span>
                            @endif
                        </div>
                    </div>

                    <div class="reg-row">
                        <div>Breakfast</div>
                        <div>{{ $rg?->breakfast ?? '-' }}</div>
                    </div>
                    <div class="reg-row">
                        <div>Note</div>
                        <div>{{ $rg?->note ?? '-' }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endif
