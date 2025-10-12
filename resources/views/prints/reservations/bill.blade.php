<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    @php
        use Illuminate\Support\Carbon;

        $m  = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
        $d  = fn($v) => $v ? Carbon::parse($v)->format('d/m/Y H:i') : '-';
        $ds = fn($v) => $v ? Carbon::parse($v)->format('d/m H:i') : '-';

        // Paper & orientasi
        $paper = strtoupper($paper ?? 'A4');
        $orientation = in_array(strtolower($orientation ?? 'portrait'), ['portrait','landscape'], true)
            ? strtolower($orientation) : 'portrait';

        // Hotel (kanan header)
        $hotelRight = array_filter([
            $hotel?->name,
            $hotel?->address,
            trim(($hotel?->city ? $hotel->city.' ' : '').($hotel?->postcode ?? '')),
            $hotel?->phone ? 'Phone : '.$hotel->phone : null,
            $hotel?->email ?: null,
        ]);

        // Reservation & Reserved By
        $resv    = $reservation ?? null;
        $rbType  = strtoupper($resv?->reserved_by_type ?? 'GUEST');
        $isGroup = $rbType === 'GROUP' && $resv?->group;

        $rbObj   = $isGroup ? $resv?->group : ($resv?->guest ?? null);
        $rbName  = $rbObj?->name  ?? '-';
        $rbAddr  = $rbObj?->address ?? '-';
        $rbPhone = $rbObj?->phone ?? ($rbObj?->handphone ?? '-');
        $rbEmail = $rbObj?->email ?? '-';

        // ======= TOTAL DEPOSIT (header + per-RG) =======
        $depositCardHeader = (int) ($resv?->deposit_card ?? $resv?->deposit ?? 0);
        $depositRoomHeader = (int) ($resv?->deposit_room ?? 0);

        // Jika controller sudah kirim daftar RG lengkap untuk header deposit per-RG,
        // silakan sesuaikan agregat ini di controller dan pass langsung.
        $depCardFromRg = (int) ($depCardFromRg ?? 0);
        $depRoomFromRg = (int) ($depRoomFromRg ?? 0);

        $depositCardTotal = $depositCardHeader + $depCardFromRg;
        $depositRoomTotal = $depositRoomHeader + $depRoomFromRg;
        $depositGrand     = $depositCardTotal + $depositRoomTotal;
    @endphp

    <title>{{ $title ?? 'GUEST BILL' }} — {{ $invoiceNo ?? '#' . ($invoiceId ?? '-') }}</title>
    <style>
        @page { size: {{ $paper }} {{ $orientation }}; margin: 10mm; }
        body { margin:0; padding:0; font-family: DejaVu Sans, Arial, sans-serif; color:#111827; font-size:8.4px; line-height:1.25; }

        table.hdr{width:100%;border-collapse:collapse;margin-bottom:6px}
        .hdr td{vertical-align:top}
        .left{width:35%}.mid{width:30%;text-align:center}.right{width:35%;text-align:right}
        .logo img{height:40px;object-fit:contain}
        .title{font-size:13px;font-weight:700;text-decoration:underline}
        .sub{font-weight:600;margin-top:2px}
        .hotel-meta{font-size:7.6px;line-height:1.25}

        table.info { width:100%; border-collapse:collapse; margin:4px 0 2px }
        table.info td { padding:1px 2px; vertical-align:top; word-wrap:break-word }
        table.info .lbl { color:#374151; font-weight:600; width:20%; }
        table.info .sep { width:8px; text-align:center; }
        table.info .gap { width:15px; }
        table.info .val { width:auto; padding-right:4px; }

        .line{border-top:1px solid #1F2937;margin:6px 0}

        table.grid{width:100%;border-collapse:collapse;table-layout:fixed;font-size:7.6px}
        .grid thead th{border-top:1px solid #1F2937;border-bottom:1px solid #1F2937;padding:3px 3px;font-weight:700;text-align:left;white-space:nowrap}
        .grid td{border-bottom:1px solid #E5E7EB;padding:3px 3px;vertical-align:top}
        .center{text-align:center} .right{text-align:right}
        .clip{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .muted{color:#6b7280}
        .tiny{font-size:7px}

        .col-guest  { width: 18%; }
        .col-pax    { width: 3%;  text-align: center; }
        .col-night  { width: 3%;  text-align: center; }
        .col-dt,
        .col-dt2    { width: 5%;  text-align: center; }
        .col-status { width: 4%;  text-align: center; }
        .col-amts   { width: 6%; }

        .grid td:nth-child(n+7):nth-child(-n+11) {
            text-align: right !important;
            white-space: nowrap;
        }

        table.total{width:100%;border-collapse:collapse;margin-top:6px;font-size:8px}
        .total td{padding:3px 4px}
        .k{color:#374151}
        .v{text-align:right;font-weight:700}
    </style>
</head>
<body>

    {{-- ===== HEADER ===== --}}
    <table class="hdr">
        <tr>
            <td class="left">
                @if (!empty($logoData)) <span class="logo"><img src="{{ $logoData }}" alt="Logo"></span> @endif
            </td>
            <td class="mid">
                <div class="title">{{ $title ?? 'GUEST BILL' }}</div>
                <div class="sub">{{ $invoiceNo ?? '#' . ($invoiceId ?? '-') }}</div>
            </td>
            <td class="right">
                <div class="hotel-meta">{!! !empty($hotelRight) ? implode('<br>', array_map('e', $hotelRight)) : '&nbsp;' !!}</div>
            </td>
        </tr>
    </table>

    <table class="info">
        <tr>
            <td class="lbl">{{ $isGroup ? 'Company' : 'Reserved By' }}</td>
            <td class="sep">:</td>
            <td class="val">{{ $rbName }}</td>
            <td class="gap"></td>
            <td class="lbl">Deposit Card</td>
            <td class="sep">:</td>
            <td class="val">{{ $m($depositCardTotal) }}</td>
        </tr>
        <tr>
            <td class="lbl">Address</td>
            <td class="sep">:</td>
            <td class="val">{{ $rbAddr }}</td>
            <td class="gap"></td>
            <td class="lbl">Deposit Room</td>
            <td class="sep">:</td>
            <td class="val">{{ $m($depositRoomTotal) }}</td>
        </tr>
        <tr>
            <td class="lbl">Phone / Email</td>
            <td class="sep">:</td>
            <td class="val">{{ $rbPhone }}{{ $rbEmail && $rbEmail !== '-' ? ' • '.$rbEmail : '' }}</td>
            <td class="gap"></td>
            <td class="lbl"><strong>Deposit Total</strong></td>
            <td class="sep">:</td>
            <td class="val"><strong>{{ $m($depositGrand) }}</strong></td>
        </tr>
    </table>

    <div class="line"></div>

    {{-- ===== TABEL RINCIAN (1 baris = 1 guest) ===== --}}
    <table class="grid">
        <thead>
            <tr>
                <th class="col-guest">Guest</th>
                <th class="col-pax">Pax</th>
                <th class="col-night">N</th>
                <th class="col-dt">C-I</th>
                <th class="col-dt2">C-O</th>
                <th class="col-status">Status</th>
                <th class="col-amts">R × N</th>
                <th class="col-amts">Charge</th>
                <th class="col-amts">Minibar</th>
                <th class="col-amts">Extra</th>
                <th class="col-amts">Penalty</th>
                <th class="col-amts">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $r)
                <tr>
                    <td class="clip">
                        <div class="clip">{{ $r['guest_name'] }}</div>
                        <div class="muted tiny clip">
                            #{{ $r['room_no'] }} — {{ $r['room_type'] }} — Rate: {{ $m($r['rate']) }}
                        </div>
                    </td>
                    <td class="center">{{ $r['pax'] ?? '' }}</td>
                    <td class="center">{{ $r['nights'] }}</td>
                    <td class="center">{{ $ds($r['checkin']) }}</td>
                    <td class="center">{{ $ds($r['checkout']) }}</td>
                    <td class="center">{{ $r['status'] }}</td>
                    <td class="center">{{ $m($r['rate_after_times_nights']) }}</td>
                    <td class="center">{{ $m($r['charge']) }}</td>
                    <td class="center">{{ $m($r['service']) }}</td> {{-- service = subtotal minibar --}}
                    <td class="center">{{ $m($r['extra']) }}</td>
                    <td class="center">{{ $m($r['penalty']) }}</td>
                    <td class="center"><strong>{{ $m($r['amount']) }}</strong></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @php
        // Ambil agregat dari ReservationMath::aggregateGuestInfoFooter($rg)
        $baseGross         = (int) ($agg['sum_base'] ?? 0);              // Σ base (sebelum pajak & deposit)
        $depRoomTotal      = (int) ($agg['sum_dep_room'] ?? 0);          // Σ deposit_room
        $depCardTotal      = (int) ($agg['sum_dep_card'] ?? 0);          // Σ deposit_card
        $baseAfterDeposits = (int) ($agg['sum_base_after_deposits'] ?? max(0, $baseGross - ($depRoomTotal + $depCardTotal)));
        $taxTotal          = (int) ($agg['sum_tax'] ?? 0);               // pajak tidak terpengaruh deposit
        $grandTotal        = (int) ($agg['total_due_all'] ?? ($baseAfterDeposits + $taxTotal));
    @endphp

    <table class="total">
        <tr>
            <td class="k" style="text-align:right">Base (before deposits)</td>
            <td class="v">{{ $m($baseGross) }}</td>
        </tr>
        <tr>
            <td class="k" style="text-align:right">(-) Deposit Room</td>
            <td class="v">- {{ $m($depRoomTotal) }}</td>
        </tr>
        <tr>
            <td class="k" style="text-align:right">(-) Deposit Card</td>
            <td class="v">- {{ $m($depCardTotal) }}</td>
        </tr>
        <tr>
            <td class="k" style="text-align:right"><strong>Subtotal (before tax)</strong></td>
            <td class="v"><strong>{{ $m($baseAfterDeposits) }}</strong></td>
        </tr>
        <tr>
            <td class="k" style="text-align:right">Tax</td>
            <td class="v">{{ $m($taxTotal) }}</td>
        </tr>
        <tr>
            <td class="k" style="text-align:right"><strong>TOTAL (Amount Due + Tax)</strong></td>
            <td class="v"><strong>{{ $m($grandTotal) }}</strong></td>
        </tr>
    </table>

    <div class="line"></div>

    <table style="width:100%;border-collapse:collapse;font-size:8px">
        <tr>
            <td>Page: 1</td>
            <td style="text-align:center">{{ $hotel?->city ? $hotel->city . ', ' : '' }}{{ $d($generatedAt ?? now()) }}</td>
            <td style="text-align:right">{{ $clerkName ?? 'Reception' }}</td>
        </tr>
    </table>
</body>
</html>
