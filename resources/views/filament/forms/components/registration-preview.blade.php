@php
    /** @var \App\Models\ReservationGuest|null $rg */
    $rg      = $getRecord();
    $res     = $rg?->reservation;
    $company = $rg?->reservation?->group; // <- perbaikan di sini
    $guest   = $rg?->guest;
    $room    = $rg?->room;

    $qty   = (int) ($rg?->extra_bed ?? 0);
    $total = $qty * 100_000;

    // helpers tampilan
    $fmt = fn($dt) => $dt ? \Illuminate\Support\Carbon::parse($dt)->format('d/m/Y H:i') : '-';
    $n   = fn($v) => is_numeric($v) ? number_format($v, 0, ',', '.') : $v;
@endphp

@php
    $arrivalRaw   = $res?->expected_arrival;
    $departureRaw = $res?->expected_departure;

    $nights = null;
    if ($arrivalRaw && $departureRaw) {
        $arr = \Illuminate\Support\Carbon::parse($arrivalRaw)->startOfDay();
        $dep = \Illuminate\Support\Carbon::parse($departureRaw)->startOfDay();
        $nights = $arr->diffInDays($dep); // 13/09 → 15/09 = 2
    }
@endphp

@if (!$rg)
    <div class="p-4 text-sm text-gray-500">
        Tidak ada data yang bisa ditampilkan (record belum terset).
    </div>
