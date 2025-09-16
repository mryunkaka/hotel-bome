<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <style>
        @page {
            margin: 12mm;
        }

        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 11px;
            color: #111;
        }

        .hdr {
            margin-bottom: 8px;
        }

        .title {
            font-weight: 700;
            font-size: 16px;
            margin-bottom: 4px;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        .totals-box {
            width: 100%;
            border: 1px solid #ddd;
        }

        .totals-box td {
            padding: 6px 8px;
            border-bottom: 1px solid #eee;
        }

        .tb-k {
            width: 65%;
        }

        .tb-v {
            width: 35%;
            text-align: right;
        }

        .muted {
            color: #666;
        }
    </style>
</head>

<body>
    <div class="hdr">
        <div class="title">MASTER GUEST BILL</div>
        <div>
            Guest: <strong>{{ $rg->guest?->name }}</strong> &nbsp; • &nbsp;
            Room: <strong>{{ $rg->room?->room_no }}</strong> &nbsp; • &nbsp;
            Nights: <strong>{{ $nights }}</strong>
        </div>
        <div class="muted">
            Arrival:
            {{ \Illuminate\Support\Carbon::parse($rg->actual_checkin ?? $rg->expected_checkin)->format('d/m/Y H:i') }}
            &nbsp; • &nbsp;
            Departure:
            {{ \Illuminate\Support\Carbon::parse($rg->actual_checkout ?? $rg->expected_checkout)->format('d/m/Y H:i') }}
        </div>
    </div>

    <table class="totals-box">
        <tr>
            <td class="tb-k">Rate After Discount × Nights</td>
            <td class="tb-v">Rp {{ number_format($rate_after_disc, 0, ',', '.') }}</td>
        </tr>
        <tr>
            <td class="tb-k">Service (Rp)</td>
            <td class="tb-v">Rp {{ number_format($service, 0, ',', '.') }}</td>
        </tr>
        <tr>
            <td class="tb-k">Extra Bed</td>
            <td class="tb-v">Rp {{ number_format($extra_bed, 0, ',', '.') }}</td>
        </tr>
        @if ($late_penalty > 0)
            <tr>
                <td class="tb-k">Late Arrival Penalty</td>
                <td class="tb-v">Rp {{ number_format($late_penalty, 0, ',', '.') }}</td>
            </tr>
        @endif
        <tr>
            <td class="tb-k">Tax {{ number_format($tax_percent, 2, ',', '.') }}%</td>
            <td class="tb-v">Rp {{ number_format($tax_rp, 0, ',', '.') }}</td>
        </tr>
        <tr>
            <td class="tb-k"><strong>RATE ++</strong></td>
            <td class="tb-v"><strong>Rp {{ number_format($grand_total, 0, ',', '.') }}</strong></td>
        </tr>
        @if ($deposit > 0)
            <tr>
                <td class="tb-k">(-) Deposit</td>
                <td class="tb-v">Rp {{ number_format($deposit, 0, ',', '.') }}</td>
            </tr>
            <tr>
                <td class="tb-k"><strong>Amount Due</strong></td>
                <td class="tb-v"><strong>Rp {{ number_format(max(0, $grand_total - $deposit), 0, ',', '.') }}</strong>
                </td>
            </tr>
        @endif
    </table>
</body>

</html>
