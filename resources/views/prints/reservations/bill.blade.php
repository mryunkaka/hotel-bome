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

        // Gunakan koleksi yg sudah dikirim route (berisi 1 RG yang dipilih)
        $guests = collect($guests ?? []);
        $totalGuestsRegistered = $guests->count();

        // ===== Akumulasi footer
        $sumBase  = 0; // subtotal sebelum pajak
        $sumTax   = 0; // pajak
        $sumGrand = 0; // subtotal + pajak

        $taxPctReservation     = (float) ($resv?->tax?->percent ?? 0);
        $depositCardReservation = (int) ($resv?->deposit_card ?? $resv?->deposit ?? 0);
        $tz = 'Asia/Makassar';

        $mode = strtolower((string) ($mode ?? request('mode', 'single')));
        $mode = in_array($mode, ['all', 'single'], true) ? $mode : 'single';

        // Koleksi guests sudah dikirim dari route
        $guests = collect($guests ?? []);
        $totalGuestsRegistered = max(1, $guests->count());

        // Pajak efektif:
        // - mode=all    → pakai pajak reservation apa adanya
        // - mode=single → pajak dibagi rata antar jumlah tamu (contoh 10% & 2 tamu → 5%)
        $taxPctEffective = $mode === 'single'
            ? ((float) $taxPctReservation) / $totalGuestsRegistered
            : (float) $taxPctReservation;

            // ===== SPLIT PAJAK (untuk mode=all). Route boleh kirim $split; bila tidak ada, hitung sederhana di blade ini.
        $split = $split ?? null;

        if ($mode === 'all') {
            if (!is_array($split)) {
                // fallback kalkulasi sederhana pakai data yang sudah tersedia di blade
                $reservationId = (int) ($resv->id ?? 0);
                $splitCount = 0;
                if ($reservationId > 0) {
                    $splitCount = (int) \App\Models\Payment::query()
                        ->where('reservation_id', $reservationId)
                        ->where('method', 'SPLIT')
                        ->count();
                }

                // total pajak seluruh baris = $sumTax akan dihitung setelah loop guests selesai.
                // tapi untuk tampilan, kita bisa isi struktur dulu — nilainya dirapikan lagi di footer.
                $split = [
                    'enabled'     => $splitCount > 0,
                    'guest_count' => $totalGuestsRegistered,
                    'split_count' => $splitCount,
                    'tax_total'   => 0,  // diisi ulang nanti sesudah $sumTax selesai
                    'tax_share'   => 0,
                    'less_total'  => 0,
                    'to_pay_now'  => 0,
                ];
            }
        }

        // ===== Minibar totals per guest (pakai total_amount dari MinibarReceipt)
        $minibarTotals = [];
        if (!empty($resv) && isset($guests)) {
            $guestIds = collect($guests)->pluck('id')->filter()->values()->all();
            if (!empty($guestIds)) {
                $minibarTotals = \App\Models\MinibarReceipt::query()
                    ->whereIn('reservation_guest_id', $guestIds)
                    ->selectRaw('reservation_guest_id, SUM(total_amount) AS sum_total')
                    ->groupBy('reservation_guest_id')
                    ->pluck('sum_total', 'reservation_guest_id')
                    ->toArray();
            }
        }
        $perGuestBase = [];
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
        .col-guest  { width: 18%; }      /* guest lebih ramping, tetap muat 2 baris info */
        .col-pax    { width: 3%;  text-align: center; }
        .col-night  { width: 3%;  text-align: center; }
        .col-dt,
        .col-dt2    { width: 5%;  text-align: center; }  /* cukup untuk d/m H:i */
        .col-status { width: 4%;  text-align: center; }
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
            <td class="lbl">Deposit Card</td>
            <td class="sep">:</td>
            <td class="val">{{ $m($depositCardReservation) }}</td>
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
                <th class="col-amts">Charge</th>
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

                // Charge & Extra & service
                $gChargeRp = (int) ($g->charge ?? 0);
                $gExtraRp   = (int) ($g->extra_bed_total ?? ((int) ($g->extra_bed ?? 0) * 100_000));
                $gMinibar = (int) ($minibarTotals[$g->id] ?? 0);

                // Penalty
                $gPen = ReservationMath::latePenalty(
                    $g->expected_checkin ?: ($g->reservation?->expected_arrival),
                    $g->actual_checkin,
                    $gRate,
                    ['tz' => $tz],
                );
                $gPenaltyRp = (int) ($gPen['amount'] ?? 0);

                // Tax base & tax
                $gTaxBase = (int) ($gRateAfter * $n + $gChargeRp + $gExtraRp + $gPenaltyRp + $gMinibar);
                $perGuestBase[$g->id] = $gTaxBase;
                $gTaxRp   = (int) round(($gTaxBase * $taxPctEffective) / 100);

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
                <td class="center">{{ $m($gChargeRp) }}</td>
                <td class="center">{{ $m($gMinibar) }}</td>
                <td class="center">{{ $m($gExtraRp) }}</td>
                <td class="center">{{ $m($gPenaltyRp) }}</td>
                <td class="center"><strong>{{ $m($gTaxBase) }}</strong></td>
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

        {{-- SPLIT pajak (opsional, hanya mode=all) --}}
        @if ($mode === 'all' && !empty($split['enabled']))
            @php
                $taxTotal  = (int) $sumTax;
                $gCount    = max(1, (int) ($split['guest_count'] ?? $totalGuestsRegistered));
                $sCount    = max(0, (int) ($split['split_count'] ?? 0));

                $taxShare  = $gCount > 0 ? (int) floor($taxTotal / $gCount) : 0;
                $lessTotal = (int) min($sumGrand, $taxShare * $sCount);
                $toPayNow  = (int) max(0, $sumGrand - $lessTotal);

                $split['tax_total']  = $taxTotal;
                $split['tax_share']  = $taxShare;
                $split['less_total'] = $lessTotal;
                $split['to_pay_now'] = $toPayNow;
            @endphp

            @if (($split['split_count'] ?? 0) > 0 && ($split['tax_total'] ?? 0) > 0)
                <tr>
                    <td class="k" style="text-align:right">
                        Less: Split Bill (Tax {{ $split['split_count'] }} of {{ $split['guest_count'] }})
                        <span class="muted">— per share {{ $m($split['tax_share']) }}</span>
                    </td>
                    <td class="v">{{ $m($split['less_total']) }}</td>
                </tr>
                <tr>
                    <td class="k" style="text-align:right"><strong>Amount to pay now</strong></td>
                    <td class="v"><strong>{{ $m($split['to_pay_now']) }}</strong></td>
                </tr>
            @endif
        @endif

        @if ($mode === 'all')
            @php
                $billBase = (!empty($split['to_pay_now']) && $split['to_pay_now'] > 0)
                    ? (int) $split['to_pay_now']
                    : (int) $sumGrand;

                // daftar metode yang TIDAK dihitung sebagai "payment tunai/kartu" biasa
                $excludeAsCash = [
                    'DEPOSIT','DEPOSIT CARD','DEPOSIT_CARD','DEPOSIT CASH','DEPOSITCARD','DEPOSIT-CARD',
                ];

                $reservationId = (int) ($resv->id ?? 0);

                // 1) Pembayaran nyata: sum(amount) SEMUA payment yang bukan deposit & bukan SPLIT
                $amountPaidCash = 0;
                if ($reservationId > 0) {
                    $amountPaidCash = (int) \App\Models\Payment::query()
                        ->where('reservation_id', $reservationId)
                        ->where(function ($q) use ($excludeAsCash) {
                            $q->whereNull('method')
                            ->orWhereNotIn('method', array_merge($excludeAsCash, ['SPLIT']));
                        })
                        ->sum('amount');
                }

                // 2) Pembayaran hasil "Split Bill": sum(actual_amount) KHUSUS method = SPLIT
                //    (karena entri SPLIT menyimpan nominal yang harus dibayar per-guest di kolom actual_amount)
                $amountPaidSplitActual = 0;
                if ($reservationId > 0) {
                    $amountPaidSplitActual = (int) \App\Models\Payment::query()
                        ->where('reservation_id', $reservationId)
                        ->where('method', 'SPLIT')
                        ->sum('actual_amount');
                }

                // 3) Total yang dianggap sudah dibayar di bill
                $amountPaid = $amountPaidCash + $amountPaidSplitActual;

                // 4) Deposit kartu tetap diperlakukan sebagai pengurang tagihan (bukan "Amount Paid")
                $effectiveDeposit = (int) $depositCardReservation;

                // 5) Hitung due/change
                $dueAfterDeposit  = max(0, $billBase - $effectiveDeposit);
                $change    = max(0, $amountPaid - $dueAfterDeposit);
                $remaining = max(0, $dueAfterDeposit - $amountPaid);
            @endphp

            <tr>
                <td class="k" style="text-align:right">Amount Paid</td>
                <td class="v">{{ $m($amountPaid) }}</td>
            </tr>

            @if ($depositCardReservation > 0)
                <tr>
                    <td class="k" style="text-align:right">(-) Deposit Card</td>
                    <td class="v">{{ $m($depositCardReservation) }}</td>
                </tr>
            @endif

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
