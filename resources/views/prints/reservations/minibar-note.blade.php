{{-- resources/views/prints/reservations/minibar-note.blade.php --}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    @php
        use App\Support\ReservationMath as RM;
        use Illuminate\Support\Carbon;

        /** EXPECTED INPUTS:
         *  - $reservation  : App\Models\Reservation (opsional, hanya untuk header hotel/nomor)
         *  - $guest        : App\Models\ReservationGuest (WAJIB)
         *  - $hotel        : App\Models\Hotel (opsional; jika tidak ada, ambil dari $reservation->hotel)
         *  - $logoData     : base64 logo (opsional)
         *  - $paper        : 'A4'|'A5'|'80mm' (opsional, default A5)
         *  - $orientation  : 'portrait'|'landscape' (opsional, default portrait)
         *  - $payment      : App\Models\Payment|null (opsional; jika dikirim → render blok Payment)
         *  - $title        : judul dokumen (opsional, default 'MINIBAR NOTE')
         *  - $generatedAt  : Carbon|string (opsional, default now())
         */

        $m = fn($v) => 'Rp ' . number_format((float)$v, 0, ',', '.');
        $d = fn($v) => $v ? Carbon::parse($v)->format('d/m/Y H:i') : '-';

        $paper = strtoupper($paper ?? 'A5');
        $orientation = in_array(strtolower($orientation ?? 'portrait'), ['portrait','landscape'], true)
            ? strtolower($orientation) : 'portrait';

        $hotel = $hotel ?? ($reservation->hotel ?? null);
        $hotelRight = array_filter([
            $hotel?->name,
            $hotel?->address,
            trim(($hotel?->city ? $hotel->city.' ' : '').($hotel?->postcode ?? '')),
            $hotel?->phone ? 'Phone : '.$hotel->phone : null,
            $hotel?->email ?: null,
        ]);

        $rg  = $guest;                      // singkat
        $tz  = 'Asia/Makassar';
        $in  = $rg->actual_checkin ?: $rg->expected_checkin;
        $out = $rg->actual_checkout ?: Carbon::now($tz);

        // ===== angka minibar (SEMUA via ReservationMath) =====
        // unpaid subtotal minibar (hanya yang belum dibayar)
        // func ini kita buat sebelumnya sebagai helper internal di ReservationMath
        // kalau kamu menamainya berbeda, ganti di sini:
        $unpaidSub = method_exists(RM::class, 'unpaidMinibarSubtotal')
            ? (int) RM::unpaidMinibarSubtotal($rg)
            : 0;

        $svcPct    = (float) RM::servicePercent($rg);
        $svcRp     = (int) round(($unpaidSub * $svcPct) / 100);

        $taxPct    = (float) RM::taxPercent($rg);
        $taxBase   = $unpaidSub + $svcRp;
        $taxRp     = (int) round(($taxBase * $taxPct) / 100);

        $dueMinibar = $unpaidSub + $svcRp + $taxRp;  // total yang harus dibayar untuk minibar (unpaid only)

        // Status paid flag (kalau tidak ada due lagi)
        $isPaid = $dueMinibar <= 0;

        $title = $title ?? 'MINIBAR NOTE';
        $invoiceNo = $reservation->reservation_no ?? ('#'.$reservation->id ?? '');
    @endphp

    <title>{{ $title }} — {{ $invoiceNo }}</title>
    <style>
        @page { size: {{ $paper }} {{ $orientation }}; margin: 10mm; }
        body { margin:0; padding:0; font-family: DejaVu Sans, Arial, sans-serif; color:#111827; font-size:9px; line-height:1.3; }

        /* Header */
        table.hdr{width:100%;border-collapse:collapse;margin-bottom:8px}
        .hdr td{vertical-align:top}
        .left{width:35%}.mid{width:30%;text-align:center}.right{width:35%;text-align:right}
        .logo img{height:38px;object-fit:contain}
        .title{font-size:12px;font-weight:700}
        .sub{font-size:10px;color:#374151}
        .hotel-meta{font-size:8px;line-height:1.25}
        .pill{display:inline-block;padding:2px 6px;border-radius:999px;border:1px solid #A7F3D0;background:#ECFDF5;color:#065F46;font-weight:700;font-size:8px}

        /* Info dua kolom */
        table.info{width:100%;border-collapse:collapse;margin:6px 0 8px}
        .info td{padding:2px 3px;vertical-align:top}
        .lbl{color:#374151;font-weight:600;white-space:nowrap;width:26%}
        .sep{width:8px;text-align:center}
        .val{width:auto}

        .line{border-top:1px solid #111827;margin:8px 0}

        /* Ringkasan minibar (2 kolom) */
        table.sum{width:100%;border-collapse:separate;border-spacing:0 6px;margin-top:4px}
        .card{border:1px solid #E5E7EB;border-radius:6px;padding:8px}
        .k{color:#374151}
        .v{text-align:right;font-weight:700}

        /* Tabel perhitungan (beda struktur dari bill utama) */
        table.calc{width:100%;border-collapse:collapse;margin-top:6px}
        .calc th,.calc td{padding:6px 6px;border-bottom:1px dashed #D1D5DB}
        .calc th{text-align:left;color:#374151;font-size:9px}
        .right{text-align:right}
        .muted{color:#6B7280}

        /* Footer bottoms */
        table.foot{width:100%;border-collapse:collapse;margin-top:10px;font-size:8.5px}
        .foot td{padding:2px 0}
    </style>
</head>
<body>
    {{-- HEADER --}}
    <table class="hdr">
        <tr>
            <td class="left">
                @if (!empty($logoData))
                    <span class="logo"><img src="{{ $logoData }}" alt="Logo"></span>
                @endif
            </td>
            <td class="mid">
                <div class="title">{{ $title }}</div>
                <div class="sub">{{ $invoiceNo }}</div>
            </td>
            <td class="right">
                <div class="hotel-meta">
                    {!! !empty($hotelRight) ? implode('<br>', array_map('e', $hotelRight)) : '&nbsp;' !!}
                </div>
                @if($isPaid)
                    <div style="margin-top:6px"><span class="pill">PAID</span></div>
                @endif
            </td>
        </tr>
    </table>

    {{-- INFO TAMU / KAMAR --}}
    <table class="info">
        <tr>
            <td class="lbl">Guest</td><td class="sep">:</td>
            <td class="val">{{ $rg->guest?->name ?? '-' }}</td>
            <td></td>
            <td class="lbl">Room</td><td class="sep">:</td>
            <td class="val">{{ $rg->room?->room_no ?? '-' }} {{ $rg->room?->type ? '• '.$rg->room->type : '' }}</td>
        </tr>
        <tr>
            <td class="lbl">Check-in</td><td class="sep">:</td>
            <td class="val">{{ $d($in) }}</td>
            <td></td>
            <td class="lbl">Check-out</td><td class="sep">:</td>
            <td class="val">{{ $d($out) }}</td>
        </tr>
    </table>

    <div class="line"></div>

    {{-- RINGKASAN MINIBAR (2 kolom kotak) --}}
    <table class="sum">
        <tr>
            <td style="width:50%">
                <div class="card">
                    <div class="k">Unpaid Minibar Subtotal</div>
                    <div class="v">{{ $m($unpaidSub) }}</div>
                    <div class="muted" style="margin-top:4px">Hanya yang belum dibayar</div>
                </div>
            </td>
            <td style="width:50%">
                <div class="card">
                    <div class="k">Total Minibar Due</div>
                    <div class="v">{{ $m($dueMinibar) }}</div>
                    <div class="muted" style="margin-top:4px">
                        Termasuk service {{ rtrim(rtrim(number_format($svcPct,2,',','.'), '0'), ',') }}% dan pajak {{ rtrim(rtrim(number_format($taxPct,2,',','.'), '0'), ',') }}%
                    </div>
                </div>
            </td>
        </tr>
    </table>

    {{-- PERINCIAN PERHITUNGAN (tata letak berbeda dari bill) --}}
    <table class="calc">
        <thead>
            <tr>
                <th>Component</th>
                <th class="right">Amount (IDR)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Minibar (Unpaid)</td>
                <td class="right">{{ $m($unpaidSub) }}</td>
            </tr>
            <tr>
                <td>Service ({{ rtrim(rtrim(number_format($svcPct,2,',','.'), '0'), ',') }}%)</td>
                <td class="right">{{ $m($svcRp) }}</td>
            </tr>
            <tr>
                <td>Tax ({{ rtrim(rtrim(number_format($taxPct,2,',','.'), '0'), ',') }}%)</td>
                <td class="right">{{ $m($taxRp) }}</td>
            </tr>
            <tr>
                <td><strong>TOTAL DUE</strong></td>
                <td class="right"><strong>{{ $m($dueMinibar) }}</strong></td>
            </tr>
        </tbody>
    </table>

    {{-- OPSIONAL: BLOK PAYMENT (ditampilkan kalau $payment dikirim) --}}
    @isset($payment)
        @php
            $payAmt   = (int) ($payment->amount ?? 0);
            $payMeth  = strtoupper((string) ($payment->method ?? 'CASH'));
            $payNote  = (string) ($payment->notes ?? '');
            $change   = max(0, $payAmt - $dueMinibar);
            $balance  = max(0, $dueMinibar - $payAmt);
        @endphp

        <div class="line"></div>
        <table class="calc">
            <thead>
            <tr><th colspan="2">Payment</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td>Method</td>
                    <td class="right">{{ $payMeth }}</td>
                </tr>
                <tr>
                    <td>Amount Paid</td>
                    <td class="right">{{ $m($payAmt) }}</td>
                </tr>
                @if($change > 0)
                    <tr>
                        <td><strong>CHANGE</strong></td>
                        <td class="right"><strong>{{ $m($change) }}</strong></td>
                    </tr>
                @else
                    <tr>
                        <td><strong>BALANCE DUE</strong></td>
                        <td class="right"><strong>{{ $m($balance) }}</strong></td>
                    </tr>
                @endif
                @if($payNote !== '')
                    <tr>
                        <td>Note</td>
                        <td class="right">{{ $payNote }}</td>
                    </tr>
                @endif
            </tbody>
        </table>
    @endisset

    <div class="line"></div>

    {{-- FOOT --}}
    <table class="foot">
        <tr>
            <td>Doc: Minibar</td>
            <td style="text-align:center">
                {{ ($hotel?->city ? $hotel->city.', ' : '') . $d($generatedAt ?? now()) }}
            </td>
            <td style="text-align:right">{{ $clerkName ?? 'Reception' }}</td>
        </tr>
    </table>
</body>
</html>
