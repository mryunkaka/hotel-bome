<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    @php
        use Illuminate\Support\Carbon;
        use App\Support\ReservationMath;

        // ===== Paper & orientation =====
        $paper = strtoupper($paper ?? 'A4');
        $orientation = in_array(strtolower($orientation ?? 'portrait'), ['portrait', 'landscape'], true)
            ? strtolower($orientation)
            : 'portrait';

        // ===== Helpers tampilan =====
        $fmtMoney     = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
        $fmtDateShort = fn($v) => $v ? Carbon::parse($v)->format('d/m H:i') : '-';
        $fmtDateFull  = fn($v) => $v ? Carbon::parse($v)->format('d/m/Y H:i') : '-';

        // Data hotel kanan-atas
        $hotelRight = array_filter([$hotel?->name, $hotel?->address, $hotel?->city, $hotel?->phone, $hotel?->email]);

        // ===== Input utama dari ROUTE =====
        $row    = $row ?? [];
        $rate   = (float) ($row['rate'] ?? ($row['room_rate'] ?? 0));
        $discPct = (float) ($row['discount_percent'] ?? 0);

        // TAMBAH: pastikan $resvObj siap SEBELUM baca pajak
        $resvObj = isset($reservation)
            ? $reservation
            : (isset($invoiceId)
                ? \App\Models\Reservation::find($invoiceId)
                : null); // TAMBAH

        // GANTI: baca pajak dari reservation (global), fallback row
        $taxPct = (float) ($resvObj?->tax?->percent ?? ($row['tax_percent'] ?? 0)); // GANTI

        $serviceRp  = (int) ($row['service'] ?? 0);
        $extraBedRp = (int) ($row['extra_bed_total'] ?? 0);
        $tz         = 'Asia/Makassar';

        // ===== Nights: actual_in → (actual_out | expected_out) =====
        $actualIn    = $row['actual_in']  ?? null;
        $actualOut   = $row['actual_out'] ?? null;
        $expectedOut = $row['expected_out'] ?? null;

        if ($actualIn && ($actualOut || $expectedOut)) {
            $in  = Carbon::parse($actualIn)->startOfDay();
            $out = Carbon::parse($actualOut ?: $expectedOut)->startOfDay();
            $nights = max(1, $in->diffInDays($out));
        } else {
            $nights = max(1, (int) ($row['nights'] ?? 1));
        }
        $__nights = max(1, (int) $nights); // guard

        // ===== Penalty: expected_arrival vs RG.actual_checkin (BUKAN now) =====
        $expectedArrival = $resvObj?->expected_arrival
            ?? ($row['expected_checkin'] ?? ($row['expected_in'] ?? null));

        $pen = ReservationMath::latePenalty(
            $expectedArrival,
            $actualIn ?: null,
            $rate,
            ['tz' => $tz],
        );
        $penaltyHours = (int) ($pen['hours']  ?? 0);
        $lateRp       = (int) ($pen['amount'] ?? 0);

        // ===== Diskon pada basic rate (per malam) =====
        $basicDiscountAmount       = round(($rate * $discPct) / 100);
        $basicRateDisc             = max(0, $rate - $basicDiscountAmount);
        $rateAfterDiscPerNight     = $basicRateDisc;
        $rateAfterDiscTimesNights  = $rateAfterDiscPerNight * $__nights;

        // ===== Amount kamar untuk kolom "Rate × Nights" (tetap basic) =====
        $subtotalBase = $rate * $__nights;

        // ===== SUBTOTAL sebelum pajak =====
        $subtotalBeforeTax = max(0.0, $rateAfterDiscTimesNights + $serviceRp + $extraBedRp + $lateRp);

        // ===== Pajak persen di atas subtotal =====
        $taxVal = $taxPct > 0 ? round(($subtotalBeforeTax * $taxPct) / 100) : 0;

        // ===== Grand total (tidak ditampilkan di slip check-in) =====
        $grandTotal = $subtotalBeforeTax + $taxVal;

        // ===== Nilai untuk template (pertahankan variabel lama) =====
        $finalRatePerNight = $rate;        // per malam (basic)
        $amount            = $subtotalBase; // (disembunyikan di tabel)
        $totalNights       = $__nights;
        $subtotal          = $subtotalBeforeTax;
        $tax_total         = $taxVal;
        $total             = $grandTotal;

        $breakdown = [
            'room_discount_percent'          => $discPct,
            'room_tax_percent'               => $taxPct,
            'service_rp'                     => $serviceRp,
            'extra_bed_rp'                   => $extraBedRp,
            'late_arrival_penalty_rp'        => $lateRp,
            'rate_plus_plus'                 => $finalRatePerNight,
            'rate_after_disc_per_night'      => $rateAfterDiscPerNight,
            'rate_after_disc_times_nights'   => $rateAfterDiscTimesNights,
        ];
    @endphp

    <title>Check-in Slip — {{ $invoiceNo ?? '#' . ($invoiceId ?? '-') }}</title>

    <style>
        @page { size: {{ $paper }} {{ $orientation }}; margin: 10mm; }
        body { margin:0; padding:0; font-family: DejaVu Sans, Arial, sans-serif; font-size:10px; color:#111827; line-height:1.3; }

        .hdr-table { width:100%; border-collapse:collapse; margin-bottom:8px; }
        .hdr-td { vertical-align:top; padding:0; }
        .hdr-left { width:35%; }
        .hdr-mid { width:30%; text-align:center; }
        .hdr-right { width:35%; text-align:right; }

        .title { font-size:16px; font-weight:700; text-decoration:underline; margin-bottom:2px; }
        .resv-no { font-weight:600; font-size:10px; }

        .logo { display:inline-block; vertical-align:middle; }
        .logo img { height:50px; object-fit:contain; }
        .hotel-meta { color:#111827; font-size:9px; line-height:1.3; }

        /* info atas */
        .info-table { width:100%; border-collapse:collapse; table-layout:fixed; font-size:10px; margin:6px 0 2px; }
        .w-lbl{ width:28mm; } .w-colon{ width:3mm; text-align:center; } .w-val{ width:60mm; }
        .w-sp{ width:6mm; } .w-lblr{ width:32mm; } .w-valr{ width:58mm; }
        .info-table td { padding:2px 0; vertical-align:top; }
        .info-label{ color:#374151; font-weight:600; white-space:nowrap; }
        .info-value{ color:#111827; font-weight:600; white-space:nowrap; overflow:hidden; }
        .row-tight{ height:16px; }
        .line { border-top:1px solid #1F2937; margin:8px 0; }

        /* tabel item */
        table.items { width:100%; border-collapse:collapse; font-size:9px; margin-top:6px; table-layout:fixed; }
        .items thead th { border-top:1px solid #1F2937; border-bottom:1px solid #1F2937; padding:5px 4px; text-align:left; font-weight:600; }
        .items td { border-bottom:1px solid #D1D5DB; padding:5px 4px; overflow:hidden; text-overflow:ellipsis; }
        .center{ text-align:center; } .right{ text-align:right; } .nowrap{ white-space:nowrap; }
        .col-room{ width:40px; } .col-cat{ width:auto; } .col-pax{ width:35px; } .col-rate{ width:75px; }
        .col-night{ width:40px; } .col-in{ width:80px; } .col-out{ width:80px; }

        /* TAMBAH: blok variasi */
        .subtle { color:#6b7280; }
        .note { font-size:9px; color:#6b7280; margin-top:6px; }
        .section { margin-top:10px; }
        .mini-title { font-weight:700; font-size:10px; text-transform:uppercase; letter-spacing:.3px; margin-bottom:4px; }
        .box { border:1px solid #E5E7EB; border-radius:6px; padding:6px 8px; }
        .grid-2 { display:table; width:100%; table-layout:fixed; }
        .col { display:table-cell; vertical-align:top; width:50%; padding:0 4px; }
        .kv { width:100%; border-collapse:collapse; font-size:9px; }
        .kv td { padding:3px 0; vertical-align:top; }
        .kv .k { color:#6b7280; width:36%; white-space:nowrap; }
        .kv .v { color:#111827; font-weight:600; }
        .pill { display:inline-block; padding:1px 6px; border:1px solid #c7d2fe; border-radius:999px; background:#eef2ff; color:#3730a3; font-size:9px; line-height:14px; }
        .list { margin:0; padding-left:14px; }
        .list li { margin:2px 0; }

        /* footer */
        .footer { margin-top:12px; }
        .foot-table { width:100%; border-collapse:collapse; font-size:9px; }
        .foot-left,.foot-mid,.foot-right { padding:3px 0; vertical-align:top; }
        .foot-left{ text-align:left; width:25%; } .foot-mid{ text-align:center; width:50%; } .foot-right{ text-align:right; width:25%; }
        .signature-box { display:inline-block; margin-top:14px; min-width:150px; text-align:center; }
        .signature-line { border-top:1px solid #9CA3AF; margin-top:22px; padding-top:4px; }
    </style>
</head>
<body>

    {{-- ===== HEADER ===== --}}
    <table class="hdr-table">
        <tr>
            <td class="hdr-td hdr-left">
                @if (!empty($logoData))
                    <span class="logo"><img src="{{ $logoData }}" alt="Logo"></span>
                @endif
            </td>
            <td class="hdr-td hdr-mid">
                <div class="title">{{ $title ?? 'GUEST CHECK-IN' }}</div>
                <div class="resv-no">{{ $invoiceNo ?? '#' . ($invoiceId ?? '-') }}</div>
            </td>
            <td class="hdr-td hdr-right">
                <div class="hotel-meta">
                    @if (!empty($hotelRight))
                        {!! implode('<br>', array_map('e', $hotelRight)) !!}
                        @if ($hotel?->postcode)
                            <br>{{ e($hotel->postcode) }}
                        @endif
                    @else
                        &nbsp;
                    @endif
                </div>
            </td>
        </tr>
    </table>

    {{-- ===== INFO ATAS ===== --}}
    <table class="info-table">
        <tbody>
            <tr class="row-tight">
                <td class="info-label w-lbl">Status</td><td class="w-colon">:</td>
                <td class="info-value w-val">{{ strtoupper($status ?? 'CONFIRM') }}</td>
                <td class="w-sp"></td>
                <td class="info-label w-lblr">Payment Method</td><td class="w-colon">:</td>
                <td class="info-value w-valr">{{ ucfirst($payment['method'] ?? 'personal') }}</td>
            </tr>
            <tr class="row-tight">
                <td class="info-label w-lbl">Reserved By</td><td class="w-colon">:</td>
                <td class="info-value w-val">{{ $companyName ?: '-' }}</td>
                <td class="w-sp"></td>
                <td class="info-label w-lblr">Entry Date</td><td class="w-colon">:</td>
                <td class="info-value w-valr">{{ $fmtDateFull($issuedAt ?? ($generatedAt ?? now())) }}</td>
            </tr>
            <tr class="row-tight">
                <td class="info-label w-lbl">Guest Name</td><td class="w-colon">:</td>
                <td class="info-value w-val">{{ $row['guest_display'] ?? '-' }}</td>
                <td class="w-sp"></td>
                 <td class="info-label w-lblr">Deposit Card</td><td class="w-colon">:</td>
                <td class="info-value w-valr">{{ $fmtMoney($deposit_card ?? 0) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="line"></div>

    {{-- ===== ITEM TABLE (tanpa Amount) ===== --}}
    <table class="items">
        <thead>
            <tr>
                <th class="col-room">Room</th>
                <th class="col-cat">Category</th>
                <th class="col-pax center">Pax</th>
                <th class="col-rate right">Rate / Night</th> {{-- GANTI label --}}
                <th class="col-night center">Nights</th>
                <th class="col-in center">Check-in</th>
                <th class="col-out center">Check-out</th>
                {{-- HAPUS kolom Amount --}}
            </tr>
        </thead>
        @php
            // jumlah tamu dalam satu reservasi (fallback 1)
            $guestCount = (int) ($resvObj?->reservationGuests?->count() ?? 1);
        @endphp
        <tbody>
            @if ($guestCount > 1)
                @foreach ($resvObj->reservationGuests as $g)
                    @php
                        $rRoomNo = $g->room?->room_no ?? ('#' . $g->room_id);
                        $rCat    = $g->room?->type ?? '-';
                        $rRate   = (float) ($g->room_rate ?? $g->room?->price ?? 0);
                        $rIn     = $g->actual_checkin ?? $g->expected_checkin;
                        $rOut    = $g->actual_checkout ?? $g->expected_checkout;
                        $rNights = ($rIn && $rOut)
                            ? max(1, \Illuminate\Support\Carbon::parse($rIn)->startOfDay()->diffInDays(
                                    \Illuminate\Support\Carbon::parse($rOut)->startOfDay()))
                            : (int) ($g->nights ?? 1);
                        $rPax    = (int) ($g->jumlah_orang ?? max(1, (int)$g->male + (int)$g->female + (int)$g->children));
                    @endphp
                    <tr>
                        <td class="nowrap">{{ $rRoomNo }}</td>
                        <td class="nowrap">{{ $rCat }}</td>
                        <td class="center nowrap">{{ $rPax }}</td>
                        <td class="right nowrap">{{ $fmtMoney($rRate) }}</td>
                        <td class="center nowrap">{{ $rNights }}</td>
                        <td class="center nowrap">{{ $fmtDateShort($rIn) }}</td>
                        <td class="center nowrap">{{ $fmtDateShort($rOut) }}</td>
                    </tr>
                @endforeach
            @else
                @php $outShown = $row['actual_out'] ?? ($row['expected_out'] ?? ($row['expected_checkout'] ?? null)); @endphp
                <tr>
                    <td class="nowrap">{{ $row['room_no'] ?? '-' }}</td>
                    <td class="nowrap">{{ $row['category'] ?? '-' }}</td>
                    <td class="center nowrap">{{ (int) ($row['ps'] ?? 1) }}</td>
                    <td class="right nowrap">{{ $fmtMoney($finalRatePerNight) }}</td>
                    <td class="center nowrap">{{ $nights }}</td>
                    <td class="center nowrap">{{ $fmtDateShort($row['actual_in'] ?? ($row['expected_in'] ?? ($row['expected_checkin'] ?? null))) }}</td>
                    <td class="center nowrap">{{ $fmtDateShort($outShown) }}</td>
                </tr>
            @endif
        </tbody>

    </table>

    {{-- TAMBAH: catatan pajak (dinamis) --}}
    @if (($taxPct ?? 0) > 0)
        <div class="note">* Room rate shown is exclusive of taxes ({{ number_format((float)$taxPct, 2, '.', '') }}%).</div>
    @else
        <div class="note subtle">* Room rate shown excludes any applicable fees.</div> {{-- TAMBAH --}}
    @endif

    {{-- TAMBAH: VARIASI — Guest & Stay Details, Notes/Requests, Policies --}}
    <div class="section box">
        @php
            $guestCount = (int) ($resvObj?->reservationGuests?->count() ?? 1);
        @endphp

        @if ($guestCount === 1)
            {{-- ==== Guest & Stay Details (single guest) ==== --}}
            <div class="mini-title">Guest & Stay Details</div>
            <div class="grid-2">
                <div class="col">
                    <table class="kv">
                        <tr><td class="k">Guest</td><td class="v">{{ $row['guest_display'] ?? '-' }}</td></tr>
                        <tr><td class="k">Reservation No</td><td class="v">{{ $invoiceNo ?? ('#' . ($invoiceId ?? '-')) }}</td></tr>
                        <tr><td class="k">Room</td><td class="v">{{ ($row['room_no'] ?? '-') . ' — ' . ($row['category'] ?? '-') }}</td></tr>
                        <tr>
                            <td class="k">In / Out</td>
                            <td class="v">
                                {{ $fmtDateFull($row['actual_in'] ?? ($row['expected_in'] ?? ($row['expected_checkin'] ?? null))) }}
                                <span class="subtle">→</span>
                                {{ $fmtDateFull(($outShown ?? null) ?? null) }}
                            </td>
                        </tr>
                    </table>
                </div>
                <div class="col">
                    <table class="kv">
                        <tr><td class="k">Rate / Night</td><td class="v">{{ $fmtMoney($finalRatePerNight) }}</td></tr>
                        <tr><td class="k">Nights</td><td class="v">{{ $nights }}</td></tr>
                        <tr>
                            <td class="k">Breakfast</td>
                            <td class="v">
                                @php $bf = ($row['breakfast'] ?? null) ?: ($resvObj?->breakfast ?? null); @endphp
                                {!! (strtoupper($bf ?? 'NO') === 'YES')
                                    ? '<span class="pill">Breakfast Included</span>'
                                    : '<span class="subtle">No</span>' !!}
                            </td>
                        </tr>
                        <tr>
                            <td class="k">Extra Bed</td>
                            <td class="v">{!! ($row['extra_bed_total'] ?? 0) > 0 ? '<span class="pill">Yes</span>' : '<span class="subtle">None</span>' !!}</td>
                        </tr>
                    </table>
                </div>
            </div>
        @else
            {{-- ==== Reservation Summary (multi guest) ==== --}}
            <div class="section box">
                <div class="mini-title">Reservation Summary</div>
                <table class="kv">
                    <tr><td class="k">Reserved By</td><td class="v">{{ $companyName ?: ($reserved_by ?? '-') }}</td></tr>
                    <tr><td class="k">Guests</td><td class="v">{{ $guestCount }}</td></tr>
                    <tr><td class="k">Reservation No</td><td class="v">{{ $invoiceNo ?? ('#' . ($invoiceId ?? '-')) }}</td></tr>
                    <tr>
                        <td class="k">Period</td>
                        <td class="v">
                            {{ $fmtDateFull($resvObj?->expected_arrival) }}
                            <span class="subtle">→</span>
                            {{ $fmtDateFull($resvObj?->expected_departure) }}
                        </td>
                    </tr>
                </table>

            </div>
        @endif

    </div>

    @php $remarks = $reservation?->remarks ?? null; @endphp
    @if ($remarks)
        <div class="section box">
            <div class="mini-title">Notes / Requests</div>
            <div style="font-size:9px;">{{ $remarks }}</div>
        </div>
    @endif

    <div class="section box">
        <div class="mini-title">Policies & Acknowledgement</div>
        <ul class="list">
            <li>Check-in time after 13:00; check-out time before 12:00 (noon).</li>
            <li>Late check-out is subject to availability and may incur charges.</li>
            <li>Room rate is exclusive of taxes and incidentals unless stated otherwise.</li>
            <li>Non-smoking room; a cleaning fee may apply for violations.</li>
        </ul>
        <div class="grid-2" style="margin-top:6px;">
            <div class="col">
                <div class="signature-box">
                    <div class="signature-line"></div>
                    Guest Signature
                </div>
            </div>
            <div class="col" style="text-align:right;">
                <div class="signature-box">
                    <div class="signature-line"></div>
                    {{ $clerkName ?? 'Reception' }}
                </div>
            </div>
        </div>
    </div>

    <div class="line"></div>

    {{-- ===== FOOTER ===== --}}
    <div class="footer">
        <table class="foot-table">
            <tr>
                <td class="foot-left">Page: 1</td>
                <td class="foot-mid">
                    {{ $hotel?->city ? $hotel->city . ', ' : '' }}{{ $fmtDateFull($generatedAt ?? now()) }}
                </td>
                <td class="foot-right">&nbsp;</td>
            </tr>
        </table>
    </div>
</body>
</html>
