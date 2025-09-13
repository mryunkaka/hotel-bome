{{-- resources/views/prints/reservations/print.blade.php --}}
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  @php
    $paper       = strtoupper($paper ?? 'A4');
    $orientation = in_array(strtolower($orientation ?? 'portrait'), ['portrait','landscape'], true) ? strtolower($orientation) : 'portrait';

    $fmtMoney = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
    $fmtDate  = fn($dt, $withTime = true) => $dt
        ? \Carbon\Carbon::parse($dt)->timezone('Asia/Singapore')->format($withTime ? 'd/m/Y H:i' : 'd/m/Y')
        : '';

    // ==== Build table rows (ROOM | CATEGORY | RATE | PS | GUEST NAME | EXP ARR | EXP DEPT) ====
    // Prefer $rows (kalau sudah dipass dari route). Jika tidak ada, coba bangun dari $items yang sudah ada.
    $rows = $rows ?? [];

    if (empty($rows) && !empty($items) && is_iterable($items)) {
        foreach ($items as $it) {
            $roomNo     = '-';
            $category   = null;
            $guestNm    = null;
            $expArr     = null;
            $expDept    = null;

            $itemName = $it['item_name'] ?? '';
            if (stripos($itemName, 'Room ') === 0) {
                $roomNo = trim(substr($itemName, 5));
            } elseif ($itemName) {
                $roomNo = $itemName;
            }

            $desc = (string)($it['description'] ?? '');
            // parse sederhana: "Category: ..., Guest: ..., EXP ARR: ..., EXP DEPT: ..."
            foreach (['Category:'=>'category','Guest:'=>'guest','EXP ARR:'=>'arr','EXP DEPT:'=>'dept'] as $needle => $key) {
                $pos = stripos($desc, $needle);
                if ($pos !== false) {
                    $seg = trim(substr($desc, $pos + strlen($needle)));
                    // potong di pemisah " · " jika ada
                    $seg = explode('·', $seg)[0] ?? $seg;
                    $seg = trim($seg);
                    if     ($key === 'category') $category = $seg;
                    elseif ($key === 'guest')    $guestNm  = $seg;
                    elseif ($key === 'arr')      $expArr   = $seg;
                    elseif ($key === 'dept')     $expDept  = $seg;
                }
            }

            $rows[] = [
                'room_no'   => $roomNo ?: '-',
                'category'  => $category ?: '-',
                'rate'      => (float)($it['unit_price'] ?? 0),
                'ps'        => $ps ?? null, // opsional: bisa diisi dari server kalau ada
                'guest'     => $guestNm ?: '-',
                'exp_arr'   => $expArr ?: '-',
                'exp_dept'  => $expDept ?: '-',
            ];
        }
    }

    // Deposit: pakai $deposit jika ada, kalau tidak pakai $paid_total
    $depositVal = isset($deposit) ? (float)$deposit : (float)($paid_total ?? 0);

    // Reserved by: gabungkan title + name jika tersedia
    $reservedTitle = $reserved_title ?? null; // misal 'Mr', 'Mrs'
    $reservedBy    = $reserved_by    ?? ($billTo['name'] ?? null);
    $reservedFull  = trim(($reservedTitle ? $reservedTitle.' ' : '').($reservedBy ?? ''));

    // Clerk / booked by
    $clerkName = $clerkName ?? ($clerk ?? null);

    // Hotel contact (kanan atas)
    $hotelRight = array_filter([
        $hotel?->address,
        ($hotel?->phone ? 'Phone  '.$hotel->phone : null),
        ($hotel?->whatsapp ? 'WhatsApp '.$hotel->whatsapp : null),
        ($hotel?->city ? ($hotel->city) : null),
    ]);

  @endphp
  <style>
    @page { size: {{ $paper }} {{ $orientation }}; margin: 12mm; }
    body { margin:0; padding:0; font-family: DejaVu Sans, Arial, sans-serif; font-size:10px; color:#111827; line-height:1.35; }

    /* Header: logo/name left, title center, hotel info right */
    .hdr-table { width:100%; border-collapse:collapse; margin-bottom:10px; }
    .hdr-td { vertical-align:top; }
    .hdr-left  { width:35%; }
    .hdr-mid   { width:30%; text-align:center; }
    .hdr-right { width:35%; text-align:right; }
    .brand-name { font-size:18px; font-weight:700; letter-spacing:0.5px; }
    .logo { display:inline-block; vertical-align:middle; margin-right:6px; }
    .logo img { width:80px; object-fit:contain; }
    .hotel-meta { color:#111827; font-size:9px; line-height:1.35; }

    .title { font-size:16px; font-weight:700; text-decoration: underline; }
    .resv-no { margin-top:4px; font-weight:600; letter-spacing:0.2px; }

    /* Key-Value grid under header */
    .kv-table { width:100%; border-collapse:collapse; margin:10px 0 8px; }
    .kv-td { padding:2px 0; }
    .k { color:#374151; width:110px; display:inline-block; }
    .v { color:#111827; font-weight:600; }

    /* Divider line */
    .line { border-top:1.5px solid #1F2937; margin:8px 0; }

    /* Items table */
    table.items { width:100%; border-collapse:collapse; font-size:10px; }
    .items thead th { border-top:1.5px solid #1F2937; border-bottom:1px solid #1F2937; padding:6px; text-align:left; }
    .items td { border-bottom:1px solid #D1D5DB; padding:6px; }
    .center { text-align:center; }
    .right  { text-align:right; }
    .narrow { width:48px; white-space:nowrap; }

    /* Additional info */
    .add-info-title { margin:10px 0 6px; font-weight:700; text-decoration:underline; }
    .two-col { width:100%; border-collapse:collapse; }
    .two-col td { vertical-align:top; padding:2px 0; }
    .two-col .left  { width:50%; padding-right:12px; }
    .two-col .right { width:50%; padding-left:12px; }
    .kv-narrow .k { width:95px; }

    /* Footer */
    .footer { margin-top:16px; }
    .foot-table { width:100%; border-collapse:collapse; }
    .foot-left  { text-align:left;  }
    .foot-mid   { text-align:center; }
    .foot-right { text-align:right; }
    .sig-block { margin-top:26px; display:inline-block; min-width:160px; text-align:center; }
    .sig-line { margin-top:38px; border-top:1px solid #9CA3AF; }

    /* Key–Value helper (3 kolom: key | : | value) */
    .kv2{width:100%;border-collapse:collapse}
    .kv2 td{padding:2px 0;vertical-align:top;font-size:10px}
    .kv2 .key{width:120px;white-space:nowrap}
    .kv2 .colon{width:10px;text-align:center}
    .kv2 .val{}

    /* Right-side block: dorong ke kanan tapi tetap pakai kv2 di dalamnya */
    .kv-right{max-width:340px;margin-left:auto}
  </style>
</head>
<body>

  {{-- ===== HEADER ===== --}}
  <table class="hdr-table">
    <tr>
      <td class="hdr-td hdr-left">
        <div>
          @if(!empty($logoData))
            <span class="logo"><img src="{{ $logoData }}" alt="Logo"></span>
          @endif
        </div>
      </td>
      <td class="hdr-td hdr-mid">
        <div class="title">{{ $title ?? 'RESERVATION' }}</div>
        <div class="resv-no">RESV NO: {{ $invoiceNo ?? ('#'.($invoiceId ?? '-')) }}</div>
      </td>
      <td class="hdr-td hdr-right">
        <div class="hotel-meta">
          @if(!empty($hotelRight))
            {!! implode('<br>', array_map('e', $hotelRight)) !!}
            @if($hotel?->postcode) <br>{{ e($hotel->postcode) }} @endif
          @else
            &nbsp;
          @endif
        </div>
      </td>
    </tr>
  </table>

  {{-- ===== TOP INFO (2 kolom) ===== --}}
  <table class="kv-table">
    <tr>
      <td class="kv-td" style="width:70%;">
        <div><span class="k">Metode</span><span class="v">: {{ ucfirst($payment['method'] ?? ($method ?? '-')) }}</span></div>
        <div><span class="k">Status</span><span class="v">: {{ $status ?? 'CONFIRM' }}</span></div>
        <div><span class="k">Reserved By</span><span class="v">: {{ $reservedFull ?: '-' }}</span></div>
      </td>
      <td class="kv-td" style="width:30%">
        <div><span class="k">Expected Arrival</span><span class="v">: {{ $fmtDate($expected_arrival ?? ($bookingDates['arrival'] ?? null), true) }}</span></div>
        <div><span class="k">Expected Departure</span><span class="v">: {{ $fmtDate($expected_departure ?? ($bookingDates['departure'] ?? null), true) }}</span></div>
        <div><span class="k">Deposit</span><span class="v">: {{ $fmtMoney($depositVal) }}</span></div>
      </td>
    </tr>
  </table>

  <div class="line"></div>

  {{-- ===== ITEMS TABLE (ROOM LIST) ===== --}}
  <table class="items">
    <thead>
      <tr>
        <th class="narrow">ROOM</th>
        <th>CATEGORY</th>
        <th class="right">RATE</th>
        <th class="center narrow">PS</th>
        <th>GUEST NAME</th>
        <th class="center narrow">EXP ARR</th>
        <th class="center narrow">EXP DEPT</th>
      </tr>
    </thead>
    <tbody>
      @forelse($rows as $r)
        <tr>
          <td>{{ $r['room_no'] ?? '-' }}</td>
          <td>{{ $r['category'] ?? '-' }}</td>
          <td class="right">{{ $fmtMoney($r['rate'] ?? 0) }}</td>
          <td class="center">{{ $r['ps'] ?? '1' }}</td>
          <td>{{ $r['guest_display'] ?? ($billTo['name'] ?? '-') }}</td>
          <td class="center">
            @php
                $arrRaw = $r['exp_arr'] ?? null;

                if (is_string($arrRaw) && preg_match('/^\d{2}\/\d{2}\/\d{4}/', $arrRaw)) {
                    // Sudah string formatted (dd/mm/yyyy [HH:MM]), tampilkan apa adanya
                    echo e($arrRaw);
                } else {
                    // Carbon/DateTime atau null → format dengan JAM
                    echo e($fmtDate($arrRaw ?: ($expected_arrival ?? null), true));
                }
            @endphp
            </td>
            <td class="center">
            @php
                $depRaw = $r['exp_dept'] ?? null;

                if (is_string($depRaw) && preg_match('/^\d{2}\/\d{2}\/\d{4}/', $depRaw)) {
                    echo e($depRaw);
                } else {
                    echo e($fmtDate($depRaw ?: ($expected_departure ?? null), true));
                }
            @endphp
            </td>

        </tr>
      @empty
        <tr><td colspan="7" class="center">No rooms</td></tr>
      @endforelse
    </tbody>
  </table>

  {{-- ===== ADDITIONAL INFORMATION ===== --}}
  <div class="add-info-title">Additional Information :</div>
    <table class="two-col kv-narrow">
    <tr>
        <td class="left" style="width:70%">
        <div><span class="k">{{ $billTo['input'] ?? 'Company Name' }}</span><span class="v">: {{ $companyName ?? '-' }}</span></div>
        <div><span class="k">Address</span><span class="v">: {{ $billTo['address'] ?? '-' }}</span></div>
        <div><span class="k">City</span><span class="v">: {{ $billTo['city'] ?? ($hotel?->city ?? '-') }}</span></div>
        <div><span class="k">Phone</span><span class="v">: {{ $billTo['phone'] ?? '-' }}</span></div>
        <div><span class="k">Handphone</span><span class="v">: {{ $billTo['mobile'] ?? ($billTo['phone'] ?? '-') }}</span></div>
        <div><span class="k">Email</span><span class="v">: {{ $billTo['email'] ?? '-' }}</span></div>
        </td>
        <td class="left" style="width:30%">
        <div><span class="k">Faximile</span><span class="v">: {{ $fax ?? '-' }}</span></div>
        <div><span class="k">Clerk/Booked By</span><span class="v">: {{ $clerkName ?? '-' }}</span></div>
        <div><span class="k">Entry Date</span><span class="v">: {{ $fmtDate($issuedAt ?? $generatedAt ?? now(), false) }}</span></div>
        <div><span class="k">Remarks</span><span class="v">: {{ $notes ?? '-' }}</span></div>
        </td>
    </tr>
    </table>


  {{-- ===== FOOTER / SIGNATURE ===== --}}
    <div class="line"></div>
  <div class="footer">
    <table class="foot-table">
      <tr>
        <td class="foot-left">Page&nbsp;&nbsp;: 1</td>
        <td class="foot-mid">
          {{ $hotel?->city ? $hotel->city.' , ' : '' }}
          {{ $fmtDate($generatedAt ?? now(), false) }} -
          Reception/Cashier
        </td>
        <td class="foot-right">&nbsp;</td>
      </tr>
      <tr>
        <td class="foot-left"></td>
        <td class="foot-mid">
          <div class="sig-block">
            <div class="sig-line">&nbsp;</div>
            {{ $clerkName ?? ($reservedBy ?? ' ' ) }}
          </div>
        </td>
        <td class="foot-right"></td>
      </tr>
    </table>
  </div>

</body>
</html>
