{{-- resources/views/prints/reservations/print.blade.php --}}
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  @php
      use App\Support\ReservationView;
      use App\Support\ReservationMath;

      // (opsional) paper & orientation tetap di blade
      $paper       = strtoupper($paper ?? 'A4');
      $orientation = in_array(strtolower($orientation ?? 'portrait'), ['portrait','landscape'], true) ? strtolower($orientation) : 'portrait';

      // Siapkan semua variabel untuk print
      $prepared = ReservationView::prepareForPrint([
          'rows'           => $rows ?? null,
          'items'          => $items ?? null,
          'ps'             => $ps    ?? null,
          'tax_lookup'     => $taxLookup ?? null,
          'deposit'        => $deposit ?? null,
          'paid_total'     => $paid_total ?? null,
          'reserved_title' => $reserved_title ?? null,
          'reserved_by'    => $reserved_by ?? null,
          'billTo'         => $billTo ?? [],
          'hotel'          => $hotel ?? null,
          'clerkName'      => $clerkName ?? null,
          'clerk'          => $clerk ?? null,
      ]);

      // Unpack
      $rows         = $prepared['rows'];
      $taxLookup    = $prepared['taxLookup'];
      $depositVal   = $prepared['depositVal'];
      $reservedFull = $prepared['reservedFull'];
      $clerkName    = $prepared['clerkName'];
      $hotelRight   = $prepared['hotelRight'];
  @endphp


  <style>
    @page { size: {{ $paper }} {{ $orientation }}; margin: 12mm; }
    body { margin:0; padding:0; font-family: DejaVu Sans, Arial, sans-serif; font-size:10px; color:#111827; line-height:1.35; }
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
    .kv-table { width:100%; border-collapse:collapse; margin:10px 0 8px; }
    .kv-td { padding:2px 0; }
    .k { color:#374151; width:110px; display:inline-block; }
    .v { color:#111827; font-weight:600; }
    .line { border-top:1.5px solid #1F2937; margin:8px 0; }
    table.items { width:100%; border-collapse:collapse; font-size:10px; }
    .items thead th { border-top:1.5px solid #1F2937; border-bottom:1px solid #1F2937; padding:6px; text-align:left; }
    .items td { border-bottom:1px solid #D1D5DB; padding:6px; }
    .center { text-align:center; }
    .right  { text-align:right; }
    .narrow { width:48px; white-space:nowrap; }
    .add-info-title { margin:10px 0 6px; font-weight:700; text-decoration:underline; }
    .two-col { width:100%; border-collapse:collapse; }
    .two-col td { vertical-align:top; padding:2px 0; }
    .two-col .left  { width:50%; padding-right:12px; }
    .two-col .right { width:50%; padding-left:12px; }
    .kv-narrow .k { width:95px; }
    .footer { margin-top:16px; }
    .foot-table { width:100%; border-collapse:collapse; }
    .foot-left  { text-align:left;  }
    .foot-mid   { text-align:center; }
    .foot-right { text-align:right; }
    .sig-block { margin-top:26px; display:inline-block; min-width:160px; text-align:center; }
    .sig-line { margin-top:38px; border-top:1px solid #9CA3AF; }
    .kv2{width:100%;border-collapse:collapse}
    .kv2 td{padding:2px 0;vertical-align:top;font-size:10px}
    .kv2 .key{width:120px;white-space:nowrap}
    .kv2 .colon{width:10px;text-align:center}
    .kv2 .val{}
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
        <div><span class="k">Reserved By</span><span class="v">: {{ $companyName ?: '-' }}</span></div>
      </td>
      <td class="kv-td" style="width:30%">
        <div><span class="k">Expected Arrival</span><span class="v">: {{ \App\Support\ReservationView::fmtDate($expected_arrival ?? ($bookingDates['arrival'] ?? null), true) }}</span></div>
        <div><span class="k">Expected Departure</span><span class="v">: {{ \App\Support\ReservationView::fmtDate($expected_departure ?? ($bookingDates['departure'] ?? null), true) }}</span></div>
        <div><span class="k">Deposit</span><span class="v">: {{ \App\Support\ReservationView::fmtMoney($depositVal) }}</span></div>
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
          {{-- <td class="right">{{ $fmtMoney($calcFinalRate($r)) }}</td> --}}
          {{-- <td class="right">{{ $fmtMoney($calcFinalRate($r)) }}</td> --}}
          <td class="right">
            {{ \App\Support\ReservationView::fmtMoney(
                ReservationMath::calcFinalRate($r, [
                    'tax_lookup'      => $taxLookup,
                    'extra_bed_price' => 100000,
                    'service_taxable' => false,
                ])
            ) }}
          </td>
          <td class="center">{{ $r['ps'] ?? '1' }}</td>
          <td>{{ $r['guest_display'] ?? ($billTo['name'] ?? '-') }}</td>
          <td class="center">
            {{ \App\Support\ReservationView::displayDateFlexible($r['exp_arr'] ?? null, $expected_arrival ?? null, true) }}
          </td>
          <td class="center">
            {{ \App\Support\ReservationView::displayDateFlexible($r['exp_dept'] ?? null, $expected_departure ?? null, true) }}
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
        <div><span class="k">Entry Date</span><span class="v">: {{ \App\Support\ReservationView::fmtDate($issuedAt ?? $generatedAt ?? now(), false) }}</span></div>
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
          {{ \App\Support\ReservationView::fmtDate($generatedAt ?? now(), false) }} -
          Reception/Cashier
        </td>
        <td class="foot-right">&nbsp;</td>
      </tr>
      <tr>
        <td class="foot-left"></td>
        <td class="foot-mid">
          <div class="sig-block">
            <div class="sig-line">&nbsp;</div>
            {{ $clerkName ?? ($reservedFull ?? ' ' ) }}
          </div>
        </td>
        <td class="foot-right"></td>
      </tr>
    </table>
  </div>

</body>
</html>
