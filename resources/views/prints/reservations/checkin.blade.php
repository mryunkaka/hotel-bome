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
        $fmtMoney = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
        $fmtDateShort = fn($v) => $v ? Carbon::parse($v)->format('d/m H:i') : '-';
        $fmtDateFull = fn($v) => $v ? Carbon::parse($v)->format('d/m/Y H:i') : '-';

        // Data hotel kanan-atas
        $hotelRight = array_filter([$hotel?->name, $hotel?->address, $hotel?->city, $hotel?->phone, $hotel?->email]);

        // ===== Input utama dari ROUTE (jangan pakai $getRecord di print) =====
        $row = $row ?? [];
        $rate = (float) ($row['rate'] ?? ($row['room_rate'] ?? 0));
        $discPct = (float) ($row['discount_percent'] ?? 0);
        $taxPct = (float) ($row['tax_percent'] ?? 0);
        $serviceRp = (int) ($row['service'] ?? 0);
        $extraBedRp = (int) ($row['extra_bed_total'] ?? 0);
        $tz = 'Asia/Makassar';

        // ===== Nights: actual_in → (actual_out | expected_out) =====
        $actualIn = $row['actual_in'] ?? null;
        $actualOut = $row['actual_out'] ?? null;
        $expectedOut = $row['expected_out'] ?? null;

        if ($actualIn && ($actualOut || $expectedOut)) {
            $in = Carbon::parse($actualIn)->startOfDay();
            $out = Carbon::parse($actualOut ?: $expectedOut)->startOfDay();
            $nights = max(1, $in->diffInDays($out));
        } else {
            $nights = max(1, (int) ($row['nights'] ?? 1));
        }
        $__nights = max(1, (int) $nights); // guard

        // ===== Ambil Reservation utk expected_arrival via $invoiceId (fallback jika perlu) =====
        $resvObj = isset($reservation)
            ? $reservation
            : (isset($invoiceId)
                ? \App\Models\Reservation::find($invoiceId)
                : null);

        $expectedArrival = $resvObj?->expected_arrival ?? ($row['expected_checkin'] ?? ($row['expected_in'] ?? null));

        // ===== Penalty: expected_arrival vs RG.actual_checkin (BUKAN now) =====
        $pen = ReservationMath::latePenalty(
            $expectedArrival,
            $actualIn ?: null, // normalnya sudah ada saat print
            $rate,
            ['tz' => $tz],
        );
        $penaltyHours = (int) ($pen['hours'] ?? 0);
        $lateRp = (int) ($pen['amount'] ?? 0);

        // ===== Diskon pada basic rate (per malam) =====
        $basicDiscountAmount = round(($rate * $discPct) / 100);
        $basicRateDisc = max(0, $rate - $basicDiscountAmount); // rate setelah diskon (per malam)
        $rateAfterDiscPerNight = $basicRateDisc;
        $rateAfterDiscTimesNights = $rateAfterDiscPerNight * $__nights;

        // ===== Amount kamar untuk kolom "Rate × Nights" (tetap basic) =====
        $subtotalBase = $rate * $__nights;

        // ===== SUBTOTAL sebelum pajak: (rate disc × nights) + service + extra + penalty =====
        $subtotalBeforeTax = max(0.0, $rateAfterDiscTimesNights + $serviceRp + $extraBedRp + $lateRp);

        // ===== Pajak persen di atas subtotal (penalty ikut kena pajak) =====
        $taxVal = $taxPct > 0 ? round(($subtotalBeforeTax * $taxPct) / 100) : 0;

        // ===== Grand total =====
        $grandTotal = $subtotalBeforeTax + $taxVal;

        // ===== Nilai untuk template (pertahankan variabel lama) =====
        $finalRatePerNight = $rate; // tampil per malam (basic)
        $amount = $subtotalBase; // kolom Amount = room charge (basic × nights)
        $totalNights = $__nights;
        $subtotal = $subtotalBeforeTax;
        $tax_total = $taxVal;
        $total = $grandTotal;

        $breakdown = [
            // 'basic_rate' => $rate, // aktifkan bila ingin tampilkan baris Basic Rate
            'room_discount_percent' => $discPct,
            'room_tax_percent' => $taxPct,
            'service_rp' => $serviceRp,
            'extra_bed_rp' => $extraBedRp,
            'late_arrival_penalty_rp' => $lateRp,
            'rate_plus_plus' => $finalRatePerNight,
            // tambahan agar bisa ditampilkan jika perlu:
            'rate_after_disc_per_night' => $rateAfterDiscPerNight,
            'rate_after_disc_times_nights' => $rateAfterDiscTimesNights,
        ];
    @endphp

    <title>Check-in Slip — {{ $invoiceNo ?? '#' . ($invoiceId ?? '-') }}</title>

    <style>
        @page {
            size: {{ $paper }} {{ $orientation }};
            margin: 10mm;
        }

        body {
            margin: 0;
            padding: 0;
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 10px;
            color: #111827;
            line-height: 1.3;
        }

        .hdr-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
        }

        .hdr-td {
            vertical-align: top;
            padding: 0;
        }

        .hdr-left {
            width: 35%;
        }

        .hdr-mid {
            width: 30%;
            text-align: center;
        }

        .hdr-right {
            width: 35%;
            text-align: right;
        }

        .title {
            font-size: 16px;
            font-weight: 700;
            text-decoration: underline;
            margin-bottom: 2px;
        }

        .resv-no {
            font-weight: 600;
            font-size: 10px;
        }

        .logo {
            display: inline-block;
            vertical-align: middle;
        }

        .logo img {
            height: 50px;
            object-fit: contain;
        }

        .hotel-meta {
            color: #111827;
            font-size: 9px;
            line-height: 1.3;
        }

        /* PERBAIKAN: Tabel informasi atas dengan layout yang lebih rapi */

        .info-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            /* stabil */
            font-size: 10px;
            margin: 6px 0 2px;
        }

        /* Lebar total A4: 210mm, margin @page 10mm kiri/kanan -> area konten 190mm
     Bagi rata:  LBL 28mm | ":" 3mm | VAL 60mm | SP 6mm | LBL-R 32mm | ":" 3mm | VAL-R 58mm = 190mm */
        .w-lbl {
            width: 28mm;
        }

        .w-colon {
            width: 3mm;
            text-align: center;
        }

        .w-val {
            width: 60mm;
        }

        .w-sp {
            width: 6mm;
        }

        .w-lblr {
            width: 32mm;
        }

        .w-valr {
            width: 58mm;
        }

        .info-table td {
            padding: 2px 0;
            vertical-align: top;
        }

        .info-label {
            color: #374151;
            font-weight: 600;
            white-space: nowrap;
            /* label jangan pecah */
            word-break: normal;
            /* cegah pecah per huruf */
            hyphens: manual;
            /* aman di DomPDF */
        }

        .info-value {
            color: #111827;
            font-weight: 600;
            white-space: nowrap;
            /* MINTA: tetap 1 baris */
            /* ellipsis kurang didukung DomPDF; biarkan terpotong natural */
            overflow: hidden;
            /* di DomPDF sering diabaikan, tapi tetap aman */
        }

        .row-tight {
            height: 16px;
        }

        /* sesuaikan kebutuhan */

        .info-spacer {
            width: 40px;
        }

        .line {
            border-top: 1px solid #1F2937;
            margin: 8px 0;
        }

        table.items {
            width: 100%;
            border-collapse: collapse;
            font-size: 9px;
            margin-top: 6px;
            table-layout: fixed;
        }

        .items thead th {
            border-top: 1px solid #1F2937;
            border-bottom: 1px solid #1F2937;
            padding: 5px 4px;
            text-align: left;
            font-weight: 600;
        }

        .items td {
            border-bottom: 1px solid #D1D5DB;
            padding: 5px 4px;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .items tfoot td {
            border-top: 1px solid #1F2937;
            font-weight: 700;
            padding: 5px 4px;
        }

        .center {
            text-align: center;
        }

        .right {
            text-align: right;
        }

        .nowrap {
            white-space: nowrap;
        }

        .col-room {
            width: 40px;
        }

        .col-cat {
            width: auto;
        }

        .col-pax {
            width: 35px;
        }

        .col-rate {
            width: 75px;
        }

        .col-night {
            width: 40px;
        }

        .col-in {
            width: 80px;
        }

        .col-out {
            width: 80px;
        }

        .col-amount {
            width: 85px;
        }

        .totals-box-wrap {
            width: 100%;
            margin-top: 8px;
        }

        .totals-box {
            margin-left: auto;
            width: 320px;
            border-collapse: collapse;
            table-layout: fixed;
            font-size: 10px;
        }

        .totals-box td {
            padding: 4px 5px;
        }

        .tb-k {
            width: 170px;
            color: #374151;
        }

        .tb-v {
            width: 150px;
            text-align: right;
            font-weight: 600;
        }

        .tb-line th {
            border-top: 1px solid #1F2937;
            padding-top: 5px;
        }

        .tb-strong {
            font-weight: 700;
        }

        .footer {
            margin-top: 12px;
        }

        .foot-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9px;
        }

        .foot-left,
        .foot-mid,
        .foot-right {
            padding: 3px 0;
            vertical-align: top;
        }

        .foot-left {
            text-align: left;
            width: 25%;
        }

        .foot-mid {
            text-align: center;
            width: 50%;
        }

        .foot-right {
            text-align: right;
            width: 25%;
        }

        .signature-box {
            display: inline-block;
            margin-top: 20px;
            min-width: 150px;
            text-align: center;
        }

        .signature-line {
            border-top: 1px solid #9CA3AF;
            margin-top: 25px;
            padding-top: 4px;
        }
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

    {{-- ===== INFO ATAS - PERBAIKAN LAYOUT ===== --}}
    <table class="info-table">
        <tbody>
            <tr class="row-tight">
                <td class="info-label w-lbl">Status</td>
                <td class="w-colon">:</td>
                <td class="info-value w-val">{{ strtoupper($status ?? 'CONFIRM') }}</td>

                <td class="w-sp"></td>

                <td class="info-label w-lblr">Payment Method</td>
                <td class="w-colon">:</td>
                <td class="info-value w-valr">{{ ucfirst($payment['method'] ?? 'personal') }}</td>
            </tr>

            <tr class="row-tight">
                <td class="info-label w-lbl">Reserved By</td>
                <td class="w-colon">:</td>
                <td class="info-value w-val">{{ $companyName ?: '-' }}</td>

                <td class="w-sp"></td>

                <td class="info-label w-lblr">Entry Date</td>
                <td class="w-colon">:</td>
                <td class="info-value w-valr">{{ $fmtDateFull($issuedAt ?? ($generatedAt ?? now())) }}</td>
            </tr>

            <tr class="row-tight">
                <td class="info-label w-lbl">Guest Name</td>
                <td class="w-colon">:</td>
                <td class="info-value w-val">{{ $row['guest_display'] ?? '-' }}</td>

                <td class="w-sp"></td>

                <td class="info-label w-lblr">Clerk</td>
                <td class="w-colon">:</td>
                <td class="info-value w-valr">{{ $clerkName ?? '-' }}</td>
            </tr>
        </tbody>
    </table>

    <div class="line"></div>

    {{-- ===== ITEM TABLE ===== --}}
    <table class="items">
        <thead>
            <tr>
                <th class="col-room">Room</th>
                <th class="col-cat">Category</th>
                <th class="col-pax center">Pax</th>
                <th class="col-rate right">Rate</th>
                <th class="col-night center">Nights</th>
                <th class="col-in center">Check-in</th>
                <th class="col-out center">Check-out</th>
                <th class="col-amount right">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="nowrap">{{ $row['room_no'] ?? '-' }}</td>
                <td class="nowrap">{{ $row['category'] ?? '-' }}</td>
                <td class="center nowrap">{{ (int) ($row['ps'] ?? 1) }}</td>
                <td class="right nowrap">{{ $fmtMoney($finalRatePerNight) }}</td>
                <td class="center nowrap">{{ $nights }}</td>
                <td class="center nowrap">
                    {{ $fmtDateShort($row['actual_in'] ?? ($row['expected_in'] ?? ($row['expected_checkin'] ?? null))) }}
                </td>
                <td class="center nowrap">
                    @php $outShown = $row['actual_out'] ?? ($row['expected_out'] ?? ($row['expected_checkout'] ?? null)); @endphp
                    {{ $fmtDateShort($outShown) }}
                </td>
                <td class="right nowrap">{{ $fmtMoney($amount) }}</td>
            </tr>
        </tbody>
    </table>

    {{-- ===== TOTALS BOX ===== --}}
    <div class="totals-box-wrap">
        <table class="totals-box">
            <tr>
                <td class="tb-k">Discount {{ $discPct }}% × Nights</td>
                <td class="tb-v">
                    {{ $fmtMoney($breakdown['rate_after_disc_times_nights'] ?? 0) }}
                </td>
            </tr>
            <tr>
                <td class="tb-k">Service (Rp)</td>
                <td class="tb-v">
                    {{ $fmtMoney($breakdown['service_rp'] ?? 0) }}
                </td>
            </tr>
            <tr>
                <td class="tb-k">Extra Bed</td>
                <td class="tb-v">
                    {{ $fmtMoney($breakdown['extra_bed_rp'] ?? 0) }}
                </td>
            </tr>
            <tr>
                <td class="tb-k">
                    Late Arrival Penalty{{ $penaltyHours ? ' (' . $penaltyHours . ' h)' : '' }}
                </td>
                <td class="tb-v">
                    {{ $fmtMoney($breakdown['late_arrival_penalty_rp'] ?? 0) }}
                </td>
            </tr>
            <tr>
                <td class="tb-k">
                    Tax {{ number_format((float) ($breakdown['room_tax_percent'] ?? $taxPct), 2, ',', '.') }}%
                </td>
                <td class="tb-v">
                    {{ $fmtMoney($tax_total ?? 0) }}
                </td>
            </tr>

            <tr class="tb-line">
                <th colspan="2"></th>
            </tr>
            <tr class="tb-strong">
                <td class="tb-k">Grand Total</td>
                <td class="tb-v">{{ $fmtMoney($total ?? 0) }}</td>
            </tr>
        </table>
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
            <tr>
                <td class="foot-left"></td>
                <td class="foot-mid">
                    <div class="signature-box">
                        <div class="signature-line"></div>
                        {{ $clerkName ?? ($reserved_by ?? 'Reception/Cashier') }}
                    </div>
                </td>
                <td class="foot-right"></td>
            </tr>
        </table>
    </div>
</body>

</html>
