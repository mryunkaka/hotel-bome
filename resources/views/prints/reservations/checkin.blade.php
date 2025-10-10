{{-- resources/views/prints/reservations/checkin.blade.php --}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    @php
        use App\Support\ReservationView;
        use Illuminate\Support\Carbon;

        // ===== Paper & orientation (opsional di Blade)
        $paper = strtoupper($paper ?? 'A4');
        $orientation = in_array(strtolower($orientation ?? 'portrait'), ['portrait', 'landscape'], true)
            ? strtolower($orientation) : 'portrait';

        // ===== Mode tampilan: 'all' (default) atau 'single'
        $mode = strtolower((string)($mode ?? request('mode', 'all')));
        if (! in_array($mode, ['single', 'all'], true)) $mode = 'all';

        // ===== Siapkan helper ReservationView (packing data umum + format)
        $prepared = ReservationView::prepareForPrint([
            'rows'          => $rows ?? null,
            'items'         => $items ?? null,
            'ps'            => $ps ?? null,
            'taxLookup'     => $taxLookup ?? null,
            'depositRoom'   => null,   // total dihitung dari RG (di bawah)
            'depositCard'   => null,
            'paidTotal'     => $paid_total ?? null,
            'reservedTitle' => $reserved_title ?? null,
            'reservedBy'    => $reserved_by ?? null,
            'billTo'        => $billTo ?? [],
            'hotel'         => $hotel ?? null,
            'clerkName'     => $clerkName ?? null,
            'clerk'         => $clerk ?? null,
        ]);

        // Unpack yang sering dipakai
        $hotelRight = $prepared['hotelRight'] ?? [];
        $clerkName  = $prepared['clerkName'] ?? ($reservation?->creator?->name ?? null);

        // ===== Ambil daftar ReservationGuest sesuai mode
        $rgList = collect();
        if ($mode === 'single' && isset($guest)) {
            $rgList = collect([$guest]);
        } else {
            $rgList = collect($reservation?->reservationGuests ?? []);
        }

        // ===== Totalkan PS (jumlah orang) & deposit dari RG yang ditampilkan
        $totalPs = 0;
        $totalDepRoom = 0.0;
        $totalDepCard = 0.0;

        foreach ($rgList as $rg) {
            $ps = (int) ($rg->jumlah_orang ?? max(1, (int)$rg->male + (int)$rg->female + (int)$rg->children));
            $totalPs      += $ps;
            $totalDepRoom += (float) ($rg->deposit_room ?? 0);
            $totalDepCard += (float) ($rg->deposit_card ?? 0);
        }

        // ===== Range tanggal expected (header)
        $expected_arrival   = $reservation?->expected_arrival;
        $expected_departure = $reservation?->expected_departure;

        // ===== Nights untuk header (dari expected range)
        $nights = '-';
        if ($expected_arrival && $expected_departure) {
            $a = Carbon::parse($expected_arrival)->startOfDay();
            $d = Carbon::parse($expected_departure)->startOfDay();
            $nights = max(1, $a->diffInDays($d));
        }

        // ===== Formatter praktis (pakai ReservationView)
        $money    = fn($v) => ReservationView::fmtMoney($v);
        $dateLong = fn($v) => ReservationView::fmtDate($v, true);
        $dateShort= fn($v) => ReservationView::fmtDate($v, false);

        // TZ untuk jam kecil di ARR/DEPT
        $tz = method_exists(ReservationView::class, 'tz') ? ReservationView::tz() : 'Asia/Makassar';
    @endphp

    <title>{{ $title ?? 'GUEST CHECK-IN' }} — {{ $invoiceNo ?? '#' . ($invoiceId ?? '-') }}</title>

    <style>
        @page { size: {{ $paper }} {{ $orientation }}; margin: 12mm; }

        body { margin:0; padding:0; font-family: DejaVu Sans, Arial, sans-serif; font-size:10px; color:#111827; line-height:1.35; }

        /* ===== Header ===== */
        .hdr-table { width:100%; border-collapse:collapse; margin-bottom:10px; }
        .hdr-td { vertical-align:top; }
        .hdr-left{ width:35%; } .hdr-mid{ width:30%; text-align:center; } .hdr-right{ width:35%; text-align:right; }

        .logo { display:inline-block; vertical-align:middle; margin-right:6px; }
        .logo img { width:80px; object-fit:contain; }

        .hotel-meta { color:#111827; font-size:9px; line-height:1.35; }

        .title { font-size:16px; font-weight:700; text-decoration:underline; }
        .resv-no { margin-top:4px; font-weight:600; letter-spacing:.2px; }

        /* ===== Key/Value top info (dua kolom) ===== */
        .kv-table { width:100%; border-collapse:collapse; margin:10px 0 8px; }
        .kv-td { padding:2px 0; }
        .k { color:#374151; width:110px; display:inline-block; }
        .v { color:#111827; font-weight:600; }

        .line { border-top:1.5px solid #1F2937; margin:8px 0; }

        /* ===== Items table: fixed layout + wrapping terkendali ===== */
        table.items { width:100%!important; table-layout:fixed!important; border-collapse:collapse; font-size:9px; }
        .items th, .items td { padding:4px 3px; box-sizing:border-box; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .items thead th { border-top:1.5px solid #1F2937; border-bottom:1px solid #1F2937; }

        /* Angka rata kanan rapih */
        .num { text-align:right; font-variant-numeric: tabular-nums; }

        /* Kolom yang boleh WRAP: CAT, GUEST, ARR, DEPT */
        .items th:nth-child(2), .items td:nth-child(2),
        .items th:nth-child(9), .items td:nth-child(9),
        .items th:nth-child(10), .items td:nth-child(10),
        .items th:nth-child(11), .items td:nth-child(11) {
            white-space: normal !important;
            overflow: visible !important;
            text-overflow: clip !important;
        }

        /* Posisi kolom lainnya */
        .items th:nth-child(1), .items td:nth-child(1)   { text-align:left;  } /* ROOM */
        .items th:nth-child(3), .items td:nth-child(3),
        .items th:nth-child(4), .items td:nth-child(4),
        .items th:nth-child(5), .items td:nth-child(5),
        .items th:nth-child(6), .items td:nth-child(6),
        .items th:nth-child(7), .items td:nth-child(7)   { text-align:right; } /* angka */
        .items th:nth-child(8), .items td:nth-child(8)   { text-align:center;} /* PS  */
        .items th:nth-child(10), .items td:nth-child(10),
        .items th:nth-child(11), .items td:nth-child(11) { text-align:center; } /* ARR/DEPT */

        /* ARR/DEPT: 2-baris (tanggal & jam) */
        .dtwrap { line-height:1.15; }
        .dtwrap .date { display:block; }
        .dtwrap .time { display:block; font-size:85%; color:#374151; }

        /* ===== Sections, footer, signature ===== */
        .section { margin-top:10px; }
        .mini-title { font-weight:700; font-size:10px; text-transform:uppercase; letter-spacing:.3px; margin-bottom:4px; }
        .box { border:1px solid #E5E7EB; border-radius:6px; padding:6px 8px; }
        .note { font-size:9px; color:#6b7280; margin-top:6px; }

        .grid-2 { display:table; width:100%; table-layout:fixed; }
        .col { display:table-cell; vertical-align:top; width:50%; padding:0 4px; }

        .kv { width:100%; border-collapse:collapse; font-size:9px; }
        .kv td { padding:3px 0; vertical-align:top; }
        .kv .k { color:#6b7280; width:36%; white-space:nowrap; }
        .kv .v { color:#111827; font-weight:600; }

        .signature-box { display:inline-block; margin-top:14px; min-width:150px; text-align:center; }
        .signature-line { border-top:1px solid #9CA3AF; margin-top:22px; padding-top:4px; }

        .footer { margin-top:12px; }
        .foot-table { width:100%; border-collapse:collapse; font-size:9px; }
        .foot-left,.foot-mid,.foot-right { padding:3px 0; vertical-align:top; }
        .foot-left{ text-align:left; width:25%; } .foot-mid{ text-align:center; width:50%; } .foot-right{ text-align:right; width:25%; }
    </style>
</head>
<body>

    {{-- ===== HEADER ===== --}}
    <table class="hdr-table">
        <tr>
            <td class="hdr-td hdr-left">
                <div>
                    @if (!empty($logoData))
                        <span class="logo"><img src="{{ $logoData }}" alt="Logo"></span>
                    @endif
                </div>
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

    {{-- ===== TOP INFO (khusus CHECK-IN) ===== --}}
    @php
        // Data utama utk header check-in (mode-aware)
        $isSingle = ($mode ?? 'all') === 'single' && isset($rgList) && $rgList->count() === 1;
        $g = $isSingle ? $rgList->first() : null;

        $primaryGuest = $isSingle
            ? ($g->guest?->display_name ?? trim(($g->guest?->salutation?->value ?? '').' '.($g->guest?->name ?? 'Guest')))
            : ($companyName ?: ($reserved_by ?? '-'));

        $roomLabel = $isSingle
            ? (($g->room?->room_no ?? '#'.$g->room_id).' — '.($g->room?->type ?? '-'))
            : ($hotel?->name ?? '-');

        // Ambil earliest actual / expected dari RG yang ditampilkan
        $earliestActualCin = optional(
            $rgList->filter(fn ($x) => !empty($x->actual_checkin))
                ->sortBy('actual_checkin')
                ->first()
        )->actual_checkin;

        $earliestExpectedCin = optional(
            $rgList->filter(fn ($x) => !empty($x->expected_checkin))
                ->sortBy('expected_checkin')
                ->first()
        )->expected_checkin;

        $latestActualCout = optional(
            $rgList->filter(fn ($x) => !empty($x->actual_checkout))
                ->sortByDesc('actual_checkout')
                ->first()
        )->actual_checkout;

        $latestExpectedCout = optional(
            $rgList->filter(fn ($x) => !empty($x->expected_checkout))
                ->sortByDesc('expected_checkout')
                ->first()
        )->expected_checkout;

        // Header: prioritas actual → expected → header
        $cin  = $isSingle
            ? ($g->actual_checkin  ?? $g->expected_checkin  ?? $expected_arrival)
            : ($earliestActualCin  ?? $earliestExpectedCin  ?? $expected_arrival);

        $cout = $isSingle
            ? ($g->actual_checkout ?? $g->expected_checkout ?? $expected_departure)
            : ($latestActualCout   ?? $latestExpectedCout   ?? $expected_departure);

        $payMethod = ucfirst($payment['method'] ?? strtolower($reservation?->method ?? 'personal'));
        $statusTxt = strtoupper($status ?? ($reservation?->status ?? 'CONFIRM'));
    @endphp

    <table class="kv-table">
        <tr>
            {{-- KIRI: fokus ke Tamu & Kamar --}}
            <td class="kv-td" style="width:65%;">
                <div><span class="k">{{ $isSingle ? 'Guest' : 'Reserved By' }}</span><span class="v">: {{ $primaryGuest }}</span></div>
                <div><span class="k">{{ $isSingle ? 'Room' : 'Hotel' }}</span><span class="v">: {{ $roomLabel }}</span></div>
                <div><span class="k">Check-in</span><span class="v">: {{ \App\Support\ReservationView::fmtDate($cin, true) }}</span></div>
                <div><span class="k">Check-out</span><span class="v">: {{ \App\Support\ReservationView::fmtDate($cout, true) }}</span></div>
                <div><span class="k">Nights</span><span class="v">: {{ $nights }}</span></div>
                <div><span class="k">Guests (PS)</span><span class="v">: {{ $totalPs }}</span></div>
            </td>

            {{-- KANAN: status & pembayaran + deposit --}}
            <td class="kv-td" style="width:35%;">
                <div><span class="k">Payment Method</span><span class="v">: {{ $payMethod }}</span></div>
                <div><span class="k">Status</span><span class="v">: {{ $statusTxt }}</span></div>
                <div><span class="k">Entry Date</span><span class="v">: {{ \App\Support\ReservationView::fmtDate($issuedAt ?? ($generatedAt ?? now()), true) }}</span></div>
                <div><span class="k">Deposit Card</span><span class="v">: {{ \App\Support\ReservationView::fmtMoney($totalDepCard) }}</span></div>
                <div><span class="k">Deposit Room</span><span class="v">: {{ \App\Support\ReservationView::fmtMoney($totalDepRoom) }}</span></div>
                <div><span class="k">Deposit Total</span><span class="v">: {{ \App\Support\ReservationView::fmtMoney($totalDepRoom + $totalDepCard) }}</span></div>
            </td>
        </tr>
    </table>

    <div class="line"></div>

    {{-- ===== ITEMS TABLE (BASE, DISC%, AFTER, DEP R, DEP C, PS, GUEST, ARR, DEPT) ===== --}}
    <table class="items">
        <colgroup>
            <col style="width:12mm">  <!-- ROOM -->
            <col style="width:22mm">  <!-- CAT  (wrap) -->
            <col style="width:18mm">  <!-- BASE -->
            <col style="width:12mm">  <!-- DISC% -->
            <col style="width:20mm">  <!-- AFTER -->
            <col style="width:16mm">  <!-- DEP R -->
            <col style="width:16mm">  <!-- DEP C -->
            <col style="width:8mm">   <!-- PS -->
            <col style="width:34mm">  <!-- GUEST (wrap) -->
            <col style="width:18mm">  <!-- ARR (wrap 2 baris) -->
            <col style="width:18mm">  <!-- DEPT (wrap 2 baris) -->
        </colgroup>
        <thead>
            <tr>
                <th>ROOM</th>
                <th>CAT</th>
                <th class="num">BASE</th>
                <th class="num">DISC%</th>
                <th class="num">AFTER</th>
                <th class="num">DEP&nbsp;R</th>
                <th class="num">DEP&nbsp;C</th>
                <th class="center">PS</th>
                <th>GUEST</th>
                <th class="center">ARR</th>
                <th class="center">DEPT</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rgList as $g)
                @php
                    $roomNo  = $g->room?->room_no ?? ('#'.$g->room_id);
                    $cat     = $g->room?->type ?? '-';
                    $base    = (float) ($g->room_rate ?? $g->room?->price ?? 0);
                    $discPct = (float) ($g->discount_percent ?? 0);
                    $discPct = max(0, min(100, $discPct));
                    $after   = max(0, round($base * (1 - $discPct/100)));

                    $depR    = (float) ($g->deposit_room ?? 0);
                    $depC    = (float) ($g->deposit_card ?? 0);

                    $ps      = (int) ($g->jumlah_orang ?? max(1, (int)$g->male + (int)$g->female + (int)$g->children));
                    $guestNm = $g->guest?->display_name
                              ?? trim(($g->guest?->salutation?->value ?? '') . ' ' . ($g->guest?->name ?? '-'));

                    $arrRaw  = $g->actual_checkin ?? $g->expected_checkin ?? $expected_arrival;
                    $depRaw  = $g->actual_checkout ?? $g->expected_checkout ?? $expected_departure;
                @endphp
                <tr>
                    <td>{{ $roomNo }}</td>
                    <td>{{ $cat }}</td>
                    <td class="num">{{ $money($base) }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format($discPct, 2, '.', ''), '0'), '.') }}%</td>
                    <td class="num">{{ $money($after) }}</td>
                    <td class="num">{{ $money($depR) }}</td>
                    <td class="num">{{ $money($depC) }}</td>
                    <td class="center">{{ $ps }}</td>
                    <td>{{ $guestNm }}</td>
                    <td class="center">
                        <div class="dtwrap">
                            <span class="date">{{ $dateShort($arrRaw) }}</span>
                            @if ($arrRaw)
                                <span class="time">{{ Carbon::parse($arrRaw)->timezone($tz)->format('H:i') }}</span>
                            @endif
                        </div>
                    </td>
                    <td class="center">
                        <div class="dtwrap">
                            <span class="date">{{ $dateShort($depRaw) }}</span>
                            @if ($depRaw)
                                <span class="time">{{ Carbon::parse($depRaw)->timezone($tz)->format('H:i') }}</span>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="11" class="center">No rooms</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- Catatan kecil di bawah tabel --}}
    <div class="note">* Room rate shown excludes any applicable fees.</div>

    {{-- Ringkas single / summary all --}}
    <div class="section box">
        @if ($mode === 'single' && $rgList->count() === 1)
            @php $g = $rgList->first(); @endphp
            <div class="mini-title">Guest & Stay Details</div>
            <table class="kv">
                <tr><td class="k">Reservation No</td><td class="v">{{ $invoiceNo ?? ('#' . ($invoiceId ?? '-')) }}</td></tr>
                <tr><td class="k">Room</td><td class="v">{{ ($g->room?->room_no ?? '#'.$g->room_id) . ' — ' . ($g->room?->type ?? '-') }}</td></tr>
                <tr><td class="k">Rate / Night</td><td class="v">{{ $money($g->room_rate ?? $g->room?->price ?? 0) }}</td></tr>
                <tr><td class="k">Guests (PS)</td><td class="v">{{ (int) ($g->jumlah_orang ?? max(1, (int)$g->male + (int)$g->female + (int)$g->children)) }}</td></tr>
            </table>
        @else
            <div class="mini-title">Reservation Summary</div>
            <table class="kv">
                <tr><td class="k">Reserved By</td><td class="v">{{ $companyName ?: ($reserved_by ?? '-') }}</td></tr>
                <tr><td class="k">Guests (PS)</td><td class="v">{{ $totalPs }}</td></tr>
                <tr><td class="k">Reservation No</td><td class="v">{{ $invoiceNo ?? ('#' . ($invoiceId ?? '-')) }}</td></tr>
                <tr>
                    <td class="k">Period</td>
                    <td class="v">{{ $dateLong($expected_arrival) }} <span class="subtle">→</span> {{ $dateLong($expected_departure) }}</td>
                </tr>
            </table>
        @endif
    </div>

    {{-- ===== Policies & Acknowledgement (dipertahankan) ===== --}}
    <div class="section box">
        <div class="mini-title">Policies & Acknowledgement</div>
        <ul class="list">
            <li>Check-in time after 13:00; check-out time before 12:00 (noon).</li>
            <li>Late check-out is subject to availability and may incur charges.</li>
            <li>Room rate is exclusive of taxes and incidentals unless stated otherwise.</li>
            <li>Non-smoking room; a cleaning fee may apply for violations.</li>
        </ul>
        <div class="grid-2" style="margin-top:6px;">
            @php
                // Nama untuk kolom tanda tangan kiri
                $leftSignName = null;

                if (($mode ?? 'all') === 'single' && isset($rgList) && $rgList->count() === 1) {
                    $g = $rgList->first();
                    $leftSignName = $g->guest?->display_name
                        ?? trim(($g->guest?->salutation?->value ?? '') . ' ' . ($g->guest?->name ?? 'Guest'));
                } else {
                    // nama pemesan (reserved by) saat mode all
                    $leftSignName = $companyName
                        ?: ($reserved_by ?? ($billTo['name'] ?? 'Reserved By'));
                }
            @endphp

            <div class="col">
                <div class="signature-box">
                    <div class="signature-line"></div>
                    {{ $leftSignName }}
                </div>
            </div>
            <div class="col" style="text-align:right;">
                <div class="signature-box">
                    <div class="signature-line"></div>
                    {{ $clerkName ?? 'Reception' }}
                </div>
            </div>
        </div>
    </div>

    <div class="line"></div>

    {{-- ===== FOOTER ===== --}}
    <div class="footer">
        <table class="foot-table">
            <tr>
                <td class="foot-left">Page&nbsp;&nbsp;: 1</td>
                <td class="foot-mid">
                    {{ $hotel?->city ? $hotel->city . ' , ' : '' }}
                    {{ $dateShort($generatedAt ?? now()) }}
                </td>
                <td class="foot-right">&nbsp;</td>
            </tr>
        </table>
    </div>

</body>
</html>
