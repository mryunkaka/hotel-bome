<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  @php
    $paper       = strtoupper($paper ?? 'A4');
    $orientation = strtolower($orientation ?? 'portrait');
    if (! in_array($orientation, ['portrait','landscape'])) $orientation = 'portrait';

    $fmtMoney = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
    $fmtDt    = fn($dt) => $dt ? $dt->timezone('Asia/Singapore')->format('Y-m-d H:i') . ' SGT' : '';
  @endphp
  <style>
    @page { size: {{ $paper }} {{ $orientation }}; margin: 12mm; }
    body { margin:0; padding:0; font-family: DejaVu Sans, Arial, sans-serif; font-size:10px; color:#111827; line-height:1.35; }

    /* Header Brand */
    .brand { display: table; width:100%; border-bottom:2px solid #111827; padding-bottom:8px; margin-bottom:12px; }
    .brand .logo { display: table-cell; width:70px; vertical-align:top; }
    .brand .logo img { width:60px; height:60px; object-fit:contain; border:0; }
    .brand .info { display: table-cell; vertical-align:top; padding-left:10px; }
    .brand .name { font-size:18px; font-weight:700; margin:0 0 2px 0; }
    .brand .meta { margin:2px 0; color:#6B7280; font-size:9px; }

    /* Invoice header */
    .inv-head { display: table; width:100%; margin-bottom:12px; }
    .inv-left, .inv-right { display: table-cell; vertical-align:top; }
    .inv-right { width:42%; }
    .h3 { font-size:16px; font-weight:700; margin:0 0 6px 0; }
    .block { border:1px solid #E5E7EB; padding:8px; border-radius:6px; }
    .kv { margin:2px 0; }
    .kv .k { width:90px; display:inline-block; color:#6B7280; }
    .kv .v { color:#111827; }

    /* ===== Info grid (4 kolom sinkron) ===== */
    .info-wrap { margin-bottom: 10px; }
    .info-table { width:100%; border-collapse: separate; border-spacing:0; }
    .info-table th, .info-table td { font-size:10px; padding:7px 8px; vertical-align:top; }
    .info-table .cell { border:1px solid #E5E7EB; }
    .info-table .corner-l { border-radius: 6px 0 0 6px; }
    .info-table .corner-r { border-radius: 0 6px 6px 0; }
    .info-k { width:85px; color:#6B7280; white-space:nowrap; }
    .info-v { color:#111827; }
    .info-split { width:20px; border:none; } /* jarak antar blok kiri–kanan */

    /* Items table */
    table.items { width:100%; border-collapse:collapse; font-size:10px; }
    .items thead th { background:#374151; color:#fff; font-weight:700; text-align:left; border:1px solid #D1D5DB; padding:6px; }
    .items td { border:1px solid #D1D5DB; padding:6px; }
    .items tbody tr:nth-child(odd){ background:#F9FAFB; }
    .center { text-align:center; }
    .right { text-align:right; }
    .nowrap { white-space:nowrap; }

    /* Totals */
    .totals { width:100%; margin-top:12px; }
    .totals .left { width:60%; vertical-align:top; }
    .totals .right { width:40%; vertical-align:top; }
    .tot-table { width:100%; border-collapse:collapse; }
    .tot-table td { padding:6px 5px; font-size:10px; }
    .tot-table .k { color:#6B7280; }
    .tot-table .v { text-align:right; }
    .grand { font-weight:700; border-top:2px solid #111827; padding-top:6px; }

    /* Footer */
    .foot { margin-top:14px; color:#6B7280; font-size:9px; }
    .sig-wrap { margin-top:30px; display:table; width:100%; }
    .sig { display:table-cell; width:33%; text-align:center; vertical-align:bottom; }
    .sig .line { margin-top:40px; border-top:1px solid #9CA3AF; }
  </style>
</head>
<body>

  {{-- ===== Brand header ===== --}}
  <div class="brand">
    <div class="logo">
      @if(!empty($logoData))
        <img src="{{ $logoData }}" alt="Logo">
      @endif
    </div>
    <div class="info">
      <div class="name">{{ $hotel->name ?? 'Hotel' }}</div>
      @if($hotel?->address)<div class="meta">Address: {{ $hotel->address }}</div>@endif
      @if($hotel?->phone || $hotel?->email)
        <div class="meta">
          @if($hotel?->phone) Phone: {{ $hotel->phone }} @endif
          @if($hotel?->phone && $hotel?->email) | @endif
          @if($hotel?->email) Email: {{ $hotel->email }} @endif
        </div>
      @endif
    </div>
  </div>

  {{-- ===== Invoice header ===== --}}
  {{-- ===== Invoice header (4 kolom sinkron) ===== --}}
<div class="info-wrap">
  <table class="info-table">
    <colgroup>
      <col style="width: 95px;">  {{-- kunci kiri --}}
      <col>                       {{-- nilai kiri --}}
      <col class="info-split">    {{-- jarak --}}
      <col style="width: 95px;">  {{-- kunci kanan --}}
      <col style="width: 38%;">   {{-- nilai kanan (lebar tetap agar seimbang) --}}
    </colgroup>
    <tbody>
      <!-- Judul di atas tabel, tetap pakai h3 -->
      <tr>
        <td colspan="5" style="border:none; padding:0 0 6px 0;">
          <div class="h3">{{ $title ?? 'INVOICE' }}</div>
        </td>
      </tr>

      {{-- Baris 1: Bill To ↔ Invoice No --}}
      <tr>
        <td class="cell corner-l info-k">Bill To</td>
        <td class="cell info-v">
          @if(!empty($billTo))
            {{ $billTo['name'] ?? '-' }}<br>
            @if(!empty($billTo['address']))<span class="muted">{{ $billTo['address'] }}</span><br>@endif
            @if(!empty($billTo['phone']) || !empty($billTo['email']))
              <span class="muted">
                {{ $billTo['phone'] ?? '' }}@if(!empty($billTo['phone']) && !empty($billTo['email'])) | @endif{{ $billTo['email'] ?? '' }}
              </span>
            @endif
          @else
            -
          @endif
        </td>

        <td class="info-split"></td>

        <td class="cell info-k">Invoice No</td>
        <td class="cell corner-r info-v">{{ $invoiceNo ?? ('#'.($invoiceId ?? '-')) }}</td>
      </tr>

      {{-- Baris 2: Booking ↔ Date --}}
      <tr>
        <td class="cell info-k">Booking</td>
        <td class="cell info-v">
          @if(!empty($booking))
            Room {{ $booking['room_no'] ?? '-' }} — {{ $booking['guest'] ?? '-' }}
          @else
            -
          @endif
        </td>

        <td class="info-split"></td>

        <td class="cell info-k">Date</td>
        <td class="cell info-v">
          @php $fmtDt = fn($dt) => $dt ? $dt->timezone('Asia/Singapore')->format('Y-m-d H:i') . ' SGT' : ''; @endphp
          {{ $fmtDt($issuedAt ?? $generatedAt ?? null) }}
        </td>
      </tr>

      {{-- Baris 3: Period ↔ Payment --}}
      <tr>
        <td class="cell info-k">Period</td>
        <td class="cell info-v">
          @if(!empty($booking['period'])) {{ $booking['period'] }} @else - @endif
        </td>

        <td class="info-split"></td>

        <td class="cell info-k">Payment</td>
        <td class="cell info-v">
          {{ !empty($payment['method']) ? ucfirst($payment['method']) : '-' }}
          @if(!empty($payment['ref'])) <span class="muted"> · Ref: {{ $payment['ref'] }}</span> @endif
        </td>
      </tr>
    </tbody>
  </table>
</div>


  {{-- ===== Items table ===== --}}
  <table class="items">
    <thead>
      <tr>
        <th class="center narrow">#</th>
        <th>Item</th>
        <th>Description</th>
        <th class="right">Qty</th>
        <th class="right">Unit Price</th>
        <th class="right">Amount</th>
      </tr>
    </thead>
    <tbody>
      @forelse($items ?? [] as $i => $it)
        <tr>
          <td class="center">{{ $i + 1 }}</td>
          <td>{{ $it['item_name'] ?? '-' }}</td>
          <td>{{ $it['description'] ?? '' }}</td>
          <td class="right">{{ number_format((float)($it['qty'] ?? 0), 2, ',', '.') }}</td>
          <td class="right">{{ $fmtMoney((float)($it['unit_price'] ?? 0)) }}</td>
          <td class="right">{{ $fmtMoney((float)($it['amount'] ?? 0)) }}</td>
        </tr>
      @empty
        <tr><td colspan="6" class="center muted">No items</td></tr>
      @endforelse
    </tbody>
  </table>

  {{-- ===== Totals ===== --}}
  <table class="totals">
    <tr>
      <td class="left">
        @if(!empty($notes))
          <div class="block">
            <div style="font-weight:700; margin-bottom:4px;">Notes</div>
            <div>{{ $notes }}</div>
          </div>
        @endif
      </td>
      <td class="right">
        <table class="tot-table">
          <tr><td class="k">Subtotal</td><td class="v">{{ $fmtMoney($subtotal ?? 0) }}</td></tr>
          @if(isset($tax_name) || isset($tax_percent))
            <tr><td class="k">{{ $tax_name ?? 'Tax' }}{{ isset($tax_percent) ? " ({$tax_percent}%)" : '' }}</td>
                <td class="v">{{ $fmtMoney($tax_total ?? 0) }}</td></tr>
          @endif
          <tr><td class="k grand">Total</td><td class="v grand">{{ $fmtMoney($total ?? 0) }}</td></tr>
          @if(!empty($paid_total))
            <tr><td class="k">Paid</td><td class="v">{{ $fmtMoney($paid_total) }}</td></tr>
            <tr><td class="k">Balance</td><td class="v">{{ $fmtMoney(($total ?? 0) - $paid_total) }}</td></tr>
          @endif
        </table>
      </td>
    </tr>
  </table>

  {{-- ===== Footer ===== --}}
  <div class="foot">
    {{ $footerText ?? 'Thank you for your business.' }}
    @if(!empty($generatedAt))
      <div class="muted">Generated: {{ $generatedAt->timezone('Asia/Singapore')->format('Y-m-d H:i') }} SGT</div>
    @endif
  </div>

  {{-- Signature optional --}}
  @if(!empty($showSignature))
    <div class="sig-wrap">
      <div class="sig"><div>Issued By</div><div class="line">&nbsp;</div></div>
      <div class="sig"></div>
      <div class="sig"><div>Received By</div><div class="line">&nbsp;</div></div>
    </div>
  @endif

</body>
</html>
