@php
    use App\Support\ReservationMath;
    use App\Support\ReservationView;
    use Illuminate\Support\Carbon;

    /** @var \App\Models\ReservationGuest|null $rg */
    $rg = is_callable($getRecord ?? null) ? $getRecord() : null;
    $res = $rg?->reservation;
    $guest = $rg?->guest;
    $room = $rg?->room;
    $group = $res?->group;

    $fmt  = fn($dt) => ReservationView::fmtDate($dt, true);
    $fmtD = fn($dt) => $dt ? Carbon::parse($dt)->format('d/m/Y') : '-';
    $money = fn($v) => ReservationView::fmtMoney($v);

    // Nights (konsisten dengan helper)
    $cin  = $rg?->actual_checkin ?? $rg?->expected_checkin;
    $cout = $rg?->actual_checkout ?: Carbon::now('Asia/Makassar');
    $nights = ReservationMath::nights($cin, $cout, 1);

    // Angka-angka bill (panel kanan)
    $basicRate = (float) ReservationMath::basicRate($rg);
    $discPct   = (float) ($rg?->discount_percent ?? 0);
    $discAmount = (int) round(($basicRate * $discPct) / 100);
    $afterDiscPerNight = max(0, $basicRate - $discAmount);
    $afterDiscTimesNights = $afterDiscPerNight * $nights;

    $chargeRp  = (int) ($rg?->charge ?? 0);
    $extraQty  = (int) ($rg?->extra_bed ?? 0);
    $extraPrice = 100_000;
    $extraSub  = $extraQty * $extraPrice;

        // === SERVICE dari MINIBAR ===
    // Subtotal minibar untuk RG ini (jumlah line_total semua item minibar pada receipt milik RG)
    $minibarSub = (int) (\App\Models\MinibarReceiptItem::query()
        ->whereHas('receipt', fn($q) => $q->where('reservation_guest_id', $rg?->id))
        ->sum('line_total'));

    // Persentase service — pakai field/resolusi yang tersedia di Reservation terlebih dahulu.
    // Silakan sesuaikan fallback sesuai skema project-mu kalau ada lokasi lain untuk service percent.
    $svcPct = (float) ($res?->service_percent ?? 0);

    // Nominal service yang DIHITUNG DARI MINIBAR
    $serviceRp = (int) round(($minibarSub * $svcPct) / 100);

    // penalty: utamakan expected_checkin RG
    $pen = ReservationMath::latePenalty($rg?->expected_checkin ?: $res?->expected_arrival, $rg?->actual_checkin, $basicRate, [
        'tz' => 'Asia/Makassar',
    ]);
    $penaltyRp = (int) ($pen['amount'] ?? 0);

    // Pajak & total (GLOBAL utk panel kanan)
    $taxPct  = (float) ($res?->tax?->percent ?? 0);
    $taxBase = $afterDiscTimesNights + $chargeRp + $minibarSub + $serviceRp + $extraSub + $penaltyRp; // subtotal tanpa pajak (termasuk minibar & servicenya)
    $taxRp   = (int) round(($taxBase * $taxPct) / 100);
    $grand   = (int) ($taxBase + $taxRp);

    $deposit = (int) ($res?->deposit ?? 0);
    $due     = max(0, $grand - $deposit);

    // Ambil list semua RG dlm reservation utk tabel kiri
    $others = $res?->reservationGuests()
        ->with([
            'guest:id,name',
            'room:id,room_no,type,price',
            'reservation.tax', // ⬅️ bukan 'tax' di RG
        ])
        ->orderBy('id')
        ->get()
        ?? collect([$rg]);

    // ⬇️ DIPINDAH KE SINI (sebelum dipakai di judul)
    $allGuests     = $others;
    $totalGuests   = $allGuests->count();
    $allCheckedOut = $totalGuests > 0 && $allGuests->every(fn($gg) => filled($gg->actual_checkout));
@endphp

@if (!$rg)
    <div class="p-4" style="color:#6b7280;font-size:14px">Record tidak tersedia.</div>
