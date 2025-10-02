{{-- resources/views/prints/minibar/receipt.blade.php --}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    @php
        use Illuminate\Support\Carbon;

        $m  = fn($v) => 'Rp ' . number_format((float)$v, 0, ',', '.');
        $d  = fn($v, $fmt = 'd/m/Y H:i') => $v ? Carbon::parse($v)->format($fmt) : '-';

        /** Input:
         * $hotel, $receipt, $logoData (opsional), $paper (ops), $orientation (ops)
         */
        $paper = strtoupper($paper ?? 'A5');
        $orientation = in_array(strtolower($orientation ?? 'portrait'), ['portrait','landscape'], true)
            ? strtolower($orientation) : 'portrait';

        $hotelRight = array_filter([
            $hotel?->name, $hotel?->address,
            trim(($hotel?->city ? $hotel->city.' ' : '').($hotel?->postcode ?? '')),
            $hotel?->phone ? 'Phone : '.$hotel->phone : null,
            $hotel?->email ?: null,
        ]);

        $rg     = $receipt?->reservationGuest;
        $guest  = $rg?->guest;
        $room   = $rg?->room;

        $items  = collect($receipt?->items ?? []);
        $sub    = (float) ($receipt?->subtotal_amount ?? $items->sum('line_total'));
        $disc   = (float) ($receipt?->discount_amount ?? 0);
        $tax    = (float) ($receipt?->tax_amount ?? 0);
        $grand  = (float) ($receipt?->total_amount ?? ($sub - $disc + $tax));
    @endphp

    <title>{{ $title ?? 'MINIBAR RECEIPT' }} — {{ $receipt?->receipt_no ?? '#' }}</title>

    <style>
        @page { size: {{ $paper }} {{ $orientation }}; margin: 8mm; }
        body { margin:0; padding:0; font-family: DejaVu Sans, Arial, sans-serif; color:#111827; font-size:8.6px; line-height:1.28; }

        table.hdr{width:100%;border-collapse:collapse;margin-bottom:6px}
        .hdr td{vertical-align:top}
        .left{width:35%}.mid{width:30%;text-align:center}.right{width:35%;text-align:right}
        .logo img{height:40px;object-fit:contain}
        .title{font-size:13px;font-weight:700;text-decoration:underline}
        .sub{font-weight:600;margin-top:2px}
        .hotel-meta{font-size:7.6px;line-height:1.25}
        .line{border-top:1px solid #1F2937;margin:6px 0}

        table.info { width:100%; border-collapse:collapse; margin:4px 0 4px }
        table.info td { padding:1px 2px; vertical-align:top; word-wrap:break-word }
        .lbl { color:#374151; font-weight:600; width:22%; }
        .sep { width:8px; text-align:center; }
        .val { width:auto; padding-right:4px; }
        .gap { width:14px; }

        table.grid{width:100%;border-collapse:collapse;table-layout:fixed;font-size:8px}
        .grid thead th{border-top:1px solid #1F2937;border-bottom:1px solid #1F2937;padding:3px 3px;font-weight:700;text-align:left;white-space:nowrap}
        .grid td{border-bottom:1px solid #E5E7EB;padding:3px 3px;vertical-align:top}
        .right{text-align:right} .center{text-align:center}
        .clip{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .muted{color:#6b7280}

        .c-no   { width: 6%;  text-align:center; }
        .c-name { width: 46%; }
        .c-qty  { width: 8%;  text-align:center; }
        .c-price{ width: 20%; text-align:right; white-space:nowrap; }
        .c-amt  { width: 20%; text-align:right; white-space:nowrap; }

        table.total{width:100%;border-collapse:collapse;margin-top:6px;font-size:8.4px}
        .total td{padding:3px 4px}
        .k{color:#374151}
        .v{text-align:right;font-weight:700}
    </style>
</head>
<body>

    {{-- HEADER --}}
    <table class="hdr">
        <tr>
            <td class="left">
                @if (!empty($logoData))
                    <span class="logo"><img src="{{ $logoData }}" alt="Logo"></span>
                @endif
            </td>
            <td class="mid">
                <div class="title">{{ $title ?? 'MINIBAR RECEIPT' }}</div>
                <div class="sub">{{ $receipt?->receipt_no ?? '#' }}</div>
            </td>
            <td class="right">
                <div class="hotel-meta">{!! !empty($hotelRight) ? implode('<br>', array_map('e', $hotelRight)) : '&nbsp;' !!}</div>
            </td>
        </tr>
    </table>

    {{-- INFO --}}
    <table class="info">
        <tr>
            <td class="lbl">Issued At</td>
            <td class="sep">:</td>
            <td class="val">{{ $d($receipt?->issued_at) }}</td>

            <td class="gap"></td>

            <td class="lbl">Status</td>
            <td class="sep">:</td>
            <td class="val">{{ strtoupper(str_replace('_',' ', $receipt?->status ?? 'unpaid')) }}</td>
        </tr>

        <tr>
            <td class="lbl">Guest</td>
            <td class="sep">:</td>
            <td class="val">
                {{ $guest?->name ?? '-' }}
                @if ($room?->room_no) <span class="muted">— Room {{ $room->room_no }}</span>@endif
            </td>

            <td class="gap"></td>

            <td class="lbl">Cashier</td>
            <td class="sep">:</td>
            <td class="val">{{ $receipt?->user?->name ?? '-' }}</td>
        </tr>
    </table>

    <div class="line"></div>

    {{-- ITEMS --}}
    <table class="grid">
        <thead>
        <tr>
            <th class="c-no">#</th>
            <th class="c-name">Item</th>
            <th class="c-qty">Qty</th>
            <th class="c-price">Unit Price</th>
            <th class="c-amt">Line Total</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($items as $i => $row)
            <tr>
                <td class="c-no">{{ $i + 1 }}</td>
                <td class="c-name">
                    <div class="clip">{{ $row->item?->name ?? ('#'.$row->item_id) }}</div>
                </td>
                <td class="c-qty center">{{ (int)$row->quantity }}</td>
                <td class="c-price right">{{ $m($row->unit_price) }}</td>
                <td class="c-amt right"><strong>{{ $m($row->line_total) }}</strong></td>
            </tr>
        @empty
            <tr>
                <td colspan="5" class="center muted">No items.</td>
            </tr>
        @endforelse
        </tbody>
    </table>

    {{-- TOTALS --}}
    <table class="total">
        <tr>
            <td class="k" style="text-align:right">Subtotal</td>
            <td class="v">{{ $m($sub) }}</td>
        </tr>
        @if ($disc > 0)
        <tr>
            <td class="k" style="text-align:right">(-) Discount</td>
            <td class="v">{{ $m($disc) }}</td>
        </tr>
        @endif
        @if ($tax > 0)
        <tr>
            <td class="k" style="text-align:right">Tax</td>
            <td class="v">{{ $m($tax) }}</td>
        </tr>
        @endif
        <tr>
            <td class="k" style="text-align:right"><strong>GRAND TOTAL</strong></td>
            <td class="v"><strong>{{ $m($grand) }}</strong></td>
        </tr>
    </table>

    <div class="line"></div>

    {{-- FOOT --}}
    <table style="width:100%;border-collapse:collapse;font-size:8px">
        <tr>
            <td>Receipt: {{ $receipt?->id ?? '-' }}</td>
            <td style="text-align:center">{{ ($hotel?->city ? $hotel->city.', ' : '') . $d(now()) }}</td>
            <td style="text-align:right">{{ $footerRight ?? 'Thank you & enjoy your stay' }}</td>
        </tr>
    </table>

</body>
</html>
