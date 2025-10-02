<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <style>
    /* ===== DOM PDF Optimized Styles ===== */
    @page {
      size: A4 landscape;
      margin: 10mm;
    }

    body{
      margin:0;
      padding:0;
      font-family: DejaVu Sans, Arial, sans-serif;
      font-size:9px;
      color:#333;
      line-height:1.2;
    }

    /* ===== Header Section ===== */
    .header{ width:100%; margin-bottom:12px; border-bottom:2px solid #333; padding-bottom:8px; }
    .header-content{ width:100%; }
    .header-with-logo{ display:table; width:100%; }
    .logo-cell{ display:table-cell; width:60px; vertical-align:top; padding-right:10px; }
    .logo-img{
      width:55px; height:55px;
      object-fit:contain;      /* lebih aman untuk berbagai logo */
      border:0;                /* hilangkan border dekoratif */
    }
    .info-cell{ display:table-cell; vertical-align:top; }
    .hotel-name{ font-size:16px; font-weight:700; margin:0 0 3px 0; color:#222; }
    .hotel-info{ font-size:8px; color:#666; margin:1px 0; }

    /* ===== Title Section ===== */
    .title-section{ margin-bottom:8px; }
    .report-title{ font-size:13px; font-weight:700; margin:0 0 2px 0; color:#222; }
    .report-meta{ font-size:7px; color:#888; margin:0; }

    /* ===== Table Styles ===== */
    .table-container{ width:100%; }
    .data-table{
      width:100%;
      border-collapse:collapse;
      border:1px solid #999;
      font-size:8px;
    }

    /* REPEAT HEADER ON EACH PAGE */
    .data-table thead{ display: table-header-group; }
    .data-table tfoot{ display: table-row-group; } /* jika suatu saat pakai tfoot */
    .data-table tr{ page-break-inside: avoid; }

    .data-table thead th{
      background:#4a5568;
      color:#fff;
      font-weight:700;
      font-size:8px;
      padding:4px 2px;
      text-align:center;
      border:1px solid #666;
      vertical-align:middle;
    }
    .data-table tbody td{
      padding:3px 2px;
      border:1px solid #ddd;
      vertical-align:top;
      font-size:8px;
    }
    .data-table tbody tr:nth-child(even){ background:#f8f9fa; }
    .data-table tbody tr:nth-child(odd){ background:#fff; }

    /* ===== Column Widths ===== */
    .col-no{
      text-align:center; font-weight:700; background:#f0f0f0 !important;
      width:25px !important; min-width:25px !important; max-width:25px !important;
      padding-left:2px !important; padding-right:2px !important;
      white-space:nowrap;
    }
    .col-name{ width:80px; font-weight:700; }
    .col-email{ width:90px; font-size:7px; }
    .col-phone{ width:60px; font-size:7px; text-align:center; }
    .col-address{ width:100px; font-size:7px; }
    .col-doc{ width:50px; font-size:7px; text-align:center; }
    .col-family{ width:60px; font-size:7px; }

    /* ===== Utilities ===== */
    .text-center{ text-align:center; }
    .text-left{ text-align:left; }
    .text-right{ text-align:right; }

    .empty-cell{ color:#999; text-align:center; font-style:italic; }
    .no-data{ text-align:center; padding:20px; font-style:italic; color:#666; }

    /* Strong wrapping for long content (email/address/ids) */
    .wrap{
      overflow-wrap:anywhere;
      word-break:break-word;
      hyphens:auto;
    }
    .nowrap{ white-space:nowrap; }

    /* ===== Optional footer (Page X of Y) =====
       Aktifkan dengan menambahkan <div class="footer"> di body (lihat bawah) */
    .footer{
      position: fixed;
      bottom: 6mm;
      left: 0; right: 0;
      text-align: center;
      font-size: 7px;
      color: #666;
    }
    .pagenum:before { content: counter(page); }
    .pagecount:before { content: counter(pages); }
  </style>
</head>
<body>
@php
  // ===== Column Configuration =====
  $columns = [
    'email'       => ['show' => $rows->contains(fn($r) => !empty($r->email)),       'title' => 'Email',    'class' => 'col-email'],
    'phone'       => ['show' => $rows->contains(fn($r) => !empty($r->phone)),       'title' => 'Phone',    'class' => 'col-phone'],
    'address'     => ['show' => $rows->contains(fn($r) => !empty($r->address)),     'title' => 'Address',  'class' => 'col-address'],
    'nid_no'      => ['show' => $rows->contains(fn($r) => !empty($r->nid_no)),      'title' => 'NID',      'class' => 'col-doc'],
    'passport_no' => ['show' => $rows->contains(fn($r) => !empty($r->passport_no)), 'title' => 'Passport', 'class' => 'col-doc'],
    'father'      => ['show' => $rows->contains(fn($r) => !empty($r->father)),      'title' => 'Father',   'class' => 'col-family'],
    'mother'      => ['show' => $rows->contains(fn($r) => !empty($r->mother)),      'title' => 'Mother',   'class' => 'col-family'],
    'spouse'      => ['show' => $rows->contains(fn($r) => !empty($r->spouse)),      'title' => 'Spouse',   'class' => 'col-family'],
  ];
  $visibleColumns = array_filter($columns, fn($c) => $c['show']);
  $totalColumns   = 2 + count($visibleColumns); // (# + name) + visible others
@endphp

  {{-- ===== HEADER ===== --}}
  <div class="header">
    <div class="header-content">
      @if(!empty($logoData))
        <div class="header-with-logo">
          <div class="logo-cell">
            <img src="{{ $logoData }}" alt="Logo" class="logo-img">
          </div>
          <div class="info-cell">
            <div class="hotel-name">{{ $hotel->name ?? 'Hotel Management' }}</div>
            @if($hotel?->address) <div class="hotel-info">Address: {{ $hotel->address }}</div> @endif
            @if($hotel?->phone)   <div class="hotel-info">Phone: {{ $hotel->phone }}</div>     @endif
            @if($hotel?->email)   <div class="hotel-info">Email: {{ $hotel->email }}</div>     @endif
          </div>
        </div>
      @else
        <div class="hotel-name">{{ $hotel->name ?? 'Hotel Management' }}</div>
        @if($hotel?->address) <div class="hotel-info">Address: {{ $hotel->address }}</div> @endif
        @if($hotel?->phone || $hotel?->email)
          <div class="hotel-info">
            @if($hotel?->phone)Phone: {{ $hotel->phone }}@endif
            @if($hotel?->phone && $hotel?->email) | @endif
            @if($hotel?->email)Email: {{ $hotel->email }}@endif
          </div>
        @endif
      @endif
    </div>
  </div>

  {{-- ===== TITLE ===== --}}
  <div class="title-section">
    <h1 class="report-title">Guest Directory Report</h1>
    <p class="report-meta">
      Generated: {{ $generatedAt->timezone('Asia/Singapore')->format('Y-m-d H:i') }} SGT ·
      Total Guests: {{ $rows->count() }}
    </p>
  </div>

  {{-- ===== TABLE ===== --}}
  <div class="table-container">
    <table class="data-table">
      <thead>
        <tr>
          <th class="col-no">#</th>
          <th class="col-name">Name</th>
          @foreach($columns as $field => $cfg)
            @if($cfg['show'])
              <th class="{{ $cfg['class'] }}">{{ $cfg['title'] }}</th>
            @endif
          @endforeach
        </tr>
      </thead>
      <tbody>
        @forelse($rows as $i => $g)
          <tr>
            <td class="col-no">{{ $i + 1 }}</td>
            <td class="col-name wrap">{{ $g->name ?? 'N/A' }}</td>

            @foreach($columns as $field => $cfg)
              @if($cfg['show'])
                <td class="{{ $cfg['class'] }} wrap">
                  @if(!empty($g->$field))
                    {{ $g->$field }}
                  @else
                    <span class="empty-cell">-</span>
                  @endif
                </td>
              @endif
            @endforeach
          </tr>
        @empty
          <tr>
            <td colspan="{{ $totalColumns }}" class="no-data">No guest data available</td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>

  {{-- ===== Optional footer (aktifkan bila ingin) =====
  <div class="footer">
    Page <span class="pagenum"></span> of <span class="pagecount"></span>
  </div>
  --}}
</body>
</html>
