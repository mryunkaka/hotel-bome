<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    @php
        use Illuminate\Support\Carbon;

        $paper = strtoupper($paper ?? 'A4');
        $orientation = in_array(strtolower($orientation ?? 'portrait'), ['portrait', 'landscape'], true)
            ? strtolower($orientation)
            : 'portrait';

        $fmtMoney = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
        $fmtDate = fn($v, $withTime = true) => $v ? Carbon::parse($v)->format($withTime ? 'd/m/Y H:i' : 'd/m/Y') : '-';

        $hotelRight = array_filter([$hotel?->name, $hotel?->address, $hotel?->city, $hotel?->phone, $hotel?->email]);
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
        }

        .kv td {
            padding: 2px 0;
            vertical-align: top;
        }

        .k {
            width: 130px;
            color: #374151;
        }

        .v {
            color: #111827;
            font-weight: 600;
        }

        .line {
            border-top: 1.5px solid #1F2937;
            margin: 10px 0;
        }

        table.items {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
            margin-top: 6px;
        }

        .items thead th {
            border-top: 1.5px solid #1F2937;
            border-bottom: 1px solid #1F2937;
            padding: 6px;
            text-align: left;
        }

        .items td {
            border-bottom: 1px solid #D1D5DB;
            padding: 6px;
        }

        .center {
            text-align: center;
        }

        .right {
            text-align: right;
        }

        .narrow {
            width: 48px;
            white-space: nowrap;
        }

        .totals {
            width: 100%;
            margin-top: 10px;
        }

        .totals td {
            padding: 4px 0;
        }

        .totals .k {
            text-align: right;
            padding-right: 8px;
        }

        .totals .v {
            text-align: right;
            min-width: 140px;
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

        @media print {
            .actions {
                display: none;
            }
        }
    </style>
</head>

<body>
    <div class="actions" style="margin:8px 0;">
        <button onclick="window.print()">Print</button>
    </div>

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

    {{-- ===== INFO ATAS (2 kolom) ===== --}}
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
            <td class="v">{{ $fmtDate($issuedAt ?? ($generatedAt ?? now()), false) }}</td>
        </tr>
        <tr>
            <td class="k">Guest</td>
            <td class="v">{{ $row['guest_display'] ?? '-' }}</td>
            <td class="k">Clerk</td>
            <td class="v">{{ $clerkName ?? '-' }}</td>
        </tr>
    </table>

    <div class="line"></div>

    {{-- ===== ITEM (baris RG ini) ===== --}}
    <table class="items">
        <thead>
            <tr>
                <th class="narrow">ROOM</th>
                <th>CATEGORY</th>
                <th class="center narrow">PAX</th>
                <th class="right narrow">RATE</th>
                <th class="center narrow">NIGHTS</th>
                <th class="center narrow">ACT CHECK-IN</th>
                <th class="center narrow">ACT/EXP CHECK-OUT</th>
                <th class="right narrow">AMOUNT</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $row['room_no'] ?? '-' }}</td>
                <td>{{ $row['category'] ?? '-' }}</td>
                <td class="center">{{ (int) ($row['ps'] ?? 1) }}</td>
                <td class="right">{{ $fmtMoney($row['rate'] ?? 0) }}</td>
                <td class="center">{{ (int) ($row['nights'] ?? 1) }}</td>
                <td class="center">{{ $fmtDate($row['actual_in'] ?? null, true) }}</td>
                <td class="center">
                    @if (!empty($row['actual_out']))
                        {{ $fmtDate($row['actual_out'], true) }}
                    @else
                        {{ $fmtDate($row['expected_out'] ?? null, true) }}
                    @endif
                </td>
                <td class="right">{{ $fmtMoney(($row['rate'] ?? 0) * ($row['nights'] ?? 1)) }}</td>
            </tr>
        </tbody>
    </table>

    {{-- ===== TOTALS ===== --}}
    <table class="totals">
        <tr>
            <td class="k">Subtotal</td>
            <td class="v">{{ $fmtMoney($subtotal ?? 0) }}</td>
        </tr>
        <tr>
            <td class="k">Tax</td>
            <td class="v">{{ $fmtMoney($tax_total ?? 0) }}</td>
        </tr>
        <tr>
            <td class="k"><strong>Total</strong></td>
            <td class="v"><strong>{{ $fmtMoney($total ?? 0) }}</strong></td>
        </tr>
    </table>

    <div class="line"></div>

    {{-- ===== FOOTER / TTD ===== --}}
    <div class="footer">
        <table class="foot-table">
            <tr>
                <td class="foot-left">Page : 1</td>
                <td class="foot-mid">
                    {{ $hotel?->city ? $hotel->city . ' , ' : '' }}
                    {{ $fmtDate($generatedAt ?? now(), false) }} - Reception/Cashier
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
