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
        $ds = fn($v) => $v ? Carbon::parse($v)->format('d/m H:i') : '-';

        $paper = strtoupper($paper ?? 'A4');
        $orientation = in_array(strtolower($orientation ?? 'portrait'), ['portrait','landscape'], true)
            ? strtolower($orientation) : 'portrait';

        $hotelRight = array_filter([
            $hotel?->name, $hotel?->address,
            trim(($hotel?->city ? $hotel->city.' ' : '').($hotel?->postcode ?? '')),
            $hotel?->phone ? 'Phone : '.$hotel->phone : null,
            $hotel?->email ?: null,
        ]);

        // ===== Reserved By
        $resv    = $reservation ?? null;
        $rbType  = strtoupper($resv?->reserved_by_type ?? 'GUEST');
        $isGroup = $rbType === 'GROUP' && $resv?->group;

        $rbObj   = $isGroup ? $resv?->group : ($resv?->guest ?? null);
        $rbName  = $rbObj?->name ?? '-';
        $rbAddr  = $rbObj?->address ?? '-';
        $rbCity  = $rbObj?->city ?? '-';
        $rbPhone = $rbObj?->phone ?? ($rbObj?->handphone ?? '-');
        $rbEmail = $rbObj?->email ?? '-';

        // MODE cetak — PASTIKAN didefinisikan di awal
        $mode = strtolower((string) ($mode ?? request('mode', 'single')));
        $mode = in_array($mode, ['all', 'single', 'remaining'], true) ? $mode : 'single';

        // Koleksi tamu yg akan DICETAK (bukan semua tamu di reservation)
        $guests = collect($guests ?? []);
        $totalGuestsRegistered = max(1, $guests->count());

        // Angka agregat yang akan DITAMPILKAN
        $sumBase  = 0;
        $sumTax   = 0;
        $sumGrand = 0;

        $taxPctReservation      = (float) ($resv?->tax?->percent ?? 0);
        $depositCardReservation = (int)   ($resv?->deposit_card ?? $resv?->deposit ?? 0);
        $tz = 'Asia/Makassar';

        // MODE cetak
        $mode = strtolower((string) ($mode ?? request('mode', 'single')));
        $mode = in_array($mode, ['all', 'single', 'remaining'], true) ? $mode : 'single';

        // Koleksi guests sudah dikirim dari route
        $guests = collect($guests ?? []);
        $totalGuestsRegistered = max(1, $guests->count());

        // Pajak efektif di tampilan baris (bukan untuk agregat reservation):
        // - mode=all    → pajak reservation apa adanya
        // - mode=single → pajak dibagi rata antar jumlah tamu (contoh 10% & 2 tamu → 5%)
        $taxPctEffective = $mode === 'single'
            ? ((float) $taxPctReservation) / $totalGuestsRegistered
            : (float) $taxPctReservation;

        // ===== SPLIT PAJAK (untuk mode=all) — sama seperti sebelumnya
        $split = $split ?? null;
        if ($mode === 'all') {
            if (!is_array($split)) {
                $reservationId = (int) ($resv->id ?? 0);
                $splitCount = 0;
                if ($reservationId > 0) {
                    $splitCount = (int) \App\Models\Payment::query()
                        ->where('reservation_id', $reservationId)
                        ->where('method', 'SPLIT')
                        ->count();
                }
                $split = [
                    'enabled'     => $splitCount > 0,
                    'guest_count' => $totalGuestsRegistered,
                    'split_count' => $splitCount,
                    'tax_total'   => 0,  // diisi setelah loop
                    'tax_share'   => 0,
                    'less_total'  => 0,
                    'to_pay_now'  => 0,
                ];
            }
        }

        // ===== Agregat reservation (independen dari mode tampilan baris)
        $resSumBaseAll      = 0;   // subtotal semua guest
        $resSumTaxAll       = 0;   // pajak semua guest (pakai $taxPctReservation)
        $resSumBaseChecked  = 0;   // subtotal guest yg sudah C/O
        $resSumTaxChecked   = 0;   // pajak guest yg sudah C/O
        $checkedItems       = [];  // daftar guest yang sudah C/O beserta nominal (base+tax)

        // ===== Minibar totals per guest … (bagianmu yang lama, biarkan)
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
        @php
            // ====== MODE SINGLE: bagi rata TOTAL TAX dari reservation ======
            $selectedGuestId = optional($guests->first())->id;
            $equalTaxShare   = null;  // pajak per-guest
            $equalTaxRema    = 0;     // sisa pembulatan utk 1 guest

            if ($mode === 'single' && $resv) {
                // Semua RG di reservation (bukan hanya yang dicetak)
                $allGuests = $resv->reservationGuests()->get();
                $guestCountAll = max(1, $allGuests->count());

                // TOTAL MINIBAR per RG (anggap "Service")
                $allMinibarTotalsAll = \App\Models\MinibarReceipt::query()
                    ->whereIn('reservation_guest_id', $allGuests->pluck('id')->all())
                    ->selectRaw('reservation_guest_id, SUM(total_amount) AS sum_total')
                    ->groupBy('reservation_guest_id')
                    ->pluck('sum_total', 'reservation_guest_id')
                    ->toArray();

                $taxPctReservation = (float) ($resv?->tax?->percent ?? 0);

                // Hitung total BASE semua RG (minibar = service; tidak ada service% tambahan)
                $totalBaseAll = 0;
                foreach ($allGuests as $gg) {   // ← PAKAI $gg di dalam loop ini
                    $in  = $gg->actual_checkin ?: $gg->expected_checkin;
                    $out = $gg->actual_checkout ?: \Illuminate\Support\Carbon::now('Asia/Makassar');
                    $n   = \App\Support\ReservationMath::nights($in, $out, 1);

                    $rate    = (float) \App\Support\ReservationMath::basicRate($gg);
                    $discPct = (float) ($gg->discount_percent ?? 0);
                    $discAmt = (int) round(($rate * $discPct) / 100);
                    $after   = max(0, $rate - $discAmt);

                    $charge  = (int) ($gg->charge ?? 0);
                    $extra   = (int) ($gg->extra_bed_total ?? ((int) ($gg->extra_bed ?? 0) * 100_000));
                    $mbSvc   = (int) ($allMinibarTotalsAll[$gg->id] ?? 0); // minibar sebagai "service"

                    $pen = \App\Support\ReservationMath::latePenalty(
                        $gg->expected_checkin ?: ($gg->reservation?->expected_arrival),
                        $gg->actual_checkin,
                        $rate,
                        ['tz' => 'Asia/Makassar'],
                    );
                    $penalty = (int) ($pen['amount'] ?? 0);

                    $base = (int) ($after * $n + $charge + $extra + $mbSvc + $penalty);
                    $totalBaseAll += $base;
                }

                // Pajak total reservation → bagi rata
                $totalTaxAll   = (int) round(($totalBaseAll * $taxPctReservation) / 100);
                $equalTaxShare = intdiv($totalTaxAll, $guestCountAll);
                $equalTaxRema  = $totalTaxAll - ($equalTaxShare * $guestCountAll);

                // Reset supaya footer Tax akurat di single-mode
                $sumTax = 0;
            }

            // Minibar utk tamu yang DICETAK (koleksi $guests)
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
        @endphp

        @foreach ($guests as $g)
            @php
                // Pax
                $paxVal = (int) (
                    $g->jumlah_orang
                    ?? ((int) ($g->male ?? 0) + (int) ($g->female ?? 0) + (int) ($g->children ?? 0))
                );

                // Nights, rate, discount… (biarkan seperti punyamu)
                $in  = $g->actual_checkin ?: $g->expected_checkin;
                $out = $g->actual_checkout ?: Carbon::now($tz);
                $n   = ReservationMath::nights($in, $out, 1);

                $gRate = (float) ReservationMath::basicRate($g);
                $gDiscPct   = (float) ($g->discount_percent ?? 0);
                $gDiscAmt   = (int) round(($gRate * $gDiscPct) / 100);
                $gRateAfter = max(0, $gRate - $gDiscAmt);

                $gChargeRp = (int) ($g->charge ?? 0);
                $gExtraRp  = (int) ($g->extra_bed_total ?? ((int) ($g->extra_bed ?? 0) * 100_000));

                // MINIBAR jadi "Service" (tanpa service% tambahan)
                $gServiceRp = (int) ($minibarTotals[$g->id] ?? 0);

                $gPen = ReservationMath::latePenalty(
                    $g->expected_checkin ?: ($g->reservation?->expected_arrival),
                    $g->actual_checkin,
                    $gRate,
                    ['tz' => $tz],
                );
                $gPenaltyRp = (int) ($gPen['amount'] ?? 0);

                // BASE utk pajak
                $gTaxBase = (int) ($gRateAfter * $n + $gChargeRp + $gExtraRp + $gServiceRp + $gPenaltyRp);

                // Pajak default
                $gTaxRp = (int) round(($gTaxBase * $taxPctReservation) / 100);

                // Override pajak kalau single: pakai jatah bagi-ratanya
                if ($mode === 'single' && $equalTaxShare !== null) {
                    $gTaxRp = (int) $equalTaxShare;
                    if ($equalTaxRema > 0 && $g->id === $selectedGuestId) {
                        $gTaxRp += $equalTaxRema; // sisa pembulatan ke tamu pertama yang dicetak
                    }
                }

                // Akumulasi footer
                $sumBase += $gTaxBase;
                $sumTax  += $gTaxRp;

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
    // Agregat reservation:
    $reservation_total_all     = (int) ($resSumBaseAll + $resSumTaxAll);
    $reservation_total_checked = (int) ($resSumBaseChecked + $resSumTaxChecked);
    $reservation_open_due      = max(0, $reservation_total_all - $reservation_total_checked);
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

        {{-- ===== FOOTER: MODE REMAINING ===== --}}
        @if ($mode === 'remaining')
            <tr>
                <td class="k" style="text-align:right">Total Reservation (All Guests)</td>
                <td class="v">{{ $m($reservation_total_all) }}</td>
            </tr>

            <tr>
                <td class="k" style="text-align:right">
                    Less: Guests already checked-out
                    @if(!empty($checkedItems))
                        <div class="muted" style="font-size:7.2px; margin-top:2px">
                            @foreach($checkedItems as $ci)
                                • {{ $ci['guest_name'] }} — {{ $m($ci['amount']) }}<br>
                            @endforeach
                        </div>
                    @endif
                </td>
                <td class="v">{{ $m($reservation_total_checked) }}</td>
            </tr>

            <tr>
                <td class="k" style="text-align:right"><strong>Remaining Due</strong></td>
                <td class="v"><strong>{{ $m($reservation_open_due) }}</strong></td>
            </tr>

            {{-- Pada mode remaining, biasanya kita TIDAK menampilkan "Amount Paid / Change"
                karena Remaining Due sendiri sudah net dari porsi tamu yang C/O. --}}
        @endif

        @if ($mode === 'all')
        @php
            // Basis tagihan yang dicetak (sudah mempertimbangkan split pajak jika aktif)
            $billBase = (!empty($split['to_pay_now']) && $split['to_pay_now'] > 0)
                ? (int) $split['to_pay_now']
                : (int) $sumGrand;

            // HANYA yang bukan jenis deposit yang dikecualikan dari "Amount Paid".
            // Catatan: TIDAK mengecualikan 'SPLIT' karena di kasus kamu SPLIT tanpa pay-now
            // memang menyimpan nilai "dibayar oleh tamu itu" di kolom amount, dan
            // kamu ingin itu terhitung sebagai Amount Paid agregat.
            $excludeFromPaid = [
                'DEPOSIT','DEPOSIT CARD','DEPOSIT_CARD','DEPOSIT CASH','DEPOSITCARD','DEPOSIT-CARD',
                // JANGAN tulis 'SPLIT' di sini
            ];

            $reservationId = (int) ($resv->id ?? 0);

            // Ambil semua payment non-deposit → pakai KOLOM amount saja untuk Amount Paid.
            $amountPaid = 0;
            if ($reservationId > 0) {
                $amountPaid = (int) \App\Models\Payment::query()
                    ->where('reservation_id', $reservationId)
                    ->where(function ($q) use ($excludeFromPaid) {
                        $q->whereNull('method')->orWhereNotIn('method', $excludeFromPaid);
                    })
                    ->sum('amount');  // ← PENTING: hanya kolom amount
            }

            // Deposit kartu sebagai pengurang tagihan (bukan "Amount Paid")
            $effectiveDeposit = (int) $depositCardReservation;

            // Hitung due/change berbasis billBase - deposit vs amountPaid
            $dueAfterDeposit = max(0, $billBase - $effectiveDeposit);
            $change          = max(0, $amountPaid - $dueAfterDeposit);
            $remaining       = max(0, $dueAfterDeposit - $amountPaid);
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