@else
    <style>
        .hb-wrap { border: 1px solid #e5e7eb; border-radius: 12px; background: #fff }
        .hb-head { padding: 10px 14px; border-bottom: 1px solid #e5e7eb; font-weight: 700 }
        .hb-body { padding: 14px }
        .muted   { color: #6b7280 }
        .grid-2  { display: grid; grid-template-columns: 1fr; gap: 16px }
        @media(min-width:1024px){ .grid-2 { grid-template-columns: 1.2fr 0.8fr } }
        .card { border: 1px solid #e5e7eb; border-radius: 10px; background: #fff; overflow: hidden }
        .card .title { padding: 8px 12px; border-bottom: 1px solid #e5e7eb; font-weight: 700 }
        .rows { display: grid; grid-template-columns: 160px 1fr }
        .row { display: contents }
        .row>div { padding: 6px 10px; border-bottom: 1px solid #f1f5f9 }
        .row .k { color: #6b7280 }
        .pill { display: inline-block; padding: 2px 8px; border-radius: 999px; border: 1px solid #c7d2fe; background: #eef2ff; color: #3730a3; font-size: 12px }
        .table { width: 100%; border-collapse: collapse }
        .table tr { border-bottom: 1px solid #e5e7eb }
        .table td { padding: 8px 10px; vertical-align: top }
        .table .k { color: #6b7280 }
        .table .v { text-align: right; font-variant-numeric: tabular-nums }
        .total { display: flex; justify-content: space-between; align-items: center; padding: 10px 12px; border-top: 2px solid #d1d5db; background: #fafafa; font-weight: 800 }
        .grid-1 { display: grid; grid-template-columns: 1fr; gap: 16px; margin-bottom: 16px }
        .table thead th { padding: 8px 10px; border-bottom: 1px solid #e5e7eb; text-align: left; font-weight: 700; color: #374151 }
        .table--compact { font-size: 12px; }
        .table--compact thead th, .table--compact td { padding: 6px 8px; white-space: nowrap; line-height: 1.2; }
        .table--compact .pill { font-size: 11px; padding: 1px 6px; }
        .table--compact .muted { font-size: 11px; }
        .table-scroll { overflow-x: auto; }
        tfoot td { font-weight: 700; background: #fafafa; }
    </style>

    <div class="hb-wrap">
        @if ($showHeader ?? false)
            <div class="hb-head">Guest Bill</div>
        @endif
        <div class="hb-body">
            <div style="margin-bottom:8px;font-size:14px">
                <span class="muted">Register No :</span>
                <strong>{{ $res?->reservation_no ?? ($res?->id ?? '-') }}</strong>
                @if ($rg?->bill_no)
                    <span class="muted" style="margin:0 6px">•</span>
                    <span class="muted">Bill No :</span> <strong>{{ $rg->bill_no }}</strong>
                @endif
                @if ($rg?->bill_closed_at)
                    <span class="muted" style="margin:0 6px">•</span>
                    <span class="muted">Closed :</span> <strong>{{ $fmt($rg->bill_closed_at) }}</strong>
                @endif
            </div>

            {{-- ====== GRID 2 kolom ====== --}}
            <div class="grid-2">
                {{-- KIRI --}}
                <div class="space-y-4">
                    @php
                        $rbType  = strtoupper($res?->reserved_by_type ?? 'GUEST');
                        $isGroup = $rbType === 'GROUP' && $res?->group;
                        $rbTitle = $isGroup ? 'Reserved By — Company' : 'Reserved By — Guest';
                        $rb      = $isGroup ? $res?->group : ($res?->guest ?? $guest);
                    @endphp

                    <div class="card">
                        <div class="title">{{ $rbTitle }}</div>
                        <div class="rows">
                            <div class="row"><div class="k">Name</div><div>{{ $rb?->name ?? '-' }}</div></div>
                            <div class="row"><div class="k">Address</div><div>{{ $rb?->address ?? '-' }}</div></div>
                            <div class="row"><div class="k">City</div><div>{{ $rb?->city ?? '-' }}</div></div>
                            <div class="row"><div class="k">Phone</div><div>{{ $rb?->phone ?? ($rb?->handphone ?? '-') }}</div></div>
                            <div class="row"><div class="k">Email</div><div>{{ $rb?->email ?? '-' }}</div></div>
                            @if ($isGroup && !empty($res?->group?->fax))
                                <div class="row"><div class="k">Fax</div><div>{{ $res->group->fax }}</div></div>
                            @endif
                        </div>
                    </div>

                    <div class="card">
                        <div class="title">Detail Guest Information</div>
                        <div class="rows">
                            <div class="row"><div class="k">Name</div><div>{{ $guest?->display_name ?? ($guest?->name ?? '-') }}</div></div>
                            <div class="row"><div class="k">Address</div><div>{{ $guest?->address ?? '-' }}</div></div>
                            <div class="row"><div class="k">City/Country</div><div>{{ $guest?->city ?? '-' }}{{ $guest?->nationality ? ' / ' . $guest->nationality : '' }}</div></div>
                            <div class="row"><div class="k">Profession</div><div>{{ $guest?->profession ?? '-' }}</div></div>
                            <div class="row"><div class="k">Identity</div><div>{{ $guest?->id_type ?? '-' }}{{ $guest?->id_card ? ' — ' . $guest->id_card : '' }}</div></div>
                            <div class="row"><div class="k">Birth</div><div>{{ $guest?->birth_place ? $guest->birth_place . ', ' : '' }}{{ $guest?->birth_date ? $fmtD($guest->birth_date) : '-' }}</div></div>
                            <div class="row"><div class="k">Issued</div><div>{{ $guest?->issued_place ? $guest->issued_place . ', ' : '' }}{{ $guest?->issued_date ? $fmtD($guest->issued_date) : '-' }}</div></div>
                            <div class="row"><div class="k">Phone</div><div>{{ $guest?->phone ?? '-' }}</div></div>
                            <div class="row"><div class="k">Email</div><div>{{ $guest?->email ?? '-' }}</div></div>
                            <div class="row">
                                <div class="k">Room</div>
                                <div>{{ $room?->room_no ?? '-' }}{{ $room?->type ? ' — ' . $room->type : '' }}
                                    @if ($rg->breakfast ?? '' === 'Yes') <span class="pill" style="margin-left:6px">Breakfast</span> @endif
                                </div>
                            </div>
                            <div class="row">
                                <div class="k">Pax</div>
                                <div>
                                    {{ (int) ($rg->jumlah_orang ?? ($rg->male ?? 0) + ($rg->female ?? 0) + ($rg->children ?? 0)) }}
                                    <span class="muted"> (M {{ (int) ($rg->male ?? 0) }}, F {{ (int) ($rg->female ?? 0) }}, C {{ (int) ($rg->children ?? 0) }})</span>
                                </div>
                            </div>
                            <div class="row"><div class="k">Extra Bed</div><div>{{ $extraQty > 0 ? $extraQty . ' — ' . $money($extraSub) : '-' }}</div></div>
                        </div>
                        {{-- ⬅️ Tidak ada tax di sini --}}
                    </div>
                </div>

                {{-- KANAN --}}
                <div class="space-y-4">
                    <div class="card">
                        <div class="title">Reservation Summary</div>
                        <div class="rows">
                            <div class="row"><div class="k">Purpose of Visit</div><div>{{ $res?->purpose ?? '-' }}</div></div>
                            <div class="row"><div class="k">Length of Stay</div><div>{{ $nights }} Night(s)</div></div>
                            <div class="row"><div class="k">Arrival</div><div>{{ $fmt($rg?->expected_checkin) }}</div></div>
                            <div class="row"><div class="k">Departure</div><div>{{ $fmt($rg?->actual_checkout ?: Carbon::now('Asia/Makassar')) }}</div></div>
                            <div class="row"><div class="k">Check-in</div><div>{{ $fmt($rg?->actual_checkin) }}</div></div>
                            <div class="row"><div class="k">Company</div><div>{{ $res?->company ?? ($group?->company ?? '-') }}</div></div>
                            <div class="row"><div class="k">Charge</div><div>{{ $res?->charge_to ?? 'Personal Account' }}</div></div>
                            <div class="row"><div class="k">Rate Code / Type</div><div>{{ $rg?->rate_code ?? '-' }}{{ $rg?->rate_type ? ' — ' . $rg->rate_type : '' }}</div></div>
                            <div class="row"><div class="k">Remarks</div><div>{{ $res?->remarks ?? '-' }}</div></div>
                        </div>

                        <table class="table" style="margin-top:8px">
                            <tr><td class="k">Basic Rate</td><td class="v">{{ $money($basicRate) }}</td></tr>
                            <tr><td class="k">Discount</td><td class="v">{{ number_format($rg?->discount_percent ?? 0, 2, ',', '.') }}%</td></tr>
                            <tr><td class="k">After Discount / Night</td><td class="v"><strong>{{ $money($afterDiscPerNight) }}</strong></td></tr>
                        </table>
                    </div>
                    @php
                        $gb = ReservationMath::subtotalGuestBill($rg);

                        $minibarDue = \App\Support\ReservationMath::minibarDue($rg);
                        // Ambil semuanya dari helper (fallback ke nilai lama jika key belum ada)
                        $afterDiscTimesNights = (int) ($gb['rate_after_disc_times_nights'] ?? $afterDiscTimesNights);
                        $chargeRp             = (int) ($gb['charge'] ?? $chargeRp);
                        $minibarSub           = (int) ($gb['minibar'] ?? $minibarSub);
                        $extraSub             = (int) ($gb['extra'] ?? $extraSub);
                        $penaltyRp            = (int) ($gb['penalty'] ?? $penaltyRp);

                        $taxPerGuest          = (int) ($gb['tax_per_guest'] ?? 0);
                        $depositCard          = (int) ($gb['deposit_card'] ?? 0);
                        $depositRoom          = (int) ($gb['deposit_room'] ?? 0);
                        $subAfterDeposit      = (int) ($gb['subtotal'] ?? 0);
                    @endphp
                    <div class="card">
                        <div class="title">Guest Bill</div>
                        <div class="hb-body" style="padding:0">
                            <table class="table">
                                <tr><td class="k">Rate After Discount × Nights</td><td class="v">{{ $money($afterDiscTimesNights) }}</td></tr>
                                <tr><td class="k">Charge</td><td class="v">{{ $money($chargeRp) }}</td></tr>
                                @php
                                    // pakai $rg yang sudah kamu set di bagian atas view
                                    $rgId = $rg->id ?? null;

                                    // ambil daftar receipt minibar milik RG ini
                                    $receipts = $rgId
                                        ? \App\Models\MinibarReceipt::where('reservation_guest_id', $rgId)
                                            ->orderByDesc('id')
                                            ->get(['id','receipt_no'])
                                        : collect();

                                    // jika hanya 1 receipt, sediakan 1 tombol langsung
                                    $printMinibarUrl = $receipts->count() === 1
                                        ? route('minibar-receipts.print', ['receipt' => $receipts->first()->id])
                                        : null;
                                @endphp
                                <tr>
                                    <td class="k">Minibar</td>
                                    <td class="v">
                                        @if ($minibarDue > 0)
                                            {{ $money($minibarDue) }}
                                        @else
                                            <span class="pill" style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:1px 6px;border-radius:999px;font-weight:600;">
                                                Paid
                                            </span>
                                        @endif

                                        {{-- tombol print --}}
                                        @if ($printMinibarUrl)
                                            <a href="{{ $printMinibarUrl }}" target="_blank" rel="noopener noreferrer"
                                            style="margin-left:8px;padding:2px 8px;border:1px solid #93c5fd;background:#eff6ff;color:#1d4ed8;border-radius:6px;text-decoration:none;font-weight:600;font-size:11px;">
                                                Print Minibar
                                            </a>
                                        @elseif($receipts->count() > 1)
                                            <details style="display:inline-block;margin-left:8px;">
                                                <summary style="cursor:pointer;padding:2px 8px;border:1px solid #93c5fd;background:#eff6ff;color:#1d4ed8;border-radius:6px;font-weight:600;font-size:11px;list-style:none;">
                                                    Print Minibar
                                                </summary>
                                                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:6px;margin-top:6px;position:absolute;z-index:20;">
                                                    @foreach ($receipts as $r)
                                                        <div style="margin:4px 0;">
                                                            <a href="{{ route('minibar-receipts.print', ['receipt' => $r->id]) }}"
                                                            target="_blank" rel="noopener noreferrer"
                                                            style="text-decoration:none;color:#1d4ed8;">
                                                                Receipt #{{ $r->receipt_no ?? $r->id }}
                                                            </a>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </details>
                                        @endif
                                    </td>
                                </tr>
                                <tr><td class="k">Extra Bed</td><td class="v">{{ $money($extraSub) }}</td></tr>
                                @if ($penaltyRp > 0)
                                    <tr><td class="k">Late Arrival Penalty</td><td class="v">{{ $money($penaltyRp) }}</td></tr>
                                @endif
                                @if (($taxPerGuest ?? 0) > 0)
                                <tr><td class="k">Tax (per guest)</td><td class="v">{{ $money($taxPerGuest) }}</td></tr>
                                @endif
                                @if (($depositCard ?? 0) > 0)
                                <tr><td class="k">Deposit Card</td><td class="v">- {{ $money($depositCard) }}</td></tr>
                                @endif
                                @if (($depositRoom ?? 0) > 0)
                                <tr><td class="k">Deposit Room</td><td class="v">- {{ $money($depositRoom) }}</td></tr>
                                @endif
                            </table>
                            {{-- SUBTOTAL (tanpa tax) --}}
                            <div class="total">
                                <div>Subtotal</div>
                                <div>{{ $money($subAfterDeposit) }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ====== FULL-WIDTH: Tabel ringkasan semua guest ====== --}}
            <div class="grid-1">
                <div class="card">
                    <div class="title" style="display:flex;align-items:center;justify-content:space-between">
                        <span>Guest Information</span>
                    </div>
                    @php
                        // Accumulators untuk footer
                        $sumBase  = 0; // total sebelum pajak
                        $sumTax   = 0; // total pajak
                        $sumGrand = 0; // total sesudah pajak (Amount Due + Tax)
                    @endphp

                    <div class="hb-body" style="padding:0">
                        <div class="table-scroll">
                            <table class="table table--compact">
                                <thead>
                                <tr>
                                    <th>Guest</th>
                                    <th>Room / Type</th>
                                    <th>Pax</th>
                                    <th>Nights</th>
                                    <th>Check-in</th>
                                    <th>Check-out</th>
                                    <th>Status</th>
                                    <th>Amount Due</th>
                                    <th>Action</th>
                                </tr>
                                </thead>
                                @php
                                    // Accumulators untuk footer
                                    $sumBase  = 0; // total sebelum pajak
                                    $sumTax   = 0; // total pajak
                                    $sumGrand = 0; // total sesudah pajak (Amount Due + Tax)

                                    // BARU: total grand untuk tamu yang SUDAH checkout
                                    $checkedGrand = 0;
                                @endphp

                                <tbody>
                                @forelse ($allGuests as $g)
                                    @php
                                        // Pax
                                        $paxVal = (int) (
                                            $g->jumlah_orang
                                            ?? ((int) ($g->male ?? 0) + (int) ($g->female ?? 0) + (int) ($g->children ?? 0))
                                        );

                                        // Nights
                                        $in  = $g->actual_checkin ?: $g->expected_checkin;
                                        $out = $g->actual_checkout ?: Carbon::now('Asia/Makassar');
                                        $n   = ReservationMath::nights($in, $out, 1);

                                        // Basic Rate (selalu RG-specific)
                                        $gRate = (float) ReservationMath::basicRate($g);

                                        // Discount & after
                                        $gDiscPct = (float) ($g->discount_percent ?? 0);
                                        $gDiscAmt = (int) round(($gRate * $gDiscPct) / 100);
                                        $gRateAfter = max(0, $gRate - $gDiscAmt);

                                        // charge & Extra
                                        $gChargeRp  = (int) ($g->charge ?? 0);
                                        $gExtraRp   = (int) ($g->extra_bed_total ?? ((int) ($g->extra_bed ?? 0) * 100_000));

                                        // Penalty (guest-first expected)
                                        $gPen = ReservationMath::latePenalty(
                                            $g->expected_checkin ?: ($g->reservation?->expected_arrival),
                                            $g->actual_checkin,
                                            $gRate,
                                            ['tz' => 'Asia/Makassar'],
                                        );
                                        $gPenaltyRp = (int) ($gPen['amount'] ?? 0);

                                        // Minibar subtotal per-guest
                                        $gMinibarSub = (int) (\App\Models\MinibarReceiptItem::query()
                                            ->whereHas('receipt', fn($q) => $q->where('reservation_guest_id', $g->id))
                                            ->sum('line_total'));

                                        // Service persen (pakai yang sama dengan reservation terkait guest ini)
                                        $gSvcPct   = (float) ($g->reservation?->service_percent ?? ($g->reservation?->service?->percent ?? 0));
                                        $gServiceRp = (int) round(($gMinibarSub * $gSvcPct) / 100);

                                        // Tax per-guest (buat hitung footer; tidak ditampilkan sebagai kolom)
                                        $gTaxPct  = (float) ($g->reservation?->tax?->percent ?? 0);
                                        $gTaxBase = $gRateAfter * $n + $gChargeRp + $gMinibarSub + $gServiceRp + $gExtraRp + $gPenaltyRp;
                                        $gTaxRp   = (int) round(($gTaxBase * $gTaxPct) / 100);
                                        $gGrand   = (int) ($gTaxBase + $gTaxRp);

                                        // Akumulasi footer
                                        $sumBase  += $gTaxBase;
                                        $sumTax   += $gTaxRp;
                                        $sumGrand += $gGrand;

                                        // BARU: tambahkan jika tamu ini sudah checkout
                                        if (filled($g->actual_checkout)) {
                                            $checkedGrand += $gGrand;
                                        }

                                    @endphp

                                    <tr>
                                        <td>
                                            {{ $g->guest?->name ?? '-' }}
                                            @if (($g->breakfast ?? null) === 'Yes')
                                                <span class="pill" style="margin-left:6px">Breakfast</span>
                                            @endif
                                            @if ($g->id === ($rg->id ?? null))
                                                <span class="pill" style="margin-left:6px;background:#ecfeff;border-color:#a5f3fc;color:#075985">Current</span>
                                            @endif
                                        </td>
                                        <td>
                                            {{ $g->room?->room_no ?? '-' }}
                                            {{ $g->room?->type ? ' — ' . $g->room->type : '' }}
                                            — {{ $money($gRate) }}
                                        </td>
                                        <td class="v">{{ $paxVal }}</td>
                                        <td class="v">{{ $n }}</td>
                                        <td>{{ ReservationView::fmtDate($g->actual_checkin, true) }}</td>
                                        <td>{{ ReservationView::fmtDate($g->actual_checkout, true) }}</td>
                                        <td>
                                            {{ $g->actual_checkout ? 'Checked-out' : 'In-house' }}
                                            @if (!$g->actual_checkout && $g->expected_checkout)
                                                <span class="muted"> (ETD {{ ReservationView::fmtDate($g->expected_checkout, true) }})</span>
                                            @endif
                                        </td>
                                        @php
                                        $gAmountDue = ReservationMath::amountDueGuestInfo($g); // base - deposit (tanpa pajak)
                                        @endphp
                                        <td class="v"><strong>{{ ReservationView::fmtMoney($gAmountDue) }}</strong></td>
                                        <td>
                                            @if ($g->id === ($rg->id ?? null))
                                                <span class="pill" style="background:#eef2ff;border-color:#c7d2fe;color:#3730a3">Selected</span>
                                            @else
                                                <a href="{{ url('/admin/reservation-guest-check-outs/' . $g->id . '/edit') }}"
                                                   class="pill"
                                                   style="text-decoration:none;display:inline-block;padding:2px 8px;border:1px solid #e5e7eb;border-radius:999px;background:#f9fafb">
                                                    Select
                                                </a>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="9" class="muted" style="padding:10px">No guests found.</td></tr>
                                @endforelse
                                </tbody>

                                {{-- ===== Footer totals (tanpa ubah kolom) ===== --}}
                                <tfoot>
                                    @php
                                    $agg = ReservationMath::aggregateGuestInfoFooter($rg);
                                    @endphp

                                    <tr>
                                    <td colspan="7" class="v" style="text-align:right">Subtotal (before tax)</td>
                                    <td class="v">{{ ReservationView::fmtMoney($agg['sum_base_after_deposits']) }}</td>
                                    <td></td>
                                    </tr>
                                    <tr>
                                    <td colspan="7" class="v" style="text-align:right">Tax</td>
                                    <td class="v">{{ ReservationView::fmtMoney($agg['sum_tax']) }}</td>
                                    <td></td>
                                    </tr>
                                    <tr>
                                    <td colspan="7" class="v" style="text-align:right">TOTAL (Amount Due + Tax)</td>
                                    <td class="v"><strong>{{ ReservationView::fmtMoney($agg['total_due_all']) }}</strong></td>
                                    <td></td>
                                    </tr>
                                    <tr>
                                    <td colspan="7" class="v" style="text-align:right">Less: Guests already checked-out</td>
                                    <td class="v">{{ ReservationView::fmtMoney($agg['checked_grand']) }}</td>
                                    <td></td>
                                    </tr>
                                    <tr>
                                    <td colspan="7" class="v" style="text-align:right">Amount to pay now</td>
                                    <td class="v"><strong>{{ ReservationView::fmtMoney($agg['to_pay_now']) }}</strong></td>
                                    <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
@endif
