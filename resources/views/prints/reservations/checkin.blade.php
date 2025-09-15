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
        // format ringkas supaya tidak terpotong
        $fmtDateShort = fn($v) => $v ? Carbon::parse($v)->format('d/m H:i') : '-';
        $fmtDateFull = fn($v) => $v ? Carbon::parse($v)->format('d/m/Y H:i') : '-';

        $hotelRight = array_filter([$hotel?->name, $hotel?->address, $hotel?->city, $hotel?->phone, $hotel?->email]);

        // ====== PERHITUNGAN RATE (PAKAI HELPER) ======
        $baseRate = (float) ($row['rate'] ?? 0);
        $discPct = (float) ($row['discount_percent'] ?? 0);
        $taxPct = (float) ($row['tax_percent'] ?? 0);
        $serviceRp = (float) ($row['service'] ?? 0);
        $extraBedRp = (float) ($row['extra_bed_total'] ?? 0);
        $lateRp = (float) ($row['late_arrival_penalty'] ?? 0);

        // ------ Ambil kandidat tanggal untuk hitung nights ------
        $inForNights = $row['actual_in'] ?? ($row['expected_in'] ?? ($row['expected_checkin'] ?? null));
        $outForNights = $row['actual_out'] ?? ($row['expected_out'] ?? ($row['expected_checkout'] ?? null));

        // Nights dari data bila disuplai (fallback = 1)
        $nights = (int) max(1, (int) ($row['nights'] ?? 1));

        // Jika punya tanggal in & out yang valid, override nights dengan selisih hari kalender
        if ($inForNights && $outForNights) {
            try {
                // Gunakan startOfDay agar stabil (kamu pakai jam 12:00; selisih hari tetap benar)
                $nightsDiff = Carbon::parse($inForNights)
                    ->startOfDay()
                    ->diffInDays(Carbon::parse($outForNights)->startOfDay());
                // Minimal 1 malam
                $nights = max(1, (int) $nightsDiff);
            } catch (\Throwable $e) {
                // biarkan nights hasil sebelumnya
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

        // Amount per baris = final rate per night × nights (sesuai permintaan)
        $amount = $finalRatePerNight * $nights;

        // Karena template ini 1 baris item, total sama dengan amount; tetap siapkan variabel agar mudah multi-baris
        $totalNights = $nights;
        $subtotal = $amount;
        $tax_total = 0;
        $total = $subtotal; // jika nanti ada biaya lain, tinggal tambahkan

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
            margin: 12mm;
        }

        body {
            margin: 0;
            padding: 0;
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 10px;
            color: #111827;
            line-height: 1.35;
        }

        .hdr-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }

        .hdr-td {
            vertical-align: top;
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
        }

        .resv-no {
            margin-top: 4px;
            font-weight: 600;
            letter-spacing: 0.2px;
        }

        .logo {
            display: inline-block;
            vertical-align: middle;
            margin-right: 6px;
        }

        .logo img {
            width: 80px;
            object-fit: contain;
        }

        .hotel-meta {
            color: #111827;
            font-size: 9px;
            line-height: 1.35;
        }

        .kv {
            width: 100%;
            border-collapse: collapse;
            margin: 8px 0;
            table-layout: fixed;
        }

        .kv td {
            padding: 2px 0;
            vertical-align: top;
        }

        .k {
            width: 130px;
            color: #374151;
            white-space: nowrap;
        }

        .v {
            color: #111827;
            font-weight: 600;
            white-space: nowrap;
        }

        .line {
            border-top: 1.5px solid #1F2937;
            margin: 10px 0;
        }

        table.items {
            width: 100%;
            border-collapse: collapse;
            font-size: 9px;
            margin-top: 6px;
            table-layout: fixed;
        }

        .items thead th {
            border-top: 1.5px solid #1F2937;
            border-bottom: 1px solid #1F2937;
            padding: 6px;
            text-align: left;
            white-space: nowrap;
        }

        .items td {
            border-bottom: 1px solid #D1D5DB;
            padding: 6px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .items tfoot td {
            border-top: 1.5px solid #1F2937;
            font-weight: 700;
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
            width: 44px;
        }

        .col-cat {
            width: auto;
        }

        .col-pax {
            width: 36px;
            text-align: center;
        }

        .col-rate {
            width: 88px;
            text-align: right;
        }

        .col-night {
            width: 40px;
            text-align: center;
        }

        .col-in {
            width: 96px;
            text-align: center;
        }

        .col-out {
            width: 108px;
            text-align: center;
        }

        .col-amount {
            width: 100px;
            text-align: right;
        }

        .totals-box-wrap {
            width: 100%;
            margin-top: 12px;
        }

        .totals-box {
            margin-left: auto;
            width: 360px;
            border-collapse: collapse;
            table-layout: fixed;
            font-size: 10px;
        }

        .totals-box td {
            padding: 6px 6px;
            white-space: nowrap;
        }

        .tb-k {
            width: 200px;
            color: #374151;
        }

        .tb-v {
            width: 160px;
            text-align: right;
            font-weight: 600;
        }

        .tb-line th {
            border-top: 1.5px solid #1F2937;
            padding-top: 8px;
        }

        .tb-strong .tb-k,
        .tb-strong .tb-v {
            font-weight: 700;
        }

        .footer {
            margin-top: 14px;
        }

        .foot-table {
            width: 100%;
            border-collapse: collapse;
        }

        .foot-left {
            text-align: left;
        }

        .foot-mid {
            text-align: center;
        }

        .foot-right {
            text-align: right;
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
                <div class="resv-no">RESV NO: {{ $invoiceNo ?? '#' . ($invoiceId ?? '-') }}</div>
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
        <tr>
            <td class="k">Status</td>
            <td class="v">{{ strtoupper($status ?? 'CONFIRM') }}</td>
            <td class="k">Method</td>
            <td class="v">{{ ucfirst($payment['method'] ?? 'personal') }}</td>
        </tr>
        <tr>
            <td class="k">Reserved By</td>
            <td class="v">{{ $companyName ?: '-' }}</td>
            <td class="k">Entry Date</td>
            <td class="v">{{ $fmtDateFull($issuedAt ?? ($generatedAt ?? now())) }}</td>
        </tr>
        <tr>
            <td class="k">Guest</td>
            <td class="v">{{ $row['guest_display'] ?? '-' }}</td>
            <td class="k">Clerk</td>
            <td class="v">{{ $clerkName ?? '-' }}</td>
        </tr>
    </table>

    <div class="line"></div>

    {{-- ===== ITEM (baris RG) ===== --}}
    <table class="items">
        <thead>
            <tr>
                <th class="col-room">ROOM</th>
                <th class="col-cat">CATEGORY</th>
                <th class="col-pax center">PAX</th>
                <th class="col-rate right">RATE</th>
                <th class="col-night center">NIGHTS</th>
                <th class="col-in center">CHECK-IN</th>
                <th class="col-out center">CHECK-OUT</th>
                <th class="col-amount right">AMOUNT</th>
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

    {{-- ===== TOTALS BOX (gabung breakdown + totals) ===== --}}
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
                <td class="tb-k">Service (Rp)</td>
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
                <td class="tb-v">{{ $fmtMoney($finalRatePerNight) }} × {{ $totalNights }} =
                    {{ $fmtMoney($total) }}</td>
            </tr>

            <tr class="tb-line">
                <th colspan="2"></th>
            </tr>

            <tr class="tb-strong">
                <td class="tb-k">Grand Total</td>
                <td class="tb-v">{{ $fmtMoney($total) }}</td>
            </tr>
        </table>
    </div>

    <div class="line"></div>

    {{-- ===== FOOTER / TTD ===== --}}
    <div class="footer">
        <table class="foot-table">
            <tr>
                <td class="foot-left">Page : 1</td>
                <td class="foot-mid">
                    {{ $hotel?->city ? $hotel->city . ' , ' : '' }}
                    {{ $fmtDateFull($generatedAt ?? now()) }} - Reception/Cashier
                </td>
                <td class="foot-right">&nbsp;</td>
            </tr>
            <tr>
                <td class="foot-left"></td>
                <td class="foot-mid">
                    <div style="display:inline-block; min-width:160px; text-align:center; margin-top:26px;">
                        <div style="margin-top:38px; border-top:1px solid #9CA3AF;">&nbsp;</div>
                        {{ $clerkName ?? ($reserved_by ?? ' ') }}
                    </div>
                </td>
                <td class="foot-right"></td>
            </tr>
        </table>
    </div>
</body>

</html>
