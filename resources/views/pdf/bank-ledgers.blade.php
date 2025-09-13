<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <style>
    @page { margin: 24mm 16mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111827; }
    .brand { width: 100%; display: table; }
    .brand .col { display: table-cell; vertical-align: middle; }
    .brand .left { width: 90px; }
    .brand .right { padding-left: 12px; }
    .hotel-name { font-size: 18px; font-weight: bold; margin: 0; }
    .meta { margin: 2px 0; line-height: 1.35; }
    .logo { max-height: 70px; max-width: 90px; }
    .divider { border-top: 2px solid #111; margin: 8px 0 14px 0; }
    h3.title { margin: 0 0 8px 0; font-size: 16px; }
    .small { font-size: 11px; color: #4b5563; }
    table { width:100%; border-collapse: collapse; }
    th, td { border:1px solid #ddd; padding:6px; text-align:left; }
    th { background:#f3f4f6; font-weight: 600; }
    tfoot td { font-weight: 600; background: #fafafa; }
    .text-right { text-align:right; }
    .text-center { text-align:center; }
    .muted { color:#6b7280; }
  </style>
</head>
<body>

  <div class="brand">
    <div class="col left">
      @if($logoData)
        <img class="logo" src="{{ $logoData }}" alt="Logo">
      @endif
    </div>
    <div class="col right">
      <p class="hotel-name">{{ $hotel->name ?? 'Hotel' }}</p>
      @if($hotel?->address)
        <div class="meta">{{ $hotel->address }}</div>
      @endif
      @if($hotel?->phone || $hotel?->email)
        <div class="meta">
          @if($hotel?->phone) Tel: {{ $hotel->phone }} @endif
          @if($hotel?->phone && $hotel?->email) &nbsp;|&nbsp; @endif
          @if($hotel?->email) Email: {{ $hotel->email }} @endif
        </div>
      @endif
    </div>
  </div>
  <div class="divider"></div>

  <h3 class="title">Bank Ledgers</h3>
  <div class="small">Dicetak: {{ $generatedAt->timezone('Asia/Singapore')->format('Y-m-d H:i') }}</div>
  <br>

  <table>
    <thead>
      <tr>
        <th class="text-center" style="width:40px;">#</th>
        <th style="width:140px;">Bank</th>
        <th class="text-right" style="width:120px;">Deposit</th>
        <th class="text-right" style="width:120px;">Withdraw</th>
        <th style="width:100px;">Date</th>
        <th>Description</th>
      </tr>
    </thead>
    <tbody>
      @php $i = 1; @endphp
      @forelse($rows as $r)
        <tr>
          <td class="text-center">{{ $i++ }}</td>
          <td>{{ optional($r->bank)->name }}</td>
          <td class="text-right">{{ number_format((float)$r->deposit, 2) }}</td>
          <td class="text-right">{{ number_format((float)$r->withdraw, 2) }}</td>
          <td>{{ optional($r->date)->format('Y-m-d') }}</td>
          <td>{{ $r->description }}</td>
        </tr>
      @empty
        <tr><td colspan="6" class="text-center muted">Tidak ada data.</td></tr>
      @endforelse
    </tbody>
    <tfoot>
      <tr>
        <td colspan="2" class="text-right">Subtotal</td>
        <td class="text-right">{{ number_format((float)$totalDeposit, 2) }}</td>
        <td class="text-right">{{ number_format((float)$totalWithdraw, 2) }}</td>
        <td colspan="2"></td>
      </tr>
    </tfoot>
  </table>

</body>
</html>
