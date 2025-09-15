<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    @php
        use Illuminate\Support\Carbon;
        use App\Support\ReservationMath;

        $paper = strtoupper($paper ?? 'A4');
        $orientation = in_array(strtolower($orientation ?? 'portrait'), ['portrait', 'landscape'], true)
            ? strtolower($orientation)
            : 'portrait';

        $fmtMoney = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
        $fmtDateShort = fn($v) => $v ? Carbon::parse($v)->format('d/m H:i') : '-';
        $fmtDateFull = fn($v) => $v ? Carbon::parse($v)->format('d/m/Y H:i') : '-';

        $hotelRight = array_filter([$hotel?->name, $hotel?->address, $hotel?->city, $hotel?->phone, $hotel?->email]);

        // ====== RATE ======
        $baseRate = (float) ($row['rate'] ?? 0);
        $discPct = (float) ($row['discount_percent'] ?? 0);
        $taxPct = (float) ($row['tax_percent'] ?? 0);
        $serviceRp = (float) ($row['service'] ?? 0);
        $extraBedRp = (float) ($row['extra_bed_total'] ?? 0);
        $lateRp = (float) ($row['late_arrival_penalty'] ?? 0);

        // Tanggal untuk hitung nights
        $inForNights = $row['actual_in'] ?? ($row['expected_in'] ?? ($row['expected_checkin'] ?? null));
        $outForNights = $row['actual_out'] ?? ($row['expected_out'] ?? ($row['expected_checkout'] ?? null));

        // Nights dari data (fallback = 1)
        $nights = (int) max(1, (int) ($row['nights'] ?? 1));

        // Override dari selisih hari kalender bila in/out valid
        if ($inForNights && $outForNights) {
            try {
                $nightsDiff = Carbon::parse($inForNights)
                    ->startOfDay()
                    ->diffInDays(Carbon::parse($outForNights)->startOfDay());
                $nights = max(1, (int) $nightsDiff);
            } catch (\Throwable $e) {
            }
        }

        $finalRatePerNight = ReservationMath::calcFinalRate(
            [
                'rate' => $baseRate,
                'discount_percent' => $discPct,
                'tax_percent' => $taxPct,
                'service' => $serviceRp,
                'extra_bed_total' => $extraBedRp,
                'late_arrival_penalty' => $lateRp,
                'id_tax' => $row['id_tax'] ?? null,
            ],
            [
                'tax_lookup' => $taxLookup ?? [],
                'service_taxable' => false,
                'rounding' => 0,
            ],
        );

        // Amount = rate per night × nights
        $amount = $finalRatePerNight * $nights;
        $totalNights = $nights;
        $subtotal = $amount;
        $tax_total = 0;
        $total = $subtotal;

        $breakdown = [
            'basic_rate' => $baseRate,
            'room_discount_percent' => $discPct,
            'room_tax_percent' => $taxPct,
            'service_rp' => $serviceRp,
            'extra_bed_rp' => $extraBedRp,
            'late_arrival_penalty_rp' => $lateRp,
            'rate_plus_plus' => $finalRatePerNight,
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

        .kv {
            width: 100%;
            border-collapse: collapse;
            margin: 6px 0;
            table-layout: fixed;
            font-size: 10px;
        }

        .kv col.kcol {
            width: 90px;
        }

        .kv col.vcol {
            width: 180px;
        }

        .kv td {
            padding: 2px 0;
            vertical-align: top;
        }

        .kv .k {
            color: #374151;
            position: relative;
        }

        .kv .k::after {
            content: ':';
            margin-left: 2px;
        }

        .kv .v {
            color: #111827;
            font-weight: 600;
            overflow: hidden;
            text-overflow: ellipsis;
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

    {{-- ===== INFO ATAS ===== --}}
    <table class="kv">
        <colgroup>
            <col class="kcol">
            <col class="vcol">
            <col class="kcol">
            <col class="vcol">
        </colgroup>
        <tr>
            <td class="k">Status</td>
            <td class="v">{{ strtoupper($status ?? 'CONFIRM') }}</td>
            <td class="k">Payment Method</td>
            <td class="v">{{ ucfirst($payment['method'] ?? 'personal') }}</td>
        </tr>
        <tr>
            <td class="k">Reserved By</td>
            <td class="v">{{ $companyName ?: '-' }}</td>
            <td class="k">Entry Date</td>
            <td class="v">{{ $fmtDateFull($issuedAt ?? ($generatedAt ?? now())) }}</td>
        </tr>
        <tr>
            <td class="k">Guest Name</td>
            <td class="v">{{ $row['guest_display'] ?? '-' }}</td>
            <td class="k">Clerk</td>
            <td class="v">{{ $clerkName ?? '-' }}</td>
        </tr>
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
        <tfoot>
            <tr>
                <td colspan="4" class="right">TOTAL NIGHTS</td>
                <td class="center">{{ $totalNights }}</td>
                <td colspan="2" class="right">TOTAL AMOUNT</td>
                <td class="right">{{ $fmtMoney($total) }}</td>
            </tr>
        </tfoot>
    </table>

    {{-- ===== TOTALS BOX ===== --}}
    <div class="totals-box-wrap">
        <table class="totals-box">
            <tr>
                <td class="tb-k">Basic Rate</td>
                <td class="tb-v">{{ $fmtMoney($breakdown['basic_rate']) }}</td>
            </tr>
            <tr>
                <td class="tb-k">Room Discount</td>
                <td class="tb-v">{{ number_format((float) $breakdown['room_discount_percent'], 2, ',', '.') }}%</td>
            </tr>
            <tr>
                <td class="tb-k">Room Tax</td>
                <td class="tb-v">{{ number_format((float) $breakdown['room_tax_percent'], 2, ',', '.') }}%</td>
            </tr>
            <tr>
                <td class="tb-k">Service Charge</td>
                <td class="tb-v">{{ $fmtMoney($breakdown['service_rp']) }}</td>
            </tr>
            <tr>
                <td class="tb-k">Extra Bed</td>
                <td class="tb-v">{{ $fmtMoney($breakdown['extra_bed_rp']) }}</td>
            </tr>
            <tr>
                <td class="tb-k">Late Arrival Penalty</td>
                <td class="tb-v">{{ $fmtMoney($breakdown['late_arrival_penalty_rp']) }}</td>
            </tr>

            <tr class="tb-line">
                <th colspan="2"></th>
            </tr>

            <tr>
                <td class="tb-k">Rate × Nights</td>
                <td class="tb-v">{{ $fmtMoney($finalRatePerNight) }} × {{ $totalNights }}</td>
            </tr>

            <tr class="tb-line">
                <th colspan="2"></th>
            </tr>

            <tr class="tb-strong">
                <td class="tb-k">GRAND TOTAL</td>
                <td class="tb-v">{{ $fmtMoney($total) }}</td>
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
