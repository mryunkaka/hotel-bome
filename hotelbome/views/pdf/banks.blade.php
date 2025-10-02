<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <style>
  @page { size: A4 portrait; margin: 16mm 12mm; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111827; }

  /* Brand header pakai block + float agar aman di Dompdf */
  .brand { width: 100%; overflow: hidden; margin-bottom: 8px; }
  .brand-left { float: left; width: 80px; }
  .brand-left img { max-width: 70px; max-height: 70px; display: block; }
  .brand-right { margin-left: 95px; } /* 80 + margin buffer */
  .hotel-name { font-size: 18px; font-weight: bold; margin: 0 0 2px 0; }
  .meta { margin: 2px 0; line-height: 1.35; }
  .divider { clear: both; border-top: 2px solid #111; margin: 8px 0 12px 0; }

  h3.title { margin: 0 0 6px 0; font-size: 16px; }
  .small { font-size: 11px; color: #4b5563; }

  /* Tabel */
  table { width: 100%; border-collapse: collapse; table-layout: fixed; }
  th, td { border: 1px solid #ddd; padding: 6px; font-size: 12px; vertical-align: top; }
  th { background: #f3f4f6; font-weight: 600; }
  th, td { word-break: break-word; }

  .col-idx   { width: 18px; }
  .col-name  { width: 150px; }
  .col-branch{ width: 120px; }
  .col-acc   { width: 150px; }
  .col-phone { width: 110px; }
  .col-email { width: 170px; }

  .px-tight { padding-left: 2px !important; padding-right: 2px !important; }
  .text-center { text-align: center; }
  tbody tr { page-break-inside: avoid; }
</style>

</head>
<body>

  {{-- ===== Header brand pakai table + colgroup ===== --}}
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


  <h3 class="title">Banks</h3>
  <div class="small">Dicetak: {{ $generatedAt->timezone('Asia/Singapore')->format('Y-m-d H:i') }}</div>
  <br>

  <table class="data">
    <thead>
      <tr>
        <th class="text-center col-idx px-tight">#</th>
        <th class="col-name">Name</th>
        <th class="col-branch">Branch</th>
        <th class="col-acc">Account No</th>
        <th>Address</th>
        <th class="col-phone">Phone</th>
        <th class="col-email">Email</th>
      </tr>
    </thead>
    <tbody>
      @php $i = 1; @endphp
      @forelse($rows as $r)
        <tr>
          <td class="text-center px-tight">{{ $i++ }}</td>
          <td>{{ $r->name }}</td>
          <td>{{ $r->branch }}</td>
          <td>{{ $r->account_no }}</td>
          <td>{{ $r->address }}</td>
          <td>{{ $r->phone }}</td>
          <td>{{ $r->email }}</td>
        </tr>
      @empty
        <tr><td colspan="7" class="text-center">Tidak ada data.</td></tr>
      @endforelse
    </tbody>
  </table>

</body>
</html>
