<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  @php
    // Normalisasi & fallback
    $paper       = strtoupper($paper ?? 'A4');
    $orientation = strtolower($orientation ?? 'portrait');
    if (! in_array($orientation, ['portrait','landscape'])) {
        $orientation = 'portrait';
    }
  @endphp
  <style>
    /* ===== DOM PDF Optimized Styles (reusable) ===== */
      @page { size: {{ $paper }} {{ $orientation }}; margin: 10mm; }

    body{
      margin:0; padding:0;
      font-family: DejaVu Sans, Arial, sans-serif;
      font-size:9px; color:#333; line-height:1.2;
    }

    /* ===== Header ===== */
    .header{ width:100%; margin-bottom:12px; border-bottom:2px solid #333; padding-bottom:8px; }
    .header-with-logo{ display:table; width:100%; }
    .logo-cell{ display:table-cell; width:60px; vertical-align:top; padding-right:10px; }
    .logo-img{ width:55px; height:55px; object-fit:contain; border:0; }
    .info-cell{ display:table-cell; vertical-align:top; }
    .hotel-name{ font-size:16px; font-weight:700; margin:0 0 3px 0; color:#222; }
    .hotel-info{ font-size:8px; color:#666; margin:1px 0; }

    /* ===== Title ===== */
    .title-section{ margin-bottom:8px; }
    .report-title{ font-size:13px; font-weight:700; margin:0 0 2px 0; color:#222; }
    .report-meta{ font-size:7px; color:#888; margin:0; }

    /* ===== Table ===== */
    .table-container{ width:100%; }
    table.data-table{
      width:100%;
      border-collapse:collapse;
      border:1px solid #999;
      font-size:8px;
    }
    .data-table thead{ display: table-header-group; }
    .data-table tr{ page-break-inside: avoid; }

    .data-table thead th{
      background:#4a5568; color:#fff; font-weight:700;
      font-size:8px; padding:4px 2px; text-align:center;
      border:1px solid #666; vertical-align:middle;
    }
    .data-table tbody td{
      padding:3px 2px; border:1px solid #ddd; vertical-align:top; font-size:8px;
    }
    .data-table tbody tr:nth-child(even){ background:#f8f9fa; }
    .data-table tbody tr:nth-child(odd){ background:#fff; }

    .col-no{
      text-align:center; font-weight:700; background:#f0f0f0 !important;
      width:25px !important; min-width:25px !important; max-width:25px !important;
      padding-left:2px !important; padding-right:2px !important; white-space:nowrap;
    }
    .wrap{ overflow-wrap:anywhere; word-break:break-word; hyphens:auto; }
    .empty-cell{ color:#999; text-align:center; font-style:italic; }
    .no-data{ text-align:center; padding:20px; font-style:italic; color:#666; }

    /* Optional footer
    .footer{ position: fixed; bottom: 6mm; left:0; right:0; text-align:center; font-size:7px; color:#666; }
    .pagenum:before{ content: counter(page); }
    .pagecount:before{ content: counter(pages); } */
  </style>
</head>
<body>
  {{-- ===== HEADER ===== --}}
  <div class="header">
    @if(!empty($logoData))
      <div class="header-with-logo">
        <div class="logo-cell">
          <img src="{{ $logoData }}" alt="Logo" class="logo-img">
        </div>
        <div class="info-cell">
          <div class="hotel-name">{{ $hotel->name ?? 'Hotel' }}</div>
          @if($hotel?->address) <div class="hotel-info">Address: {{ $hotel->address }}</div> @endif
          @if($hotel?->phone)   <div class="hotel-info">Phone: {{ $hotel->phone }}</div> @endif
          @if($hotel?->email)   <div class="hotel-info">Email: {{ $hotel->email }}</div> @endif
        </div>
      </div>
    @else
      <div class="hotel-name">{{ $hotel->name ?? 'Hotel' }}</div>
      @if($hotel?->address) <div class="hotel-info">Address: {{ $hotel->address }}</div> @endif
      @if($hotel?->phone || $hotel?->email)
        <div class="hotel-info">
          @if($hotel?->phone) Phone: {{ $hotel->phone }} @endif
          @if($hotel?->phone && $hotel?->email) | @endif
          @if($hotel?->email) Email: {{ $hotel->email }} @endif
        </div>
      @endif
    @endif
  </div>

  {{-- ===== TITLE ===== --}}
  <div class="title-section">
    <h1 class="report-title">{{ $title ?? 'Report' }}</h1>
    @if(!empty($generatedAt))
      <p class="report-meta">
        Generated: {{ $generatedAt->timezone('Asia/Singapore')->format('Y-m-d H:i') }} SGT
        @if(isset($totalCount)) · Total: {{ $totalCount }} @endif
      </p>
    @endif
  </div>

  {{-- ===== TABLE =====
       $columns: array keyed => ['title' => string, 'class' => string, 'show' => bool]
       $data:    array of rows (associative) where keys match the $columns keys
  --}}
  <div class="table-container">
    <table class="data-table">
      <thead>
        <tr>
          <th class="col-no">#</th>
          @foreach($columns as $key => $cfg)
            @if(!isset($cfg['show']) || $cfg['show'])
              <th class="{{ $cfg['class'] ?? '' }}">{{ $cfg['title'] ?? ucfirst($key) }}</th>
            @endif
          @endforeach
        </tr>
      </thead>
      <tbody>
    @forelse($data as $idx => $row)
        <tr>
            <td class="col-no">{{ $idx + 1 }}</td>
            @foreach($columns as $key => $cfg)
                @if(!isset($cfg['show']) || $cfg['show'])
                    @php $val = $row[$key] ?? null; @endphp
                    <td class="{{ $cfg['class'] ?? '' }} {{ ($cfg['wrap'] ?? true) ? 'wrap' : '' }}">
                        @if(filled($val))
                            @if($key === 'logo')
                                {{-- tampilkan logo sebagai gambar kecil --}}<center>
                                <img src="{{ buildPdfLogoData($val) }}" alt="logo" style="max-height:100px; max-width:100px;"></center>
                            @else
                                {{ $val }}
                            @endif
                        @else
                            <span class="empty-cell">-</span>
                        @endif
                    </td>
                @endif
            @endforeach
        </tr>
    @empty
        <tr>
            <td class="no-data" colspan="{{ 1 + collect($columns)->filter(fn($c)=>!isset($c['show']) || $c['show'])->count() }}">
                No data available
            </td>
        </tr>
    @endforelse
</tbody>

    </table>
  </div>

  {{-- Optional footer
  <div class="footer">
    Page <span class="pagenum"></span> of <span class="pagecount"></span>
  </div> --}}
</body>
</html>
