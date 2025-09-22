{{-- resources/views/prints/reservations/bill.blade.php --}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    @php
        use Illuminate\Support\Carbon;
        use App\Support\ReservationMath;

        $m = fn($v) => 'Rp ' . number_format((float)$v, 0, ',', '.');
        $d = fn($v) => $v ? Carbon::parse($v)->format('d/m/Y H:i') : '-';
        $ds = fn($v) => $v ? Carbon::parse($v)->format('d/m H:i') : '-'; // ⬅️ pendek

        $paper = strtoupper($paper ?? 'A4');
        $orientation = in_array(strtolower($orientation ?? 'portrait'), ['portrait','landscape'], true)
            ? strtolower($orientation) : 'portrait';

        $hotelRight = array_filter([
            $hotel?->name, $hotel?->address,
            trim(($hotel?->city ? $hotel->city.' ' : '').($hotel?->postcode ?? '')),
            $hotel?->phone ? 'Phone : '.$hotel->phone : null,
            $hotel?->email ?: null,
        ]);

        // ===== Reserved By (Guest / Company) + semua guest
        $resv    = $reservation ?? null;
        $rbType  = strtoupper($resv?->reserved_by_type ?? 'GUEST');
        $isGroup = $rbType === 'GROUP' && $resv?->group;

        $rbObj   = $isGroup ? $resv?->group : ($resv?->guest ?? null);
        $rbName  = $rbObj?->name ?? '-';
        $rbAddr  = $rbObj?->address ?? '-';
        $rbCity  = $rbObj?->city ?? '-';
        $rbPhone = $rbObj?->phone ?? ($rbObj?->handphone ?? '-');
        $rbEmail = $rbObj?->email ?? '-';

        $guestsQ = $resv
            ? $resv->reservationGuests()->with(['guest:id,name', 'room:id,room_no,type,price', 'reservation.tax'])->orderBy('id')
            : null;
        $guests  = $guestsQ ? $guestsQ->get() : collect();
        $totalGuestsRegistered = $guests->count();

        // ===== Akumulasi footer
        $sumBase  = 0; // subtotal sebelum pajak
        $sumTax   = 0; // pajak
        $sumGrand = 0; // subtotal + pajak

        $taxPctReservation  = (float) ($resv?->tax?->percent ?? 0);
        $depositReservation = (int) ($resv?->deposit ?? 0);
        $tz = 'Asia/Makassar';
    @endphp
    <title>{{ $title ?? 'GUEST BILL' }} — {{ $invoiceNo ?? '#' . ($invoiceId ?? '-') }}</title>
    <style>
        @page { size: {{ $paper }} {{ $orientation }}; margin: 10mm; }
        body { margin:0; padding:0; font-family: DejaVu Sans, Arial, sans-serif; color:#111827; font-size:8.4px; line-height:1.25; }

        table.hdr{width:100%;border-collapse:collapse;margin-bottom:6px} .hdr td{vertical-align:top}
        .left{width:35%}.mid{width:30%;text-align:center}.right{width:35%;text-align:right}
        .logo img{height:40px;object-fit:contain}
        .title{font-size:13px;font-weight:700;text-decoration:underline}
        .sub{font-weight:600;margin-top:2px}
        .hotel-meta{font-size:7.6px;line-height:1.25}

        table.info{width:100%;border-collapse:collapse;table-layout:fixed;margin:4px 0 2px}
        table.info td{padding:1px 0;vertical-align:top}
        .lbl{color:#374151;font-weight:600;white-space:nowrap}
        .c{width:8px;text-align:center}
        .line{border-top:1px solid #1F2937;margin:6px 0}

        /* ====== TABEL RINCI PER GUEST ====== */
        table.grid{width:100%;border-collapse:collapse;table-layout:fixed;font-size:7.6px}
        .grid thead th{border-top:1px solid #1F2937;border-bottom:1px solid #1F2937;padding:3px 3px;font-weight:700;text-align:left;white-space:nowrap}
        .grid td{border-bottom:1px solid #E5E7EB;padding:3px 3px;vertical-align:top}
        .center{text-align:center} .right{text-align:right}
        .clip{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .muted{color:#6b7280}
        .tiny{font-size:7px}

        /* Lebar kolom (total ≈100%) */
        .col-guest{width:28%}
        .col-pax{width:7%;text-align:center}
        .col-night{width:11%;text-align:center}
        .col-dt{width:17%;text-align:center}          /* check-in */
        .col-dt2{width:17%;text-align:center}         /* check-out */
        .col-status{width:15%;text-align:center}
        .col-amt{width:7%;text-align:right}           /* 5 kolom amount × 7% = 35% */

        /* Baris total */
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

    {{-- ===== INFO RESERVED BY (tanpa detail 1 guest) ===== --}}
    <table class="info">
        <tr>
            <td class="lbl" style="width:30mm">{{ $isGroup ? 'Company' : 'Reserved By' }}</td><td class="c">:</td><td>{{ $rbName }}</td>
            <td style="width:8mm"></td>
            <td class="lbl">Guests Registered</td><td class="c">:</td><td>{{ $totalGuestsRegistered }}</td>
        </tr>
        <tr>
            <td class="lbl">Address</td><td class="c">:</td><td class="clip">{{ $rbAddr }}</td>
            <td></td>
            <td class="lbl">City</td><td class="c">:</td><td>{{ $rbCity }}</td>
        </tr>
        <tr>
            <td class="lbl">Phone</td><td class="c">:</td><td>{{ $rbPhone }}</td>
            <td></td>
            <td class="lbl">Email</td><td class="c">:</td><td class="clip">{{ $rbEmail }}</td>
        </tr>
    </table>

    <div class="line"></div>

    {{-- ===== TABEL RINCIAN SEMUA GUEST (1 baris = 1 guest) ===== --}}
    <table class="grid">
        <thead>
            <tr>
                <th class="col-guest">Guest</th>
                <th class="col-pax">Pax</th>
                <th class="col-night">Nights</th>
                <th class="col-dt">Check-in</th>
                <th class="col-dt2">Check-out</th>
                <th class="col-status">Status</th>
                <th class="col-amt">Room×Night</th>
                <th class="col-amt">Service</th>
                <th class="col-amt">Extra</th>
                <th class="col-amt">Penalty</th>
                <th class="col-amt">Amount</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($guests as $g)
            @php
                // Pax
                $paxVal = (int) (
                    $g->jumlah_orang
                    ?? ((int) ($g->male ?? 0) + (int) ($g->female ?? 0) + (int) ($g->children ?? 0))
                );

                // Nights
                $in  = $g->actual_checkin ?: $g->expected_checkin;
                $out = $g->actual_checkout ?: Carbon::now($tz);
                $n   = ReservationMath::nights($in, $out, 1);

                // Basic Rate
                $gRate = (float) ReservationMath::basicRate($g);

                // Discount & after
                $gDiscPct   = (float) ($g->discount_percent ?? 0);
                $gDiscAmt   = (int) round(($gRate * $gDiscPct) / 100);
                $gRateAfter = max(0, $gRate - $gDiscAmt);

                // Service & Extra
                $gServiceRp = (int) ($g->service ?? 0);
                $gExtraRp   = (int) ($g->extra_bed_total ?? ((int) ($g->extra_bed ?? 0) * 100_000));

                // Penalty
                $gPen = ReservationMath::latePenalty(
                    $g->expected_checkin ?: ($g->reservation?->expected_arrival),
                    $g->actual_checkin,
                    $gRate,
                    ['tz' => $tz],
                );
                $gPenaltyRp = (int) ($gPen['amount'] ?? 0);

                // Tax base & tax
                $gTaxBase = (int) ($gRateAfter * $n + $gServiceRp + $gExtraRp + $gPenaltyRp);
                $gTaxRp   = (int) round(($gTaxBase * $taxPctReservation) / 100);

                // Akumulasi footer
                $sumBase  += $gTaxBase;
                $sumTax   += $gTaxRp;
            @endphp
            <tr>
                <td class="clip">
                    <div class="clip">
                        {{ $g->guest?->name ?? '-' }}
                        @if (($g->breakfast ?? null) === 'Yes') <span class="muted">• BF</span>@endif
                    </div>
                    <div class="muted tiny clip">
                        #{{ $g->room?->room_no }} — {{ $g->room?->type }}
                        — Rate: {{ $m($gRate) }}
                        {!! $gDiscPct > 0 ? '<span class="muted">(Disc ' . number_format($gDiscPct, 2, ',', '.') . '%)</span>' : '' !!}
                    </div>
                </td>
                <td class="center">{{ $paxVal }}</td>
                <td class="center">{{ $n }}</td>
                <td class="center">{{ $ds($in) }}</td>
                <td class="center">{{ $ds($out) }}</td>
                <td class="center">{{ $g->actual_checkout ? 'CO' : 'IH' }}</td>
                <td class="right">{{ $m($gRateAfter * $n) }}</td>
                <td class="right">{{ $m($gServiceRp) }}</td>
                <td class="right">{{ $m($gExtraRp) }}</td>
                <td class="right">{{ $m($gPenaltyRp) }}</td>
                <td class="right"><strong>{{ $m($gTaxBase) }}</strong></td>
            </tr>
        @endforeach
        </tbody>
    </table>

    @php $sumGrand = $sumBase + $sumTax; @endphp

    {{-- ===== FOOTER TOTALS ===== --}}
    <table class="total">
        <tr>
            <td class="k" style="text-align:right">Subtotal (before tax)</td>
            <td class="v">{{ $m($sumBase) }}</td>
        </tr>
        <tr>
            <td class="k" style="text-align:right">Tax</td>
            <td class="v">{{ $m($sumTax) }}</td>
        </tr>
        <tr>
            <td class="k" style="text-align:right"><strong>TOTAL (Amount Due + Tax)</strong></td>
            <td class="v"><strong>{{ $m($sumGrand) }}</strong></td>
        </tr>
        @php
            $depShown = min($depositReservation, $sumGrand);
            $balance  = max(0, $sumGrand - $depositReservation);
        @endphp
        @if ($depShown > 0)
            <tr>
                <td class="k" style="text-align:right">(-) Deposit</td>
                <td class="v">{{ $m($depShown) }}</td>
            </tr>
        @endif
        <tr>
            <td class="k" style="text-align:right"><strong>BALANCE DUE</strong></td>
            <td class="v"><strong>{{ $m($balance) }}</strong></td>
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
