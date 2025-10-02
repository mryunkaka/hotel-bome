{{-- resources/views/prints/reservations/folio.blade.php --}}
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

        $m = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
        $d = fn($v) => $v ? Carbon::parse($v)->format('d/m/Y') : '-';
        $dt = fn($v) => $v ? Carbon::parse($v)->format('d/m/Y H:i:s') : '-';

        $hotelRight = array_values(
            array_filter([
                $hotel?->name,
                $hotel?->address,
                trim(($hotel?->city ? $hotel->city . ' ' : '') . ($hotel?->postcode ?? '')),
                $hotel?->phone ? 'Phone : ' . $hotel->phone : null,
                $hotel?->email ?: null,
            ]),
        );

        $depositHeader = (float) ($reservation?->deposit ?? 0);

        $row = $row ?? [];
        $actualIn = $row['actual_in'] ?? null;
        $actualOut = $row['actual_out'] ?? ($row['expected_out'] ?? null);
        $roomNo = $row['room_no'] ?? '-';
        $roomType = $row['category'] ?? '-';
        $rate = (int) ($row['rate'] ?? ($calc['rate'] ?? 0));
        $nights = (int) ($row['nights'] ?? ($calc['nights'] ?? 1));
        $pax = (int) ($row['ps'] ?? 1);
        $guestName = $row['guest_display'] ?? '-';

        $discPct = (float) ($row['discount_percent'] ?? ($calc['disc_percent'] ?? 0));
        $taxPct = (float) ($row['tax_percent'] ?? ($calc['tax_percent'] ?? 0));
        $serviceRp = (int) ($row['service'] ?? ($calc['service'] ?? 0));
        $extraRp = (int) ($row['extra_bed_total'] ?? ($calc['extra'] ?? 0));

        // ==== LATE ARRIVAL PENALTY (match kartu "Guest Bill") ====
        $expectedArrival = $reservation?->expected_arrival ?? null;
        $pen = ReservationMath::latePenalty($expectedArrival, $actualIn, $rate, ['tz' => 'Asia/Makassar']);
        $penaltyRp = (float) ($pen['amount'] ?? 0);
        $penaltyH = (int) ($pen['hours'] ?? 0);

        // Build entries jika tak disuplai dari controller
        $entries = $entries ?? [];
        if (empty($entries)) {
            // room per malam
            if ($actualIn && $nights > 0) {
                $start = Carbon::parse($actualIn)->startOfDay();
                for ($i = 0; $i < $nights; $i++) {
                    $date = $start->copy()->addDays($i)->toDateString();
                    $entries[] = [
                        'date' => $date,
                        'desc' => "ROOM $roomNo  " . Carbon::parse($date)->format('d/m/Y'),
                        'debit' => $rate,
                        'credit' => 0,
                    ];
                }
            } else {
                $entries[] = [
                    'date' => now()->toDateString(),
                    'desc' => "ROOM $roomNo × $nights NIGHT(S)",
                    'debit' => $rate * max(1, $nights),
                    'credit' => 0,
                ];
            }

            if ($serviceRp > 0) {
                $entries[] = [
                    'date' => $actualOut ? Carbon::parse($actualOut)->toDateString() : now()->toDateString(),
                    'desc' => 'SERVICE CHARGE',
                    'debit' => $serviceRp,
                    'credit' => 0,
                ];
            }
            if ($extraRp > 0) {
                $entries[] = [
                    'date' => $actualOut ? Carbon::parse($actualOut)->toDateString() : now()->toDateString(),
                    'desc' => 'EXTRA BED',
                    'debit' => $extraRp,
                    'credit' => 0,
                ];
            }
            if ($penaltyRp > 0) {
                $entries[] = [
                    'date' => $actualOut ? Carbon::parse($actualOut)->toDateString() : now()->toDateString(),
                    'desc' => 'LATE ARRIVAL PENALTY' . ($penaltyH ? ' (' . $penaltyH . ' h)' : ''),
                    'debit' => $penaltyRp,
                    'credit' => 0,
                ];
            }

            // discount sebagai credit (basic * nights * pct)
            if ($discPct > 0) {
                $discVal = round((($rate * $discPct) / 100) * max(1, $nights));
                if ($discVal > 0) {
                    $entries[] = [
                        'date' => $actualOut ? Carbon::parse($actualOut)->toDateString() : now()->toDateString(),
                        'desc' => "DISCOUNT $discPct%",
                        'debit' => 0,
                        'credit' => $discVal,
                    ];
                }
            }

        }

        // ===== MINIBAR (per ReservationGuest) =====
        $rgId = (int) ($row['id'] ?? ($row['reservation_guest_id'] ?? 0));
        if ($rgId > 0) {
            $minibarSum = (int) \App\Models\MinibarReceipt::query()
                ->where('reservation_guest_id', $rgId)
                ->sum('total_amount');

            if ($minibarSum > 0) {
                $entries[] = [
                    'date'   => $actualOut ? \Illuminate\Support\Carbon::parse($actualOut)->toDateString() : now()->toDateString(),
                    'desc'   => 'MINIBAR',
                    'debit'  => $minibarSum,
                    'credit' => 0,
                ];
            }
        }

        usort($entries, fn($a, $b) => strcmp($a['date'], $b['date']));
        $run = 0.0;
        $rows = [];
        foreach ($entries as $e) {
            $run += (float) $e['debit'] - (float) $e['credit'];
            $rows[] = [
                'date' => $d($e['date'] ?? null),
                'desc' => (string) ($e['desc'] ?? ''),
                'debit' => (float) ($e['debit'] ?? 0),
                'credit' => (float) ($e['credit'] ?? 0),
                'balance' => $run,
            ];
        }
        $sumD = array_sum(array_column($rows, 'debit'));
        $sumC = array_sum(array_column($rows, 'credit'));
        $bal = $run;
    @endphp

    <title>Guest Folio — {{ $invoiceNo ?? '#' . ($invoiceId ?? '-') }}</title>

    <style>
        /* === Page & base === */
        @page {
            size: {{ $paper }} {{ $orientation }};
            margin: 10mm;
        }

        body {
            margin: 0;
            padding: 0;
            font-family: DejaVu Sans, Arial, sans-serif;
            color: #111827;
            font-size: 10px;
        }

        /* === Header === */
        table.hdr {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }

        table.hdr td {
            vertical-align: top;
        }

        table.hdr .left {
            width: 35%;
        }

        table.hdr .mid {
            width: 30%;
            text-align: center;
        }

        table.hdr .right {
            width: 35%;
            text-align: right;
        }

        .logo img {
            height: 50px;
            object-fit: contain;
        }

        .title {
            font-size: 16px;
            font-weight: 700;
            text-decoration: underline;
        }

        .sub {
            font-weight: 600;
            margin-top: 2px;
        }

        .hotel-meta {
            font-size: 9px;
            line-height: 1.3;
        }

        /* === Info block === */
        table.info {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
            table-layout: fixed;
            /* aman untuk blok info */
        }

        table.info td {
            padding: 2px 0;
        }

        .lbl {
            color: #374151;
            font-weight: 600;
            white-space: nowrap;
        }

        .colon {
            width: 12px;
            text-align: center;
        }

        .line {
            border-top: 1px solid #1F2937;
            margin: 8px 0;
        }

        /* === FOLIO TABLE (anti pecah) === */
        table.grid {
            width: 100%;
            border-collapse: collapse;
            table-layout: auto;
            /* penting: biar kolom menyesuaikan */
            font-size: 9.5px;
        }

        table.grid thead th {
            border-top: 1px solid #1F2937;
            border-bottom: 1px solid #1F2937;
            padding: 5px 4px;
            font-weight: 600;
            white-space: nowrap;
        }

        table.grid td {
            border-bottom: 1px solid #D1D5DB;
            padding: 5px 4px;
            vertical-align: top;
            white-space: nowrap;
            /* default: semua 1 baris */
            word-break: keep-all;
            /* jangan pecah di tengah kata */
            hyphens: none;
        }

        /* kolom khusus */
        .col-date {
            width: 28mm;
        }

        .col-debit {
            width: 28mm;
            text-align: right;
        }

        .col-credit {
            width: 28mm;
            text-align: right;
        }

        .col-balance {
            width: 32mm;
            text-align: right;
        }

        .col-desc {
            white-space: normal;
            /* description boleh multi-baris */
            word-break: normal;
            /* wrap hanya di spasi */
            hyphens: manual;
        }

        /* utils */
        .center {
            text-align: center;
        }

        .right {
            text-align: right;
        }

        /* dipakai di beberapa tempat */
        .nowrap {
            white-space: nowrap;
        }

        /* === Totals box === */
        .totals {
            width: 100%;
            margin-top: 8px;
        }

        .totals table {
            margin-left: auto;
            width: 320px;
            border-collapse: collapse;
        }

        .totals td {
            padding: 4px 5px;
        }

        .k {
            width: 170px;
            color: #374151;
        }

        .v {
            width: 150px;
            text-align: right;
            font-weight: 600;
        }

        .topline {
            border-top: 1px solid #1F2937;
        }

        /* === Signature === */
        .sign {
            margin-top: 16px;
        }

        .sign table {
            width: 100%;
            border-collapse: collapse;
        }

        .sigbox {
            display: inline-block;
            min-width: 160px;
            text-align: center;
            margin-top: 20px;
        }

        .sigline {
            border-top: 1px solid #9CA3AF;
            margin-top: 26px;
            padding-top: 4px;
        }

        /* small helper */
        .note { font-size: 8px; color:#6b7280; line-height: 1.3; }
    </style>
</head>

<body>
    <table class="hdr">
        <tr>
            <td class="left">
                @if (!empty($logoData))
                    <span class="logo"><img src="{{ $logoData }}" alt="Logo"></span>
                @endif
            </td>
            <td class="mid">
                <div class="title">GUEST FOLIO</div>
                <div class="sub">{{ $invoiceNo ?? '#' . ($invoiceId ?? '-') }}</div>
                @if ($depositHeader > 0)
                    <div class="note" style="margin-top:2px">
                        Deposit: <strong>{{ $m($depositHeader) }}</strong>
                    </div>
                @endif
            </td>
            <td class="right">
                <div class="hotel-meta">{!! !empty($hotelRight) ? implode('<br>', array_map('e', $hotelRight)) : '&nbsp;' !!}</div>
            </td>
        </tr>
    </table>

    <table class="info">
        <tr>
            <td class="lbl" style="width:32mm">Guest</td>
            <td class="colon">:</td>
            <td>{{ $guestName }}</td>
            <td style="width:10mm"></td>
            <td class="lbl nowrap">Room No / Type</td>
            <td class="colon">:</td>
            <td class="nowrap">
                <nobr>{{ $roomNo }}</nobr> — <nobr>{{ $roomType }}</nobr>
            </td>

        </tr>
        <tr>
            <td class="lbl">Arrival</td>
            <td class="colon">:</td>
            <td>{{ $dt($actualIn) }}</td>
            <td></td>
            <td class="lbl">Departure</td>
            <td class="colon">:</td>
            <td>{{ $dt($actualOut) }}</td>
        </tr>
        <tr>
            <td class="lbl">Pax / Nights</td>
            <td class="colon">:</td>
            <td>
                {{ $pax }} pax /
                @if (!empty($row['nights_expected']) && $row['nights_expected'] !== $nights)
                    {{ $nights }} night(s) <span class="nowrap" style="color:#6b7280"> (booked
                        {{ (int) $row['nights_expected'] }})</span>
                @else
                    {{ $nights }} night(s)
                @endif
            </td>
            <td></td>
            <td class="lbl">Rate</td>
            <td class="colon">:</td>
            <td>
                <div>{{ $m($rate) }}</div>
                <div class="note">Rates exclude tax (if applicable).</div>
            </td>
        </tr>
    </table>

    <div class="line"></div>

    @php
        // Kompatibilitas nama variabel lama vs baru:
        $rowsForPrint = $rowsForPrint ?? ($rows ?? []);
        $money = $money ?? $m;

        // Jaga-jaga kalau sum/balance belum ada variabelnya:
        $sumD = $sumD ?? array_sum(array_column($rowsForPrint, 'debit'));
        $sumC = $sumC ?? array_sum(array_column($rowsForPrint, 'credit'));
        $bal = $bal ?? (count($rowsForPrint) ? end($rowsForPrint)['balance'] : 0);
    @endphp

    <table class="grid">
        <thead>
            <tr>
                <th class="col-date">DATE</th>
                <th class="col-desc">DESCRIPTION</th>
                <th class="col-debit">DEBIT</th>
                <th class="col-credit">CREDIT</th>
                <th class="col-balance">BALANCE</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td class="col-date">
                        <nobr>{{ $r['date'] }}</nobr>
                    </td>
                    <td class="col-desc">{{ $r['desc'] }}</td>
                    <td class="col-debit">{{ $r['debit'] ? $m($r['debit']) : '0' }}</td>
                    <td class="col-credit">{{ $r['credit'] ? $m($r['credit']) : '0' }}</td>
                    <td class="col-balance">{{ $m($r['balance']) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="center">No entries.</td>
                </tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2" class="right" style="font-weight:700">TOTAL</td>
                <td class="right" style="font-weight:700">{{ $m($sumD) }}</td>
                <td class="right" style="font-weight:700">{{ $m($sumC) }}</td>
                <td class="right" style="font-weight:700">{{ $m($bal) }}</td>
            </tr>
        </tfoot>
    </table>

    <div class="totals">
        <table>
            <tr>
                <td class="k">Debit</td>
                <td class="v">{{ $m($sumD) }}</td>
            </tr>
            <tr>
                <td class="k">Credit</td>
                <td class="v">{{ $m($sumC) }}</td>
            </tr>
            <tr class="topline">
                <td class="k" style="font-weight:700">Balance</td>
                <td class="v" style="font-weight:700">{{ $m($bal) }}</td>
            </tr>
        </table>
    </div>

    <div class="line"></div>

    <div class="sign">
        <table>
            <tr>
                <td>Page: 1</td>
                <td class="center">{{ $hotel?->city ? $hotel->city . ', ' : '' }}{{ $dt($generatedAt ?? now()) }}</td>
                <td class="right">&nbsp;</td>
            </tr>
            <tr>
                <td></td>
                <td class="center">
                    <div class="sigbox">
                        <div class="sigline"></div>
                        {{ $clerkName ?? ($reservation?->creator?->name ?? 'Reception/Cashier') }}
                    </div>
                </td>
                <td></td>
            </tr>
        </table>
    </div>
</body>

</html>