@else
    <style>
        .reg-card { border:1px solid var(--tw-prose-hr, #e5e7eb); border-radius:.75rem; padding:1rem; background:#fff; }
        .reg-grid { display:grid; grid-template-columns: 1.3fr 1fr; gap:1rem; }
        .reg-head { display:flex; justify-content:space-between; align-items:center; font-weight:600; margin-bottom:.5rem; }
        .reg-row  { display:grid; grid-template-columns: 160px 1fr; gap:.5rem; margin:.25rem 0; }
        .box { border:1px dashed #e5e7eb; border-radius:.5rem; padding:.75rem; }
        .muted { color:#6b7280; font-size:.9rem; }
        .big   { font-size:1.05rem; font-weight:600; }
        .money { font-variant-numeric: tabular-nums; }
        .btns  { display:flex; gap:.5rem; flex-wrap:wrap; }
        .tag   { display:inline-block; padding:.1rem .5rem; border-radius:999px; background:#f3f4f6; font-size:.75rem; }
        .sep   { height:1px; background:#f3f4f6; margin:.5rem 0; }
        .ttl   { display:flex; justify-content:flex-end; gap:1rem; align-items:center; }
        .kv    { display:grid; grid-template-columns: 1fr auto; gap:.5rem; }
        .kv .k { color:#6b7280; }
        .kv .v { text-align:right; font-variant-numeric: tabular-nums; }
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
                <x-filament::button
                    tag="a"
                    {{-- href="{{ route('prints.reservations.show', ['reservation' => $res?->id, 'guest' => $rg->id]) }}" --}}
                    icon="heroicon-o-printer"
                    target="_blank"
                >
                    Print
                </x-filament::button>

                {{-- CHECK-IN: kalau ada route khusus --}}
                @if (Route::has('reservation-guests.checkin'))
                    <x-filament::button
                        color="success"
                        tag="a"
                        href="{{ route('reservation-guests.checkin', $rg->id) }}"
                        icon="heroicon-o-arrow-down-circle"
                    >
                        Check In
                    </x-filament::button>
                @endif

                {{-- ROOM CHANGE: arahkan ke halaman edit (header action Room Change akan muncul di sana) --}}
                <x-filament::button
                    color="warning"
                    tag="a"
                    {{-- href="{{ \Filament\Facades\Filament::getPanel('admin')->generateUrl('App\\Filament\\Resources\\ReservationGuests\\ReservationGuestResource', 'edit', ['record' => $rg->id]) }}" --}}
                    icon="heroicon-o-arrow-path"
                >
                    Room Change
                </x-filament::button>
            </div>
        </div>

        <div class="reg-grid">
            {{-- Kiri: Data Tamu --}}
            <div class="box">
                <div class="reg-row"><div>Name</div><div>{{ $guest?->display_name ?? $guest?->name ?? '-' }}</div></div>
                <div class="reg-row"><div>Address</div><div>{{ $guest?->address ?? '-' }}</div></div>
                <div class="reg-row"><div>Check In</div><div>{{ $guest?->guest_type ?? '-' }}</div></div>
                <div class="reg-row"><div>City/Country</div><div>{{ $guest?->city ?? '-' }}</div></div>
                <div class="reg-row"><div>Nationality</div><div>{{ $guest?->nationality ?? '-' }}</div></div>
                <div class="reg-row"><div>Profession</div><div>{{ $guest?->profession ?? '-' }}</div></div>
                <div class="reg-row">
                    <div>Identity Card</div>
                    <div>{{ $guest?->id_type ?? '-' }} {{ $guest?->id_card ?? '' }}</div>
                </div>
                <div class="reg-row"><div>Birth</div><div>{{ $guest?->birth_place ?? '' }}, {{$guest?->birth_date?->translatedFormat('d F Y') ?? '-' }}</div></div>
                <div class="reg-row"><div>Issued</div><div>{{ $guest?->issued_place ?? '' }}, {{$guest?->issued_date?->translatedFormat('d F Y') ?? '-' }}</div></div>
                <div class="sep"></div>
                <div class="reg-row"><div>Room No.</div><div>{{ $room?->room_no ?? '-' }} {{ $room?->type ? '— '.$room->type : '' }}</div></div>
                <div class="reg-row"><div>Rate Type</div><div>{{ $res?->method ?? '-' }}</div></div>
                <div class="reg-row"><div>Extra Bed</div><div> {{ $qty > 0 ? ($qty . ' — Rp.' . number_format($total, 0, ',', '.')) : '-' }}</div></div>
                <div class="reg-row">
                    <div>Pax/Persons</div>
                    <div>
                        {{ (int)($rg?->jumlah_orang ?? 0) }} 
                        <span class="muted"> ( Male: {{ (int)($rg?->male ?? 0) }}, Female: {{ (int)($rg?->female ?? 0) }}, Children: {{ (int)($rg?->children ?? 0) }} )</span>
                    </div>
                </div>

                <div class="sep"></div>

                <div class="kv">
                    <div class="k">Basic Rate</div>
                    <div class="v money">Rp {{ $n($rg?->room_rate ?? 0) }}</div>

                    <div class="k">% Service</div>
                    <div class="v">0</div>

                    <div class="k">% Tax</div>
                    <div class="v">0</div>
                </div>

                <div class="sep"></div>

                <div class="ttl">
                    <div class="muted">RATE ++</div>
                    <div class="big money">Rp {{ $n($rg?->room_rate ?? 0) }}</div>
                </div>
            </div>

            {{-- Kanan: Data Kamar & Tariff --}}
            <div class="box">
                <div class="reg-row"><div>Purpose of Visit</div><div>{{ $rg?->pov ?? '-' }}</div></div>
                <div class="reg-row"><div>Length of Stay</div><div>{{ $res?->length_of_stay ? $res->length_of_stay . ' Night(s)' : '-' }}</div></div>
                <div class="reg-row"><div>Arrival</div><div>{{ $fmt($res?->expected_arrival) }}</div></div>
                <div class="reg-row"><div>Departure</div><div>{{ $fmt($res?->expected_departure) }}</div></div>
                <div class="reg-row"><div>Company</div><div>{{ $company?->name ?? '-' }}</div></div>
                 <div class="reg-row"><div>Code</div><div>{{ $res?->option ?? '-' }}</div></div>
                <div class="reg-row"><div>Charge To</div><div>{{ $rg?->charge_to ?? '-' }}</div></div>
                <div class="reg-row"><div>Phone No</div><div>{{ $guest?->phone ?? '-' }}</div></div>
                <div class="reg-row"><div>Email</div><div>{{ $guest?->email ?? '-' }}</div></div>
                <div class="reg-row">
                    <div>Status</div>
                    <div>
                        <span class="tag">{{ $res?->status ?? 'DRAFT' }}</span>
                        @if($rg?->actual_checkin) <span class="tag">Checked-In</span> @endif
                    </div>
                </div>
                <div class="sep"></div>

                <div class="reg-row"><div>Short Remarks</div><div>{{ $rg?->note ?? '-' }}</div></div>
                <div class="reg-row"><div>Actual Check-In</div><div>{{ $fmt($rg?->actual_checkin) }}</div></div>
                <div class="reg-row"><div>Actual Check-Out</div><div>{{ $fmt($rg?->actual_checkout) }}</div></div>
            </div>
        </div>
    </div>
@endif
