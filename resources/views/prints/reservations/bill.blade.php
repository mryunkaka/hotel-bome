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

        /* ===== Info header (2 kolom sejajar) - DomPDF Compatible ===== */
        table.info { width:100%; border-collapse:collapse; margin:4px 0 2px }
        table.info td { padding:1px 2px; vertical-align:top; word-wrap:break-word }
        table.info .lbl { color:#374151; font-weight:600; width:20%; }
        table.info .sep { width:8px; text-align:center; }  /* titik dua */
        table.info .gap { width:15px; }                    /* jarak antar kolom */
        table.info .val { width:auto; padding-right:4px; }

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

        /* Lebar kolom (total = 100%) */
        .col-guest  { width: 20%; }      /* guest lebih ramping, tetap muat 2 baris info */
        .col-pax    { width: 5%;  text-align: center; }
        .col-night  { width: 5%;  text-align: center; }
        .col-dt,
        .col-dt2    { width: 8%;  text-align: center; }  /* cukup untuk d/m H:i */
        .col-status { width: 5%;  text-align: center; }
        /* 5 kolom nominal × 3% = 15% */
        .col-amts   { width: 6%; }

        /* Paksa kolom nominal (kolom 7..11) rata kanan & nowrap */
        .grid td:nth-child(n+7):nth-child(-n+11) {
            text-align: right !important;
            white-space: nowrap;
        }


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
            <td class="lbl">{{ $isGroup ? 'Company' : 'Reserved By' }}</td>
            <td class="sep">:</td>
            <td class="val">{{ $rbName }}</td>
            <td class="gap"></td>
            <td class="lbl">Deposit</td>
            <td class="sep">:</td>
            <td class="val">{{ $m($depositReservation) }}</td>
        </tr>

        <tr>
            <td class="lbl">Address</td>
            <td class="sep">:</td>
            <td class="val">{{ $rbAddr }}</td>
            <td class="gap"></td>
            <td class="lbl">City</td>
            <td class="sep">:</td>
            <td class="val">{{ $rbCity }}</td>
        </tr>

        <tr>
            <td class="lbl">Phone</td>
            <td class="sep">:</td>
            <td class="val">{{ $rbPhone }}</td>
            <td class="gap"></td>
            <td class="lbl">Email</td>
            <td class="sep">:</td>
            <td class="val">{{ $rbEmail }}</td>
        </tr>
    </table>


    <div class="line"></div>

    {{-- ===== TABEL RINCIAN SEMUA GUEST (1 baris = 1 guest) ===== --}}
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
                <th class="col-amts">Service</th>
                <th class="col-amts">Extra</th>
                <th class="col-amts">Penalty</th>
                <th class="col-amts">Amount</th>
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
                <td class="center">{{ $m($gRateAfter * $n) }}</td>
                <td class="center">{{ $m($gServiceRp) }}</td>
                <td class="center">{{ $m($gExtraRp) }}</td>
                <td class="center">{{ $m($gPenaltyRp) }}</td>
                <td class="center"><strong>{{ $m($gTaxBase) }}</strong></td>
            </tr>
        @endforeach
        </tbody>
    </table>

    @php $sumGrand = $sumBase + $sumTax; @endphp

    @php
        // Total deposit dari reservation (sudah ada: $depositReservation)
        // Total pembayaran yang sudah tercatat di tabel payments untuk reservation ini:
        $amountPaid = 0;
        if ($resv?->id) {
            $amountPaid = (int) \App\Models\Payment::where('reservation_id', $resv->id)->sum('amount');
        }

        // Total yang harus dibayar setelah dikurangi deposit
        $dueAfterDeposit = max(0, $sumGrand - $depositReservation);

        // Hitung kembalian atau sisa tagihan
        $change    = max(0, $amountPaid - $dueAfterDeposit);
        $remaining = max(0, $dueAfterDeposit - $amountPaid);
    @endphp

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

        {{-- Amount Paid (total pembayaran yang tercatat) --}}
        <tr>
            <td class="k" style="text-align:right">Amount Paid</td>
            <td class="v">{{ $m($amountPaid) }}</td>
        </tr>

        {{-- (-) Deposit --}}
        @if ($depositReservation > 0)
            <tr>
                <td class="k" style="text-align:right">(-) Deposit</td>
                <td class="v">{{ $m($depositReservation) }}</td>
            </tr>
        @endif

        {{-- Baris akhir: Change (jika lebih) atau Balance Due (jika kurang) --}}
        @if ($change > 0)
            <tr>
                <td class="k" style="text-align:right"><strong>CHANGE</strong></td>
                <td class="v"><strong>{{ $m($change) }}</strong></td>
            </tr>
        @else
            <tr>
                <td class="k" style="text-align:right"><strong>BALANCE DUE</strong></td>
                <td class="v"><strong>{{ $m($remaining) }}</strong></td>
            </tr>
        @endif
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
