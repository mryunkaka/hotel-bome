<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 12px; }
    .brand { width: 100%; display: table; }
    .brand .col { display: table-cell; vertical-align: middle; }
    .brand .left { width: 90px; }
    .brand .right { padding-left: 12px; }
    .hotel-name { font-size: 18px; font-weight: bold; margin: 0; }
    .meta { font-size: 12px; margin: 2px 0; }
    .logo { max-height: 70px; max-width: 90px; }
    .divider { border-top: 2px solid #000; margin: 8px 0 14px 0; }
    table { width:100%; border-collapse: collapse; }
    th, td { border:1px solid #ddd; padding:6px; text-align:left; }
    th { background:#f3f4f6; }
    tfoot td { font-weight: bold; background: #fafafa; }
    .text-right { text-align:right; }
    .text-center { text-align:center; }
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
          @if($hotel?->phone && $hotel?->email) | @endif
          @if($hotel?->email) Email: {{ $hotel->email }} @endif
        </div>
      @endif
    </div>
  </div>
  <div class="divider"></div>

  <h3>Account Ledgers</h3>
  <p style="font-size:11px; color:#555;">Dicetak: {{ $generatedAt->format('Y-m-d H:i') }}</p>

  <table>
    <thead>
      <tr>
        <th class="text-center" style="width:40px;">#</th>
        <th class="text-right" style="width:120px;">Debit</th>
        <th class="text-right" style="width:120px;">Credit</th>
        <th style="width:100px;">Date</th>
        <th style="width:110px;">Method</th>
        <th>Description</th>
      </tr>
    </thead>
    <tbody>
      @php $i=1; @endphp
      @forelse($rows as $r)
        <tr>
          <td class="text-center">{{ $i++ }}</td>
          <td class="text-right">{{ number_format($r->debit, 2) }}</td>
          <td class="text-right">{{ number_format($r->credit, 2) }}</td>
          <td>{{ optional($r->date)->format('Y-m-d') }}</td>
          <td>{{ $r->method }}</td>
          <td>{{ $r->description }}</td>
        </tr>
      @empty
        <tr><td colspan="6" class="text-center">Tidak ada data</td></tr>
      @endforelse
    </tbody>
    <tfoot>
      <tr>
        <td colspan="1" class="text-right">Subtotal</td>
        <td class="text-right">{{ number_format($totalDebit,2) }}</td>
        <td class="text-right">{{ number_format($totalCredit,2) }}</td>
        <td colspan="3"></td>
      </tr>
    </tfoot>
  </table>

</body>
</html>
