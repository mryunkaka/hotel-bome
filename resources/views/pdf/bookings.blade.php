<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <style>
    @page { size: A4 portrait; margin: 16mm 12mm; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111827; }

    .brand { width: 100%; overflow: hidden; margin-bottom: 8px; }
    .brand-left { float: left; width: 80px; }
    .brand-left img { max-width: 70px; max-height: 70px; display: block; }
    .brand-right { margin-left: 95px; }
    .hotel-name { font-size: 18px; font-weight: bold; margin: 0 0 2px 0; }
    .meta { margin: 2px 0; line-height: 1.35; }
    .divider { clear: both; border-top: 2px solid #111; margin: 8px 0 12px 0; }

    h3.title { margin: 0 0 6px 0; font-size: 16px; }
    .small { font-size: 11px; color: #4b5563; }

    table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    th, td { border: 1px solid #ddd; padding: 6px; text-align: left; vertical-align: top; }
    th { background: #f3f4f6; font-weight: 600; }
    th, td { overflow-wrap: anywhere; word-break: break-word; }
    .text-center { text-align: center; }

    .col-idx { width: 22px; }
    .col-room { width: 120px; }
    .col-guest { width: 140px; }
    .col-in { width: 110px; }
    .col-out { width: 110px; }
    .col-status { width: 90px; }
    /* notes fleksibel */

    tbody tr { page-break-inside: avoid; }
  </style>
</head>
<body>

  <div class="brand">
    <div class="brand-left">
      @if($logoData)
        <img src="{{ $logoData }}" alt="Logo">
      @endif
    </div>
    <div class="brand-right">
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

  <h3 class="title">Bookings</h3>
  <div class="small">Dicetak: {{ $generatedAt->timezone('Asia/Singapore')->format('Y-m-d H:i') }}</div>
  <br>

  <table>
    <thead>
      <tr>
        <th class="text-center col-idx">#</th>
        <th class="col-room">Room</th>
        <th class="col-guest">Guest</th>
        <th class="col-in">Check-in</th>
        <th class="col-out">Check-out</th>
        <th class="col-status">Status</th>
        <th>Notes</th>
      </tr>
    </thead>
    <tbody>
      @php $i = 1; @endphp
      @forelse($rows as $r)
        <tr>
          <td class="text-center">{{ $i++ }}</td>
          <td>{{ optional($r->room)->room_no ?? optional($r->room)->number }}</td>
          <td>{{ optional($r->guest)->name ?? optional($r->guest)->email }}</td>
          <td>{{ $r->check_in_at?->timezone('Asia/Singapore')?->format('Y-m-d H:i') }}</td>
          <td>{{ $r->check_out_at?->timezone('Asia/Singapore')?->format('Y-m-d H:i') }}</td>
          <td>{{ $r->status }}</td>
          <td>{{ $r->notes }}</td>
        </tr>
      @empty
        <tr><td colspan="7" class="text-center">Tidak ada data.</td></tr>
      @endforelse
    </tbody>
  </table>

</body>
</html>
