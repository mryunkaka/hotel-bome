{{-- resources/views/prints/reservations/print.blade.php --}}
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  @php
  // ================== HITUNGAN RATE (diskon, pajak, extra bed) ==================
  // Harga extra bed per unit
  $EXTRA_BED_PRICE = 100000;

  /**
   * (Opsional) peta pajak: [tax_id => percent]
   * Lebih bagus dikirim dari controller: $taxLookup = TaxSetting::pluck('percent','id')->toArray();
   * Jika belum ada tapi $rows berisi id_tax, kita coba isi otomatis:
   */
  if (!isset($taxLookup)) {
      $taxLookup = [];
      if (!empty($rows) && is_iterable($rows)) {
          $ids = [];
          foreach ($rows as $rr) {
              $idTax = is_array($rr) ? ($rr['id_tax'] ?? null) : (is_object($rr) ? ($rr->id_tax ?? null) : null);
              if ($idTax) $ids[] = (int) $idTax;
          }
          $ids = array_values(array_unique(array_filter($ids)));
          if ($ids) {
              try {
                  $taxLookup = \App\Models\TaxSetting::query()
                      ->whereIn('id', $ids)
                      ->pluck('percent', 'id')
                      ->toArray();
              } catch (\Throwable $e) {
                  $taxLookup = [];
              }
          }
      }
  }

  /** helper: ambil nilai dari array/objek */
  $getVal = function ($src, string $key, $default = null) {
      if (is_array($src) && array_key_exists($key, $src)) return $src[$key];
      if (is_object($src) && isset($src->{$key})) return $src->{$key};
      return $default;
  };

  /** helper: normalisasi angka (support "1.234,56", "10%", dsb) → float */
  $toNum = function ($v): float {
      if ($v === null || $v === '') return 0.0;
      if (is_numeric($v)) return (float) $v;
      $s = (string) $v;
      $s = trim($s);
      $s = str_replace(['%',' '], '', $s);
      if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
          $s = str_replace('.', '', $s);
          $s = str_replace(',', '.', $s);
      } else {
          $s = str_replace([',', ' '], '', $s);
      }
      $s = preg_replace('/[^0-9\.\-]/', '', $s);
      return is_numeric($s) ? (float) $s : 0.0;
  };

  /** clamp persen 0–100 */
  $clampPct = fn (float $p) => max(0.0, min(100.0, $p));

  // Rumus final rate
  $calcFinalRate = function ($row) use ($EXTRA_BED_PRICE, $getVal, $toNum, $clampPct, $taxLookup) {
      // base rate (cari beberapa kemungkinan key)
      $base = $toNum(
          $getVal($row, 'rate',
              $getVal($row, 'unit_price',
                  $getVal($row, 'room_rate', 0)
              )
          )
      );

      // diskon %
      $disc = $clampPct($toNum(
          $getVal($row, 'discount_percent', $getVal($row, 'discount', 0))
      ));

      // pajak % (prioritas: tax_percent | tax | id_tax -> $taxLookup)
      $tax = $toNum($getVal($row, 'tax_percent', $getVal($row, 'tax', null)));
      if ($tax === 0.0) {
          $idTax = $getVal($row, 'id_tax');
          if ($idTax !== null && isset($taxLookup[(int) $idTax])) {
              $tax = $toNum($taxLookup[(int) $idTax]);
          }
      }
      $tax = $clampPct($tax);

      // extra bed qty
      $extraQty = (int) $toNum($getVal($row, 'extra_bed', 0));
      $extra    = $extraQty * $EXTRA_BED_PRICE;

      // Hitung: base → diskon → pajak → + extra
      $afterDisc = max(0.0, $base * (1 - $disc / 100));
      $afterTax  = $afterDisc * (1 + $tax / 100);

      return $afterTax + $extra;
  };

  /**
   * ENRICH ROW dari ReservationGuest bila field diskon/pajak/extra belum ada.
   * Kunci pencarian: room_no + tanggal exp_arr (dd/mm/yyyy atau date lain).
   * Tidak menghapus field yang sudah ada — hanya melengkapi yang kosong.
   */
  use \Carbon\Carbon;
  $activeHotelId = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id ?? ($hotel->id ?? null);

  // cache room_no -> room_id untuk hotel aktif
  $roomIdByNo = [];
  try {
      $roomIdByNo = $activeHotelId
        ? \App\Models\Room::where('hotel_id', $activeHotelId)->pluck('id', 'room_no')->toArray()
        : [];
  } catch (\Throwable $e) {
      $roomIdByNo = [];
  }

  $enrichRow = function ($row) use (&$taxLookup, $getVal, $toNum, $roomIdByNo, $activeHotelId) {
      $r = is_array($row) ? $row : (array) $row;

      $needDisc  = !isset($r['discount_percent']);
      $needTaxId = !isset($r['id_tax']) && !isset($r['tax_percent']) && !isset($r['tax']);
      $needExtra = !isset($r['extra_bed']);
      $needBase  = !isset($r['rate']) && !isset($r['unit_price']) && !isset($r['room_rate']);

      if (!($needDisc || $needTaxId || $needExtra || $needBase)) {
          return $r; // sudah lengkap
      }

      // derive kunci untuk cari ReservationGuest
      $roomNo = trim((string) ($r['room_no'] ?? ''));
      $roomId = $roomIdByNo[$roomNo] ?? null;

      $arrRaw = $getVal($r, 'exp_arr');
      $arrDate = null;
      if ($arrRaw) {
          try {
              $arrDate = Carbon::parse($arrRaw)->toDateString();
          } catch (\Throwable $e) {
              // jika format "dd/mm/yyyy", parse manual
              if (preg_match('~^(\d{2})/(\d{2})/(\d{4})~', (string)$arrRaw, $m)) {
                  $arrDate = "{$m[3]}-{$m[2]}-{$m[1]}";
              }
          }
      }

      try {
          $q = \App\Models\ReservationGuest::query()
              ->with('tax')
              ->when($activeHotelId, fn($qq) => $qq->where('hotel_id', $activeHotelId))
              ->when($roomId,       fn($qq) => $qq->where('room_id', $roomId))
              ->when($arrDate,      fn($qq) => $qq->whereDate('expected_checkin', $arrDate))
              ->latest('id');

          $rg = $q->first();

          if ($rg) {
              if ($needBase && $rg->room_rate) {
                  $r['room_rate'] = (float) $rg->room_rate;
              }
              if ($needDisc && $rg->discount_percent !== null) {
                  $r['discount_percent'] = (float) $rg->discount_percent;
              }
              if ($needExtra && $rg->extra_bed !== null) {
                  $r['extra_bed'] = (int) $rg->extra_bed;
              }
              if ($needTaxId) {
                  if ($rg->id_tax) {
                      $r['id_tax'] = (int) $rg->id_tax;
                      // pastikan taxLookup punya persen-nya
                      if ($rg->relationLoaded('tax') && $rg->tax && !isset($taxLookup[$rg->id_tax])) {
                          $taxLookup[$rg->id_tax] = (float) $rg->tax->percent;
                      }
                  } elseif ($rg->relationLoaded('tax') && $rg->tax) {
                      $r['tax_percent'] = (float) $rg->tax->percent;
                  }
              }
          }
      } catch (\Throwable $e) {
          // lewati jika gagal query
      }

      return $r;
  };
  // ================== /END HITUNGAN ==================


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
                    $seg = explode('·', $seg)[0] ?? $seg; // potong di pemisah " · " jika ada
                    $seg = trim($seg);
                    if     ($key === 'category') $category = $seg;
                    elseif ($key === 'guest')    $guestNm  = $seg;
                    elseif ($key === 'arr')      $expArr   = $seg;
                    elseif ($key === 'dept')     $expDept  = $seg;
                }
            }

            $rows[] = [
              'room_no'          => $roomNo ?: '-',
              'category'         => $category ?: '-',
              'rate'             => (float)($it['unit_price'] ?? 0),
              'discount_percent' => (float)($it['discount_percent'] ?? 0), // jika ada di $items
              'tax_percent'      => isset($it['tax_percent']) ? (float)$it['tax_percent'] : 0,
              'id_tax'           => $it['id_tax'] ?? null,
              'extra_bed'        => (int)($it['extra_bed'] ?? 0),
              'ps'               => $ps ?? null,
              'guest'            => $guestNm ?: '-',
              'exp_arr'          => $expArr ?: '-',
              'exp_dept'         => $expDept ?: '-',
            ];
        }
    }

    // >>> ENRICH semua row dari ReservationGuest bila field-field penting belum ada
    if (!empty($rows)) {
        foreach ($rows as &$__r) {
            $__r = $enrichRow($__r);
        }
        unset($__r);
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
          <td class="right">{{ $fmtMoney($calcFinalRate($r)) }}</td>
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
