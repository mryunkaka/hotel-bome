<?php

use App\Models\Bank;
use App\Models\Room;
use App\Models\User;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\BankLedger;
use App\Models\IncomeItem;
use App\Models\Reservation;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Models\AccountLedger;
use App\Models\MinibarReceipt;
use Illuminate\Support\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\ReservationGuest;
use App\Support\ReservationMath;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::redirect('/', '/admin');

Route::get('/admin/minibar-receipts/{receipt}/print', function (MinibarReceipt $receipt) {
    // Relasi aman (hanya field pasti ada)
    $receipt->load([
        'items.item:id,name',
        'reservationGuest.guest:id,name',
        'reservationGuest.room:id,room_no,type',
        'user:id,name',
    ]);

    // Hotel aktif
    $hid   = (int) (session('active_hotel_id') ?? ($receipt->hotel_id ?? 0));
    $hotel = Hotel::find($hid);

    // Logo base64 (opsional)
    $logoData = null;
    if (function_exists('buildPdfLogoData')) {
        $logoData = buildPdfLogoData($hotel?->logo ?? null);
    } else {
        $logoPath = $hotel?->logo ?? null;
        if ($logoPath && ! Str::startsWith($logoPath, ['http://', 'https://'])) {
            try {
                $full = Storage::disk('public')->path($logoPath);
                if (is_file($full)) {
                    $mime     = mime_content_type($full) ?: 'image/png';
                    $logoData = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($full));
                }
            } catch (\Throwable $e) {
                // ignore logo error
            }
        }
    }

    $paper = strtoupper(request('paper', 'A5'));
    $orientation = in_array(strtolower(request('o', 'portrait')), ['portrait', 'landscape'], true)
        ? strtolower(request('o', 'portrait'))
        : 'portrait';

    $view = [
        'paper'       => $paper,
        'orientation' => $orientation,
        'hotel'       => $hotel,
        'logoData'    => $logoData,
        'title'       => 'MINIBAR RECEIPT',
        'receipt'     => $receipt,
        'footerRight' => 'Thank you & enjoy your stay',
    ];

    if (request()->boolean('html')) {
        return response()->view('prints.minibar.receipt', $view);
    }

    $pdf = Pdf::loadView('prints.minibar.receipt', $view)
        ->setPaper($paper, $orientation)
        ->setOption(['isRemoteEnabled' => false]);

    $filename = 'minibar-receipt-' . ($receipt->receipt_no ?? $receipt->id) . '.pdf';

    return response()->stream(fn() => print($pdf->output()), 200, [
        'Content-Type'        => 'application/pdf',
        'Content-Disposition' => 'inline; filename="' . $filename . '"',
    ]);
})->name('minibar-receipts.print');

Route::patch('/admin/rooms/{room}/quick-status', function (Request $request, Room $room) {
    $validated = $request->validate([
        'status' => 'required|in:' . implode(',', [
            Room::ST_VC,
            Room::ST_VCI,
            Room::ST_VD,
            Room::ST_OCC,
            Room::ST_ED,
            Room::ST_OOO,
            Room::ST_HU,
        ]),
    ]);

    $room->forceFill([
        'status' => $request->input('status'), // ✅ bukan $request->status
        'status_changed_at' => now(),
    ])->save();

    return back()->with('room-status-updated', true);
})->name('rooms.quick-status')->middleware(['web', 'auth']);

Route::get('/admin/reservation-guests/{guest}/bill', function (ReservationGuest $guest) {

    // ==== Relasi minimum yang dipakai di view ====
    $reservation = $guest->reservation()
        ->with([
            'guest:id,name,salutation,address,city,phone,email',
            'group:id,name,address,city,phone,handphone,fax,email',
            'creator:id,name',
            'tax:id,percent',
        ])
        ->firstOrFail();

    // Pastikan RG terpilih punya relasi yang dipakai label/identitas di view
    $guest->load([
        'guest:id,name',                 // ⬅️ display_name DIHAPUS
        'room:id,room_no,type,price',
        'reservation.tax',
    ]);

    // ==== Hotel aktif (untuk header/logo) ====
    $hid   = (int) (session('active_hotel_id') ?? ($reservation->hotel_id ?? 0));
    $hotel = Hotel::find($hid);

    // Logo → base64 (lokal) / gunakan helper jika ada
    $logoData = null;
    if (function_exists('buildPdfLogoData')) {
        $logoData = buildPdfLogoData($hotel?->logo ?? null);
    } else {
        $logoPath = $hotel?->logo ?? null;
        if ($logoPath && ! Str::startsWith($logoPath, ['http://', 'https://'])) {
            try {
                $full = Storage::disk('public')->path($logoPath);
                if (is_file($full)) {
                    $mime     = mime_content_type($full) ?: 'image/png';
                    $logoData = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($full));
                }
            } catch (\Throwable $e) {
                // abaikan error logo
            }
        }
    }

    // ==== Parameter UI ====
    $orientation = in_array(strtolower(request('o', 'portrait')), ['portrait', 'landscape'], true)
        ? strtolower(request('o', 'portrait'))
        : 'portrait';

    $mode = strtolower((string) request('mode', 'single'));
    if (! in_array($mode, ['all', 'single', 'remaining'], true)) {
        $mode = 'single';
    }

    // ==== Dataset guests yang akan dicetak ====
    if ($mode === 'all' || $mode === 'remaining') {
        $guestsForView = $reservation->reservationGuests()
            ->with([
                'guest:id,name',          // ⬅️ display_name DIHAPUS
                'room:id,room_no,type,price',
                'reservation.tax',
            ])
            ->orderBy('id')
            ->get();
    } else {
        $guestsForView = collect([$guest]);
    }

    // ==== View-model baris tabel (NO math di blade) ====
    $rows = $guestsForView->map(
        fn(ReservationGuest $g) => ReservationMath::billRow($g) // pastikan billRow() mengembalikan 'guest_name' dari $g->guest->name
    )->all();

    // ==== Aggregate/footer untuk tabel (pakai RG pertama sebagai konteks) ====
    $contextRg = $guestsForView->first() ?: $guest;
    $agg       = ReservationMath::aggregateGuestInfoFooter($contextRg);

    // ==== Tax share (bila butuh logika split di blade) ====
    $taxShare = ReservationMath::taxShareForReservationBilling($contextRg);

    // ==== Breakdown angka untuk header kanan (RG terpilih) ====
    $b   = ReservationMath::guestBaseForBilling($guest);
    $dep = ReservationMath::deposits($guest);
    $taxPerGuest = (int) ($taxShare['tax_per_guest'] ?? 0);

    $billGrand  = (int) ($b['base'] + $taxPerGuest);
    $balance    = max(0, $billGrand - (int) $dep['total']);

    // ==== (Opsional) Split sekadar info (mode=all) ====
    $split = [
        'enabled'        => false,
        'guest_count'    => 0,
        'split_count'    => 0,
        'tax_total'      => (int) ($taxShare['tax_total'] ?? 0),
        'tax_share'      => (int) ($taxShare['tax_per_guest'] ?? 0),
        'less_total'     => 0,
        'to_pay_now'     => 0,
    ];

    if ($mode === 'all') {
        $guestCount = max(1, (int) ($taxShare['count'] ?? $guestsForView->count()));
        // marker contoh (jika Anda memang pakai Payment method=SPLIT):
        $splitCount = Payment::query()
            ->where('reservation_id', $reservation->id)
            ->where('method', 'SPLIT')
            ->count();

        if ($splitCount > 0 && ($taxShare['tax_total'] ?? 0) > 0) {
            $lessTotal = (int) min(
                (int) $agg['total_due_all'],
                (int) ($taxShare['tax_per_guest'] ?? 0) * $splitCount
            );
            $toPayNow = (int) max(0, (int) $agg['total_due_all'] - $lessTotal);

            $split = [
                'enabled'        => true,
                'guest_count'    => $guestCount,
                'split_count'    => $splitCount,
                'tax_total'      => (int) ($taxShare['tax_total'] ?? 0),
                'tax_share'      => (int) ($taxShare['tax_per_guest'] ?? 0),
                'less_total'     => $lessTotal,
                'to_pay_now'     => $toPayNow,
            ];
        }
    }

    // ==== Data untuk view ====
    $view = [
        'paper'       => 'A4',
        'orientation' => $orientation,
        'hotel'       => $hotel,
        'logoData'    => $logoData,

        'title'       => 'GUEST BILL',
        'invoiceId'   => $reservation->id,
        'invoiceNo'   => $reservation->reservation_no ?? ('#' . $reservation->id),
        'generatedAt' => now(),
        'clerkName'   => $reservation->creator?->name,
        'reservation' => $reservation,

        // Identitas ringkas RG terpilih
        'row' => [
            'guest_display' => $guest->guest?->name ?? '-',  // ⬅️ pakai name saja
            'room_no'       => $guest->room?->room_no,
            'category'      => $guest->room?->type,
            'ps'            => (int) ($guest->jumlah_orang
                ?? ((int) ($guest->male ?? 0) + (int) ($guest->female ?? 0) + (int) ($guest->children ?? 0))),
            'actual_in'     => $guest->actual_checkin ?: $guest->expected_checkin,
            'actual_out'    => $guest->actual_checkout ?: $guest->expected_checkout,
        ],

        // Breakdown angka RG terpilih (semua dari ReservationMath)
        'bill' => [
            'rate'                  => (int) $b['rate'],
            'nights'                => (int) $b['nights'],
            'discount_percent'      => (float) $b['disc_percent'],
            'after_disc_per_night'  => (int) $b['rate_after'],
            'room_after_disc'       => (int) $b['rate_after_times_nights'],
            'charge'                => (int) $b['charge'],
            'service'               => (int) $b['service_minibar_unpaid'],
            'extra'                 => (int) $b['extra'],
            'penalty'               => (int) $b['penalty'],
            'subtotal_before_tax'   => (int) $b['base'],
            'tax_percent'           => (float) ($taxShare['percent'] ?? ($reservation->tax?->percent ?? 0)),
            'tax_rp'                => (int) $taxPerGuest,
            'grand'                 => (int) $billGrand,
            'deposit_room'          => (int) $dep['room'],
            'deposit_card'          => (int) $dep['card'],
            'deposit_total'         => (int) $dep['total'],
            'balance'               => (int) $balance,
        ],

        // tabel/loop & aggregate
        'rows'     => $rows,
        'agg'      => $agg,
        'taxShare' => $taxShare,

        // mode cetak & split info
        'mode'  => $mode,
        'split' => $split,
    ];

    // ==== Render ====
    if (request()->boolean('html')) {
        return response()->view('prints.reservations.bill', $view);
    }

    $pdf = Pdf::loadView('prints.reservations.bill', $view)
        ->setPaper('a4', $orientation)
        ->setOption(['isRemoteEnabled' => false]);

    $filename = 'bill-' . ($reservation->reservation_no ?? $reservation->id) . '-' . $guest->id . '.pdf';

    return response()->stream(fn() => print($pdf->output()), 200, [
        'Content-Type'        => 'application/pdf',
        'Content-Disposition' => 'inline; filename="' . $filename . '"',
    ]);
})->name('reservation-guests.bill');

Route::get('/admin/reservation-guests/{guest}/folio', function (ReservationGuest $guest) {
    $reservation = $guest->reservation()
        ->with([
            'guest:id,name,salutation,address,city,phone,email',
            'group:id,name,address,city,phone,handphone,fax,email',
            'creator:id,name',
            'tax:id,percent', // pajak lewat Reservation
        ])
        ->firstOrFail();

    // RG tidak punya relasi tax
    $guest->loadMissing([
        'guest:id,name,salutation,address,city,phone,email',
        'room:id,room_no,type,price',
    ]);

    $hid   = (int) (session('active_hotel_id') ?? ($reservation->hotel_id ?? 0));
    $hotel = \App\Models\Hotel::find($hid);

    // Logo → base64 (local) / URL (remote)
    $allowRemote = false;
    $logoData = null;
    if (function_exists('buildPdfLogoData')) {
        $logoData = buildPdfLogoData($hotel?->logo ?? null);
    } else {
        $logoPath = $hotel?->logo ?? null;
        if ($logoPath && ! \Illuminate\Support\Str::startsWith($logoPath, ['http://', 'https://'])) {
            try {
                $full = \Illuminate\Support\Facades\Storage::disk('public')->path($logoPath);
                if (is_file($full)) {
                    $mime     = mime_content_type($full) ?: 'image/png';
                    $logoData = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($full));
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
    }

    // Perhitungan & data baris untuk view
    $calc = \App\Support\ReservationMath::guestBill($guest, ['tz' => 'Asia/Makassar']);
    $n    = (int) ($calc['nights'] ?? 1);
    $rate = (int) ($calc['rate'] ?? 0);

    // gunakan FQCN agar tidak perlu use
    $nExpected = \App\Support\ReservationMath::nights($guest->expected_checkin, $guest->expected_checkout, 1);

    $pax  = (int) ($guest->jumlah_orang ?? max(
        1,
        (int) ($guest->male ?? 0) + (int) ($guest->female ?? 0) + (int) ($guest->children ?? 0)
    ));

    $view = [
        'paper'       => 'A4',
        'orientation' => request('o', 'portrait'),
        'hotel'       => $hotel,
        'logoData'    => $logoData,
        'invoiceId'   => $reservation->id,
        'invoiceNo'   => $reservation->reservation_no ?? ('#' . $reservation->id),
        'generatedAt' => now(),
        'reservation' => $reservation,
        'clerkName'   => $reservation->creator?->name,
        'row' => [
            'id'               => $guest->id,                 // ← tambahan (dipakai Folio/Minibar)
            'reservation_guest_id' => $guest->id,             // ← tambahan (opsional)
            'room_no'          => $guest->room?->room_no,
            'category'         => $guest->room?->type,
            'rate'             => $rate,
            'nights'           => $n,
            'nights_expected'  => $nExpected, // optional
            'ps'               => $pax,
            'guest_display'    => $guest->guest?->display_name ?? $guest->guest?->name,
            'actual_in'        => $guest->actual_checkin,
            'actual_out'       => $guest->actual_checkout,
            'expected_out'     => $guest->expected_checkout,
            'discount_percent' => (float) ($calc['disc_percent'] ?? 0),
            'tax_percent'      => (float) ($calc['tax_percent'] ?? 0),
            'service'          => (int)   ($calc['service'] ?? 0),
            'extra_bed_total'  => (int)   ($calc['extra'] ?? 0),
        ],
        'calc' => $calc,
    ];

    if (request()->boolean('html')) {
        return response()->view('prints.reservations.folio', $view);
    }

    $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('prints.reservations.folio', $view)
        ->setPaper('a4', $view['orientation'])
        ->setOptions(['isRemoteEnabled' => $allowRemote]);

    $filename = 'folio-' . ($reservation->reservation_no ?? $reservation->id) . '-' . $guest->id . '.pdf';

    return response()->stream(fn() => print($pdf->output()), 200, [
        'Content-Type'        => 'application/pdf',
        'Content-Disposition' => 'inline; filename="' . $filename . '"',
    ]);
})->name('reservation-guests.folio');

Route::post('/admin/reservation-guests/{guest}/checkin', function (Request $request, ReservationGuest $guest) {
    // ===== Muat relasi yang dibutuhkan =====
    $guest->loadMissing([
        'reservation:id,hotel_id,reservation_no,expected_arrival,expected_departure,method,status,deposit,created_at,entry_date,reserved_by_type,guest_id',
        'reservation.creator:id,name',
        'reservation.group:id,name,address,city,phone,handphone,fax,email',
        'reservation.guest:id,name,salutation,address,city,phone,email',
        'room:id,room_no,type,price',
        'guest:id,name',
        'tax:id,percent',
    ]);

    // ===== Validasi minimal =====
    abort_if(!$guest->reservation, 404, 'Reservation tidak ditemukan untuk RG ini.');

    // ===== (Opsional) Hotel aktif =====
    $hid   = (int) (session('active_hotel_id') ?? ($guest->reservation->hotel_id ?? 0));
    $hotel = $hid ? Hotel::find($hid) : null;

    // ===== Parameter hitung =====
    $tz       = 'Asia/Makassar';
    $rate     = (float) ($guest->room_rate ?? $guest->room?->price ?? 0);
    $expected = $guest->reservation->expected_arrival;

    // BATAS HITUNG = actual_checkin RG (kalau belum ada, pakai timestamp yang AKAN diset sekarang)
    $actualCandidate = $guest->actual_checkin ?: \Illuminate\Support\Carbon::now();

    // Hitung penalty expected vs actual_checkin (BUKAN now())
    $pen = \App\Support\ReservationMath::latePenalty(
        $expected,
        $actualCandidate,
        $rate,
        ['tz' => $tz]
    );
    $penaltyHours = (int) ($pen['hours'] ?? 0);
    $penaltyRp    = (int) ($pen['amount'] ?? 0);

    if ($request->boolean('debug')) {
        $rawExpected = optional($guest->reservation)->getAttributes()['expected_arrival'] ?? $expected;
        return response()->json([
            'guest_id'                 => $guest->id,
            'reservation_id'           => $guest->reservation->id,
            'tz'                       => $tz,
            'now_tz'                   => \Illuminate\Support\Carbon::now($tz)->toDateTimeString(),
            'expected_arrival_raw'     => $rawExpected,
            'expected_arrival_casted'  => $expected instanceof \Illuminate\Support\Carbon
                ? $expected->copy()->timezone($tz)->toDateTimeString()
                : ($rawExpected ? \Illuminate\Support\Carbon::parse($rawExpected, $tz)->toDateTimeString() : null),
            'rate'                     => $rate,
            'actual_checkin_used'      => $actualCandidate instanceof \Illuminate\Support\Carbon
                ? $actualCandidate->copy()->timezone($tz)->toDateTimeString()
                : \Illuminate\Support\Carbon::parse($actualCandidate, $tz)->toDateTimeString(),
            'penalty_per_hour'         => \App\Support\ReservationMath::LATE_PENALTY_PER_HOUR,
            'max_percent_cap'          => \App\Support\ReservationMath::LATE_PENALTY_MAX_PERCENT_OF_BASE,
            'penalty_calc'             => $pen,
        ]);
    }

    // Simpan actual_checkin hanya jika belum ada (idempotent), TIDAK menyimpan penalty (sesuai permintaan)
    \Illuminate\Support\Facades\DB::transaction(function () use ($guest, $actualCandidate) {
        if (blank($guest->actual_checkin)) {
            $guest->actual_checkin = $actualCandidate;
            $guest->save();
        }
    });

    // Flash & redirect
    $msg = sprintf(
        'Check-in #%d sukses. Keterlambatan: %d jam → Rp %s (expected %s, actual %s, rate Rp %s).',
        $guest->id,
        $penaltyHours,
        number_format($penaltyRp, 0, ',', '.'),
        $expected ? \Illuminate\Support\Carbon::parse($expected, $tz)->format('d/m/Y H:i') : '-',
        ($actualCandidate instanceof \Illuminate\Support\Carbon
            ? $actualCandidate->copy()->timezone($tz)->format('d/m/Y H:i')
            : \Illuminate\Support\Carbon::parse($actualCandidate, $tz)->format('d/m/Y H:i')),
        number_format($rate, 0, ',', '.')
    );

    if ($request->boolean('print')) {
        return redirect()
            ->route('reservation-guests.print', ['guest' => $guest->id])
            ->with('flash_success', $msg);
    }

    return back()->with('flash_success', $msg);
})->name('reservation-guests.checkin')->middleware(['web', 'auth']);

Route::get('/admin/reservation-guests/{guest}/print', function (ReservationGuest $guest) {
    // === Ambil mode dari query: single | all (default: all) ===
    $mode = strtolower((string) request('mode', 'all'));
    if (! in_array($mode, ['single', 'all'], true)) {
        $mode = 'all';
    }

    $reservation = $guest->reservation()->with([
        'guest:id,name,salutation,address,city,phone,email',
        'group:id,name,address,city,phone,handphone,fax,email',
        'creator:id,name',
        'tax:id,percent',
    ])->firstOrFail();

    $hid   = (int) (session('active_hotel_id') ?? ($reservation->hotel_id ?? 0));
    $hotel = Hotel::find($hid);

    // Muat relasi yang dibutuhkan (jangan minta kolom yg tak ada di reservations)
    $guest->loadMissing([
        'guest:id,name,salutation,city,phone,email,address',
        'room:id,room_no,type,price',
        'reservation:id,hotel_id,reservation_no,expected_arrival,expected_departure,method,status,created_at,entry_date,reserved_by_type,guest_id,checkin_date,checkout_date,id_tax',
        'reservation.creator:id,name',
        'reservation.group:id,name,address,city,phone,handphone,fax,email',
        'reservation.guest:id,name,salutation,address,city,phone,email',
        'reservation.tax:id,percent',
        // penting untuk mode=all
        'reservation.reservationGuests:id,reservation_id,guest_id,room_id,actual_checkin,expected_checkin,actual_checkout,expected_checkout,room_rate,discount_percent,deposit_room,deposit_card,male,female,children,jumlah_orang',
        'reservation.reservationGuests.guest:id,name,salutation',
        'reservation.reservationGuests.room:id,room_no,type,price',
    ]);

    // Logo (tetap)
    $logoData = null;
    if (function_exists('buildPdfLogoData')) {
        $logoData = buildPdfLogoData($hotel?->logo ?? null);
    } else {
        $logoPath = $hotel?->logo ?? null;
        if ($logoPath && ! Str::startsWith($logoPath, ['http://', 'https://'])) {
            try {
                $full = Storage::disk('public')->path($logoPath);
                if (is_file($full)) {
                    $mime     = mime_content_type($full) ?: 'image/png';
                    $logoData = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($full));
                }
            } catch (\Throwable $e) {
            }
        }
    }

    $orientation = in_array(strtolower(request('o', 'portrait')), ['portrait', 'landscape'], true)
        ? strtolower(request('o', 'portrait'))
        : 'portrait';

    $type = strtoupper(trim((string) ($reservation->reserved_by_type ?? 'GUEST')));

    $companyName = null;
    $fax = null;
    $billTo = [];

    if ($type === 'GROUP' && $reservation->group) {
        $party = $reservation->group;
        $companyName = $party->name;
        $billTo = [
            'input'   => 'Company Name',
            'name'    => $party->name,
            'address' => $party->address,
            'city'    => $party->city,
            'phone'   => $party->phone,
            'mobile'  => $party->handphone ?? $party->phone,
            'email'   => $party->email,
        ];
        $fax = $party->fax;
    } else {
        $g = $reservation->guest ?: $guest->guest;
        $companyName = $g?->name;
        $billTo = [
            'input'   => 'Reserved By',
            'name'    => $g?->name,
            'address' => $g?->address,
            'city'    => $g?->city,
            'phone'   => $g?->phone,
            'mobile'  => $g?->phone,
            'email'   => $g?->email,
        ];
        $fax = null;
    }

    // Wajib sudah actual_checkin — hanya untuk mode=single
    if ($mode === 'single') {
        abort_if(blank($guest->actual_checkin), 403, 'Belum check-in.');
    }

    // Nights untuk baris guest yg dipilih (tetap aman untuk mode=all; blade akan hitung sendiri)
    $actualIn  = $guest->actual_checkin;
    $actualOut = $guest->actual_checkout ?: $guest->expected_checkout;
    $nights = ($actualIn && $actualOut)
        ? max(1, Carbon::parse($actualIn)->startOfDay()->diffInDays(Carbon::parse($actualOut)->startOfDay()))
        : 1;

    $rate      = (float) ($guest->room_rate ?? $guest->room?->price ?? 0);
    $pax       = (int) ($guest->jumlah_orang ?? max(1, (int)$guest->male + (int)$guest->female + (int)$guest->children));

    // Tambahan nilai baris
    $discountPercent = (float) ($guest->discount_percent ?? 0);
    $taxPercent      = (float) ($reservation->tax?->percent ?? 0);
    $serviceRp       = (float) ($guest->service ?? 0);
    $extraBedRp      = (float) ($guest->extra_bed_total ?? 0);
    $idTax           = $reservation->id_tax ?? null;

    $subtotal  = $rate * $nights;
    $tax_total = 0.0;
    $total     = $subtotal + $tax_total;

    $clerkName = $reservation->creator?->name;
    $reservedByForPrint = $clerkName ?: ($billTo['name'] ?? null);

    // Deposit dari RG terpilih (total seluruh RG akan dihitung di blade bila mode=all)
    $depositRoom = (int) ($guest->deposit_room ?? 0);
    $depositCard = (int) ($guest->deposit_card ?? 0);

    $viewData = [
        'paper'       => 'A4',
        'orientation' => $orientation,

        'hotel'       => $hotel,
        'logoData'    => $logoData,

        'title'       => 'GUEST CHECK-IN',
        'invoiceId'   => $reservation->id,
        'invoiceNo'   => $reservation->reservation_no ?? ('#' . $reservation->id),
        'issuedAt'    => $reservation->entry_date ?? $reservation->created_at,
        'generatedAt' => now(),

        'payment'     => ['method' => strtolower($reservation->method ?? 'personal'), 'ref' => null],
        'status'      => $reservation->status ?? 'CONFIRM',
        'companyName' => $companyName,
        'fax'         => $fax,
        'reserved_by' => $reservedByForPrint,
        'reservedType' => $type,
        'clerkName'   => $clerkName,

        // === tambahkan ini ===
        'guest' => $guest,        // <— penting untuk mode=single
        'mode'  => $mode,         // sudah ada, tetap

        'billTo'      => $billTo,

        // Baris tunggal (RG ini saja) – dipakai untuk mode=single
        'row'         => [
            'room_no'       => $guest->room?->room_no ?: ('#' . $guest->room_id),
            'category'      => $guest->room?->type ?: '-',
            'rate'          => $rate,
            'nights'        => $nights,
            'ps'            => $pax,
            'guest_display' => $guest->guest?->display_name ?? ($guest->guest?->salutation?->value . ' ' . $guest->guest?->name),
            'actual_in'     => $actualIn,
            'actual_out'    => $guest->actual_checkout,
            'expected_out'  => $guest->expected_checkout,
            'discount_percent' => $discountPercent,
            'tax_percent'      => $taxPercent,
            'service'          => $serviceRp,
            'extra_bed_total'  => $extraBedRp,
            'id_tax'           => $idTax,
        ],

        'subtotal'    => $subtotal,
        'tax_total'   => $tax_total,
        'total'       => $total,

        'notes'       => $reservation->remarks ?? null,
        'footerText'  => 'Printed by ' . ($clerkName ?? 'System'),
        'showSignature' => true,

        // kirim juga objek reservation (untuk mode=all)
        'reservation' => $reservation,

        // Deposit RG terpilih (hanya referensi; total akan dihitung di blade)
        'deposit_room' => $depositRoom,
        'deposit_card' => $depositCard,

        // === kirim flag mode ke blade ===
        'mode' => $mode,
    ];

    if (request()->boolean('html')) {
        return response()->view('prints.reservations.checkin', $viewData);
    }

    $pdf = Pdf::loadView('prints.reservations.checkin', $viewData)->setPaper('a4', $orientation);
    $pdf->setOption(['isRemoteEnabled' => false]);

    $filename = 'checkin-' . ($reservation->reservation_no ?? $reservation->id) . '-' . $guest->id . '.pdf';

    return response()->stream(fn() => print($pdf->output()), 200, [
        'Content-Type'        => 'application/pdf',
        'Content-Disposition' => 'inline; filename="' . $filename . '"',
    ]);
})->name('reservation-guests.print');

Route::get('/admin/reservations/{reservation}/print', function (Reservation $reservation) {
    // ===== Hotel aktif =====
    $hid   = (int) (session('active_hotel_id') ?? ($reservation->hotel_id ?? 0));
    $hotel = Hotel::find($hid);

    // ===== Eager load =====
    $reservation->load([
        'guest:id,name,salutation,address,city,phone,email',
        'group:id,name,address,city,phone,handphone,fax,email',
        'creator:id,name',
        'reservationGuests.guest:id,name,salutation,city,phone,email,address',
        'reservationGuests.room:id,room_no,type,price',
    ]);

    // ===== Logo ke base64 =====
    $logoData = null;
    if (function_exists('buildPdfLogoData')) {
        $logoData = buildPdfLogoData($hotel?->logo ?? null);
    } else {
        $logoPath = $hotel?->logo ?? null;
        if ($logoPath && ! Str::startsWith($logoPath, ['http://', 'https://'])) {
            try {
                $full = Storage::disk('public')->path($logoPath);
                if (is_file($full)) {
                    $mime     = mime_content_type($full) ?: 'image/png';
                    $logoData = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($full));
                }
            } catch (\Throwable $e) {
            }
        }
    }

    // ===== Orientasi kertas =====
    $orientation = in_array(strtolower(request('o', 'portrait')), ['portrait', 'landscape'], true)
        ? strtolower(request('o', 'portrait'))
        : 'portrait';

    // ===== Ringkas booking utk header =====
    $first = $reservation->reservationGuests->first();

    // === Tentukan pihak pemesan (jangan override) ===
    $type = strtoupper(trim((string) ($reservation->reserved_by_type ?? 'GUEST')));

    $companyName = null;
    $fax = null;
    $billTo = [];

    if ($type === 'GROUP' && $reservation->group) {
        $party = $reservation->group;

        $companyName = $party->name;
        $billTo = [
            'input'   => 'Company Name',
            'name'    => $party->name,
            'address' => $party->address,
            'city'    => $party->city,
            'phone'   => $party->phone,
            'mobile'  => $party->handphone ?? $party->phone,
            'email'   => $party->email,
        ];
        $fax = $party->fax;
    } else {
        $g = $reservation->guest ?: ($first?->guest);

        $companyName = $g?->name;
        $billTo = [
            'input'   => 'Reserved By',
            'name'    => $g?->name,
            'address' => $g?->address,
            'city'    => $g?->city,
            'phone'   => $g?->phone,
            'mobile'  => $g?->phone,
            'email'   => $g?->email,
        ];
        $fax = null;
    }

    // === Header tengah singkat (tetap) ===
    $booking = [
        'room_no' => $first?->room?->room_no ?: ($reservation->reservationGuests->count() > 1 ? 'Multiple' : '-'),
        'guest'   => $first?->guest?->display_name ?? $first?->guest?->name ?? '-',
        'period'  => implode(' - ', array_filter([
            $reservation->expected_arrival   ? \Carbon\Carbon::parse($reservation->expected_arrival)->format('d/m/Y H:i') : null,
            $reservation->expected_departure ? \Carbon\Carbon::parse($reservation->expected_departure)->format('d/m/Y H:i') : null,
        ])),
    ];

    // === Bangun rows untuk tabel kamar (minim; biar di-enrich oleh ReservationView) ===
    $rows = [];
    $subtotal = 0;
    foreach ($reservation->reservationGuests as $rg) {
        $in  = $rg->expected_checkin;
        $out = $rg->expected_checkout;

        $nights = ($in && $out) ? max(1, \Carbon\Carbon::parse($in)->diffInDays(\Carbon\Carbon::parse($out))) : 1;
        $rate   = (float) ($rg->room_rate ?? $rg->room?->price ?? 0);
        $subtotal += $rate * $nights;

        $g = $rg->guest;
        $rows[] = [
            'room_no'       => $rg->room?->room_no ?: '-',
            'category'      => $rg->room?->type ?: '-',
            'rate'          => $rate,                    // base rate
            // diskon, tax, deposit per-guest dibiarkan kosong → akan diisi enrichRows()
            'ps'            => (int) ($rg->jumlah_orang ?? max(1, (int)$rg->male + (int)$rg->female + (int)$rg->children)),
            'guest_display' => $g?->display_name ?? $g?->name ?? '-',
            'exp_arr'       => $in,
            'exp_dept'      => $out,
        ];
    }

    // (compat items – opsional)
    $items = array_map(function ($r) {
        return [
            'item_name'   => $r['room_no'] ? 'Room ' . $r['room_no'] : 'Room',
            'description' => trim(collect([
                $r['category']      ? 'Category: ' . $r['category'] : null,
                $r['guest_display'] ? 'Guest: '    . $r['guest_display'] : null,
                $r['exp_arr']       ? 'EXP ARR: '  . \Carbon\Carbon::parse($r['exp_arr'])->format('d/m/Y H:i')  : null,
                $r['exp_dept']      ? 'EXP DEPT: ' . \Carbon\Carbon::parse($r['exp_dept'])->format('d/m/Y H:i') : null,
            ])->filter()->implode(' · ')),
            'qty'         => 1,
            'unit_price'  => (float) $r['rate'],
            'amount'      => (float) $r['rate'],
        ];
    }, $rows);

    $tax_total  = 0;
    $total      = $subtotal + $tax_total;
    $paid_total = (float) ($reservation->deposit ?? 0);

    // ===== Deposit header =====
    $depositRoom = (int) ($reservation->deposit_room ?? 0);
    $depositCard = (int) ($reservation->deposit_card ?? 0); // <— DITAMBAHKAN

    $clerkName = $reservation->creator?->name;
    $reservedByForPrint = $clerkName ?: ($reservation->reserved_by ?: ($billTo['name'] ?? null));

    $viewData = [
        'paper'       => 'A4',
        'orientation' => $orientation,

        'companyName'  => $companyName,
        'fax'          => $fax,
        'reservedType' => $type,

        'hotel'       => $hotel,
        'logoData'    => $logoData,

        'title'       => 'RESERVATION',
        'invoiceId'   => $reservation->id,
        'invoiceNo'   => $reservation->reservation_no ?? ('#' . $reservation->id),
        'issuedAt'    => $reservation->entry_date ?? $reservation->created_at,
        'generatedAt' => now(),

        // header kanan
        'expected_arrival'   => $reservation->expected_arrival,
        'expected_departure' => $reservation->expected_departure,
        'deposit_room'       => $depositRoom,
        'deposit_card'       => $depositCard,   // <— DITAMBAHKAN

        // header kiri (yang sudah ada)
        'payment'     => ['method' => strtolower($reservation->method ?? 'personal'), 'ref' => null],
        'status'      => $reservation->status ?? 'CONFIRM',
        'reserved_by' => $reservedByForPrint,
        'clerkName'   => $clerkName,

        // identitas/billing
        'billTo'  => $billTo,
        'booking' => $booking,

        // tabel
        'rows'  => $rows,
        'items' => $items,

        // totals / catatan
        'subtotal'   => $subtotal,
        'tax_total'  => $tax_total,
        'total'      => $total,
        'paid_total' => $paid_total,

        'notes'         => $reservation->remarks ?? null,
        'footerText'    => 'Printed by ' . ($clerkName ?? 'System'),
        'showSignature' => true,
    ];

    // Preview HTML (opsional)
    if (request()->boolean('html')) {
        return response()->view('prints.reservations.print', $viewData);
    }

    $pdf = Pdf::loadView('prints.reservations.print', $viewData)->setPaper('a4', $orientation);
    $pdf->setOption(['isRemoteEnabled' => false]);

    $filename = 'reservation-' . ($reservation->reservation_no ?? $reservation->id) . '.pdf';

    return response()->stream(fn() => print($pdf->output()), 200, [
        'Content-Type'        => 'application/pdf',
        'Content-Disposition' => 'inline; filename="' . $filename . '"',
    ]);
})->name('reservations.print');

Route::get('/admin/invoices/{invoice}/preview-pdf', function (Invoice $invoice) {
    $hid   = (int) (session('active_hotel_id') ?? 0);
    $hotel = Hotel::find($hid);

    // muat relasi yang dibutuhkan
    $invoice->load([
        'items',
        'booking.room:id,room_no',
        'booking.guest:id,name',
        'taxSetting:id,name,percent',
    ]);

    // siapkan data helper
    $logoData = function_exists('buildPdfLogoData') ? buildPdfLogoData($hotel?->logo ?? null) : null;
    $orientation = in_array(strtolower(request('o', 'portrait')), ['portrait', 'landscape'], true)
        ? strtolower(request('o', 'portrait'))
        : 'portrait';

    // === FIX: definisikan $booking (array) ===
    $booking = null;
    if ($invoice->booking) {
        $period = null;
        $in  = $invoice->booking->check_in_at;
        $out = $invoice->booking->check_out_at;
        if ($in || $out) {
            $fmt = fn($dt) => $dt?->timezone('Asia/Singapore')?->format('Y-m-d');
            $period = trim(($fmt($in) ?? '') . ' → ' . ($fmt($out) ?? ''));
        }

        $booking = [
            'room_no' => $invoice->booking->room?->room_no,
            'guest'   => $invoice->booking->guest?->name,
            'period'  => $period,
        ];
    }

    // kolom tabel items
    $columns = [
        'item_name'   => ['title' => 'Item',        'class' => 'col-name',   'show' => true, 'wrap' => true],
        'description' => ['title' => 'Description', 'class' => 'col-address', 'show' => true, 'wrap' => true],
        'qty'         => ['title' => 'Qty',         'class' => 'col-doc',    'show' => true, 'wrap' => false],
        'unit_price'  => ['title' => 'Unit Price',  'class' => 'col-doc',    'show' => true, 'wrap' => false],
        'amount'      => ['title' => 'Amount',      'class' => 'col-doc',    'show' => true, 'wrap' => false],
    ];

    $fmtMoney = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');

    $data = $invoice->items->map(fn($it) => [
        'item_name'   => $it->item_name,
        'description' => $it->description,
        'qty'         => $it->qty,
        'unit_price'  => (float) $it->unit_price,
        'amount'      => (float) $it->amount,
    ])->toArray();

    $pdf = Pdf::loadView('pdf.invoice', [
        'paper'       => 'A4',
        'orientation' => $orientation,
        'hotel'       => $hotel,
        'logoData'    => $logoData,

        'title'       => 'INVOICE',
        'invoiceId'   => $invoice->id,
        'invoiceNo'   => $invoice->number ?? ('#' . $invoice->id),
        'issuedAt'    => $invoice->date ?? $invoice->created_at,
        'generatedAt' => now(),

        // Bill To (opsional, isi sesuai model kamu)
        'billTo'      => [
            'name'    => $invoice->booking?->guest?->name,
            'address' => $invoice->booking?->guest?->address,
            'phone'   => $invoice->booking?->guest?->phone,
            'email'   => $invoice->booking?->guest?->email,
        ],

        // === kirim $booking yang sudah didefinisikan ===
        'booking'     => $booking,

        'payment'     => ['method' => $invoice->payment_method ?? 'cash', 'ref' => $invoice->payment_ref ?? null],
        'items'       => $data,
        'subtotal'    => $invoice->subtotal,
        'tax_name'    => $invoice->taxSetting?->name ?? 'Tax',
        'tax_percent' => $invoice->taxSetting?->percent,
        'tax_total'   => $invoice->tax_total,
        'total'       => $invoice->total,
        'paid_total'  => $invoice->paid_total ?? null,
        'notes'       => $invoice->notes ?? null,
        'footerText'  => 'Payment is due upon receipt.',
        'showSignature' => true,
    ])->setPaper('a4', $orientation);

    $pdf->setOption(['isRemoteEnabled' => false]);

    return response()->stream(fn() => print($pdf->output()), 200, [
        'Content-Type'        => 'application/pdf',
        'Content-Disposition' => 'inline; filename="invoice-' . $invoice->id . '.pdf"',
    ]);
})->name('invoices.preview-pdf');

Route::get('/admin/income-items/preview-pdf', function () {
    $hid   = (int) (session('active_hotel_id') ?? 0);
    $hotel = Hotel::find($hid);

    $rows = IncomeItem::query()
        ->with('incomeCategory')
        ->when($hid, fn($q) => $q->where('hotel_id', $hid))
        ->orderByDesc('date')
        ->get();

    $columns = [
        'date'        => ['title' => 'Date',       'class' => 'col-doc',    'show' => true, 'wrap' => false],
        'category'    => ['title' => 'Category',   'class' => 'col-name',   'show' => true, 'wrap' => true],
        'description' => ['title' => 'Description', 'class' => 'col-address', 'show' => true, 'wrap' => true],
        'amount'      => ['title' => 'Amount',     'class' => 'col-doc',    'show' => true, 'wrap' => false],
    ];

    $fmtDt = fn($dt) => $dt ? $dt->timezone('Asia/Singapore')->format('Y-m-d H:i') : null;
    $fmtMoney = fn($v) => $v !== null ? number_format((float) $v, 2, '.', ',') : null;

    $data = $rows->map(function ($it) use ($fmtDt, $fmtMoney) {
        return [
            'date'        => $fmtDt($it->date),
            'category'    => $it->incomeCategory?->name,
            'description' => $it->description,
            'amount'      => $fmtMoney($it->amount),
        ];
    })->values()->all();

    $totalAmount = $rows->sum('amount');

    $logoData    = function_exists('buildPdfLogoData') ? buildPdfLogoData($hotel?->logo ?? null) : null;
    $orientation = strtolower(request('o', 'portrait'));
    if (! in_array($orientation, ['portrait', 'landscape'], true)) {
        $orientation = 'portrait';
    }

    $pdf = Pdf::loadView('pdf.report', [
        'title'        => 'Income Items Report',
        'hotel'        => $hotel,
        'logoData'     => $logoData,
        'generatedAt'  => now(),
        'totalCount'   => count($data) . ' | Total Amount: ' . number_format((float) $totalAmount, 2, '.', ','),
        'columns'      => $columns,
        'data'         => $data,
        'paper'        => 'A4',
        'orientation'  => $orientation,
    ])->setPaper('a4', $orientation);

    $pdf->setOption(['isRemoteEnabled' => false]);

    return response()->stream(fn() => print($pdf->output()), 200, [
        'Content-Type'        => 'application/pdf',
        'Content-Disposition' => 'inline; filename="income-items.pdf"',
    ]);
})->name('income-items.preview-pdf');

Route::get('/admin/users/preview-pdf', function () {
    $hid   = (int) (session('active_hotel_id') ?? 0);
    $hotel = Hotel::find($hid); // cuma untuk header/logo

    $rows = User::query()
        ->with('hotel')
        ->orderBy('hotel_id')
        ->orderBy('name')
        ->get();

    $show = fn(string $field) => $rows->contains(fn($r) => filled($r->{$field}));

    $hasAnyRole = $rows->contains(fn($u) => method_exists($u, 'getRoleNames') && $u->getRoleNames()->isNotEmpty());

    $columns = [
        'hotel'            => ['title' => 'Hotel',   'class' => 'col-name',   'show' => true,                       'wrap' => true],
        'name'             => ['title' => 'Name',    'class' => 'col-name',   'show' => true,                       'wrap' => true],
        'email'            => ['title' => 'Email',   'class' => 'col-email',  'show' => true,                       'wrap' => true],
        'email_verified_at' => ['title' => 'Verified', 'class' => 'col-doc',    'show' => $show('email_verified_at'), 'wrap' => false],
        'roles'            => ['title' => 'Roles',   'class' => 'col-family', 'show' => $hasAnyRole,                'wrap' => true],
    ];

    $fmtDt = fn($dt) => $dt ? $dt->timezone('Asia/Singapore')->format('Y-m-d H:i') : null;

    $data = $rows->map(function ($u) use ($fmtDt) {
        $roles = method_exists($u, 'getRoleNames') ? $u->getRoleNames()->join(', ') : null;

        return [
            'hotel'             => $u->hotel?->name,
            'name'              => (string) $u->name,
            'email'             => $u->email,
            'email_verified_at' => $fmtDt($u->email_verified_at),
            'roles'             => $roles,
        ];
    })->values()->all();

    $logoData    = function_exists('buildPdfLogoData') ? buildPdfLogoData($hotel?->logo ?? null) : null;
    $orientation = strtolower(request('o', 'portrait'));
    if (! in_array($orientation, ['portrait', 'landscape'], true)) {
        $orientation = 'portrait';
    }

    $pdf = Pdf::loadView('pdf.report', [
        'title'        => 'Users Report',
        'hotel'        => $hotel,
        'logoData'     => $logoData,
        'generatedAt'  => now(),
        'totalCount'   => count($data),
        'columns'      => $columns,
        'data'         => $data,
        'paper'        => 'A4',
        'orientation'  => $orientation,
    ])->setPaper('a4', $orientation);

    $pdf->setOption(['isRemoteEnabled' => false]);

    return response()->stream(fn() => print($pdf->output()), 200, [
        'Content-Type'        => 'application/pdf',
        'Content-Disposition' => 'inline; filename="users.pdf"',
    ]);
})->name('users.preview-pdf');

Route::get('/admin/hotels/preview-pdf', function () {
    $hid   = (int) (session('active_hotel_id') ?? 0);
    $hotel = Hotel::find($hid); // dipakai untuk header logo/identitas

    $rows = Hotel::query()
        ->orderBy('name')
        ->get();

    // Kolom untuk pdf.report (logo ikut ditampilkan sebagai teks)
    $show = fn(string $field) => $rows->contains(fn($r) => filled($r->{$field}));
    $columns = [
        'name'    => ['title' => 'Name',    'class' => 'col-name',    'show' => true,            'wrap' => true],
        'tipe'    => ['title' => 'Type',    'class' => 'col-tipe',    'show' => $show('tipe'),   'wrap' => false],
        'email'   => ['title' => 'Email',   'class' => 'col-email',   'show' => $show('email'),  'wrap' => true],
        'phone'   => ['title' => 'Phone',   'class' => 'col-phone',   'show' => $show('phone'),  'wrap' => false],
        'address' => ['title' => 'Address', 'class' => 'col-address', 'show' => $show('address'), 'wrap' => true],
        'no_reg'  => ['title' => 'Reg No',  'class' => 'col-reg',     'show' => $show('no_reg'), 'wrap' => false],
        'logo'    => ['title' => 'Logo',    'class' => 'col-doc',     'show' => $show('logo'),   'wrap' => true],
    ];

    $data = $rows->map(function ($h) {
        return [
            'name'    => (string) $h->name,
            'tipe'    => $h->tipe,
            'email'   => $h->email,
            'phone'   => $h->phone,
            'address' => $h->address,
            'no_reg'  => $h->no_reg,
            'logo'    => $h->logo, // tampilkan path/logo filename
        ];
    })->values()->all();

    $logoData    = function_exists('buildPdfLogoData') ? buildPdfLogoData($hotel?->logo ?? null) : null;
    $orientation = strtolower(request('o', 'landscape'));
    if (! in_array($orientation, ['portrait', 'landscape'], true)) {
        $orientation = 'landscape';
    }

    $pdf = Pdf::loadView('pdf.report', [
        'title'        => 'Hotels Report',
        'hotel'        => $hotel,       // header (nama/alamat/email/phone)
        'logoData'     => $logoData,    // base64 untuk header
        'generatedAt'  => now(),
        'totalCount'   => count($data),
        'columns'      => $columns,
        'data'         => $data,
        'paper'        => 'A4',
        'orientation'  => $orientation,
    ])->setPaper('a4', $orientation);

    $pdf->setOption(['isRemoteEnabled' => false]);

    return response()->stream(fn() => print($pdf->output()), 200, [
        'Content-Type'        => 'application/pdf',
        'Content-Disposition' => 'inline; filename="hotels.pdf"',
    ]);
})->name('hotels.preview-pdf');

Route::get('/admin/rooms/preview-pdf', function () {
    $hid   = (int) (session('active_hotel_id') ?? 0);
    $hotel = Hotel::find($hid);

    $rows = Room::query()
        ->when($hid, fn($q) => $q->where('hotel_id', $hid))
        ->orderBy('floor')
        ->orderBy('room_no')
        ->get();

    // Definisi kolom untuk pdf.report (class mengikuti stylesheet kamu)
    $columns = [
        'room_no' => ['title' => 'Room No', 'class' => 'col-doc',   'show' => true, 'wrap' => false],
        'type'    => ['title' => 'Type',    'class' => 'col-name',  'show' => true, 'wrap' => true],
        'floor'   => ['title' => 'Floor',   'class' => 'col-doc',   'show' => true, 'wrap' => false],
        'price'   => ['title' => 'Price',   'class' => 'col-doc',   'show' => true, 'wrap' => false],

        // Flags/boolean → kolom kecil
        'geyser'   => ['title' => 'Geyser',   'class' => 'col-family', 'show' => true, 'wrap' => false],
        'ac'       => ['title' => 'AC',       'class' => 'col-family', 'show' => true, 'wrap' => false],
        'balcony'  => ['title' => 'Balcony',  'class' => 'col-family', 'show' => true, 'wrap' => false],
        'bathtub'  => ['title' => 'Bathtub',  'class' => 'col-family', 'show' => true, 'wrap' => false],
        'hicomode' => ['title' => 'Hi/Co',    'class' => 'col-family', 'show' => true, 'wrap' => false],
        'locker'   => ['title' => 'Locker',   'class' => 'col-family', 'show' => true, 'wrap' => false],
        'freeze'   => ['title' => 'Fridge',   'class' => 'col-family', 'show' => true, 'wrap' => false],
        'internet' => ['title' => 'Internet', 'class' => 'col-family', 'show' => true, 'wrap' => false],
        'intercom' => ['title' => 'Intercom', 'class' => 'col-family', 'show' => true, 'wrap' => false],
        'tv'       => ['title' => 'TV',       'class' => 'col-family', 'show' => true, 'wrap' => false],
        'wardrobe' => ['title' => 'Wardrobe', 'class' => 'col-family', 'show' => true, 'wrap' => false],
    ];

    $fmtPrice = fn($v) => $v !== null ? number_format((float)$v, 2, '.', ',') : null;
    $tick     = fn($b) => $b ? '✓' : '';

    $data = $rows->map(function ($r) use ($fmtPrice, $tick) {
        return [
            'room_no'  => (string) $r->room_no,
            'type'     => (string) $r->type,
            'floor'    => $r->floor !== null ? (int) $r->floor : null,
            'price'    => $fmtPrice($r->price),

            'geyser'   => $tick($r->geyser),
            'ac'       => $tick($r->ac),
            'balcony'  => $tick($r->balcony),
            'bathtub'  => $tick($r->bathtub),
            'hicomode' => $tick($r->hicomode),
            'locker'   => $tick($r->locker),
            'freeze'   => $tick($r->freeze),
            'internet' => $tick($r->internet),
            'intercom' => $tick($r->intercom),
            'tv'       => $tick($r->tv),
            'wardrobe' => $tick($r->wardrobe),
        ];
    })->values()->all();

    $logoData    = function_exists('buildPdfLogoData') ? buildPdfLogoData($hotel?->logo ?? null) : null;
    $orientation = strtolower(request('o', 'landscape'));
    if (! in_array($orientation, ['portrait', 'landscape'], true)) {
        $orientation = 'landscape';
    }

    $pdf = Pdf::loadView('pdf.report', [
        'title'        => 'Rooms Report',
        'hotel'        => $hotel,
        'logoData'     => $logoData,
        'generatedAt'  => now(),
        'totalCount'   => count($data),
        'columns'      => $columns,
        'data'         => $data,
        'paper'        => 'A4',
        'orientation'  => $orientation,
    ])->setPaper('a4', $orientation);

    $pdf->setOption(['isRemoteEnabled' => false]);

    return response()->stream(fn() => print($pdf->output()), 200, [
        'Content-Type'        => 'application/pdf',
        'Content-Disposition' => 'inline; filename="rooms.pdf"',
    ]);
})->name('rooms.preview-pdf');

Route::get('/admin/guests/preview-pdf', function () {
    $hid   = (int) (session('active_hotel_id') ?? 0);
    $hotel = Hotel::find($hid);

    $rows = Guest::query()
        ->when($hid, fn($q) => $q->where('hotel_id', $hid))
        ->latest('id')
        ->get();

    // Tentukan kolom dinamis (show = true kalau ada data)
    $show = fn($field) => $rows->contains(fn($r) => filled($r->{$field}));

    $columns = [
        'name'        => ['title' => 'Name',     'class' => 'col-name',   'show' => true, 'wrap' => true],
        'email'       => ['title' => 'Email',    'class' => 'col-email',  'show' => $show('email')],
        'phone'       => ['title' => 'Phone',    'class' => 'col-phone',  'show' => $show('phone'), 'wrap' => false],
        'address'     => ['title' => 'Address',  'class' => 'col-address', 'show' => $show('address')],
        'nid_no'      => ['title' => 'NID',      'class' => 'col-doc',    'show' => $show('nid_no'), 'wrap' => false],
        'passport_no' => ['title' => 'Passport', 'class' => 'col-doc',    'show' => $show('passport_no'), 'wrap' => false],
        'father'      => ['title' => 'Father',   'class' => 'col-family', 'show' => $show('father')],
        'mother'      => ['title' => 'Mother',   'class' => 'col-family', 'show' => $show('mother')],
        'spouse'      => ['title' => 'Spouse',   'class' => 'col-family', 'show' => $show('spouse')],
    ];

    // Mapping rows -> array sesuai $columns
    $data = $rows->map(function ($g) {
        return [
            'name'        => $g->name,
            'email'       => $g->email,
            'phone'       => $g->phone,
            'address'     => $g->address,
            'nid_no'      => $g->nid_no,
            'passport_no' => $g->passport_no,
            'father'      => $g->father,
            'mother'      => $g->mother,
            'spouse'      => $g->spouse,
        ];
    })->values()->all();

    // logo base64 (pakai helper yang sudah kamu punya)
    $logoData = buildPdfLogoData($hotel?->logo ?? null);

    $orientation = 'landscape'; // landscape atau 'portrait' (sesuai pilihan)

    $pdf = Pdf::loadView('pdf.report', [
        'title'       => 'Guest Directory Report',
        'hotel'       => $hotel,
        'logoData'    => $logoData,
        'generatedAt' => now(),
        'totalCount'  => count($data),
        'columns'     => $columns,
        'data'        => $data,
        'orientation' => $orientation,
    ])->setPaper('a4', $orientation);

    // Pastikan inline preview:
    $pdf->setOption(['isRemoteEnabled' => false]);

    return response()->stream(fn() => print($pdf->output()), 200, [
        'Content-Type'        => 'application/pdf',
        'Content-Disposition' => 'inline; filename="guests.pdf"',
    ]);
})->name('guests.preview-pdf');

Route::get('/admin/bookings/preview-pdf', function () {
    $hid   = (int) (session('active_hotel_id') ?? 0);
    $hotel = Hotel::find($hid);

    $rows = Booking::query()
        ->with(['room', 'guest'])
        ->when($hid, fn($q) => $q->where('hotel_id', $hid))
        ->latest('check_in_at')
        ->get();

    // kolom mana yang punya isi? (boleh diubah kalau ingin selalu muncul)
    $has = fn(string $field) => $rows->contains(fn($b) => filled($b->{$field}));

    // definisi kolom untuk layout reusable
    $columns = [
        'room'         => ['title' => 'Room',        'class' => 'col-name',   'show' => true,       'wrap' => true],
        'guest'        => ['title' => 'Guest',       'class' => 'col-name',   'show' => true,       'wrap' => true],
        'check_in_at'  => ['title' => 'Check In',    'class' => 'col-doc',    'show' => true,       'wrap' => false],
        'check_out_at' => ['title' => 'Check Out',   'class' => 'col-doc',    'show' => $has('check_out_at'), 'wrap' => false],
        'status'       => ['title' => 'Status',      'class' => 'col-family', 'show' => true,       'wrap' => false],
        'notes'        => ['title' => 'Notes',       'class' => 'col-address', 'show' => $has('notes'),        'wrap' => true],
    ];

    // mapping baris → array sesuai keys di $columns (format waktu SGT & null-safe)
    $fmt = fn($dt) => $dt ? $dt->timezone('Asia/Singapore')->format('Y-m-d H:i') : null;

    $data = $rows->map(function ($b) use ($fmt) {
        return [
            'room'         => optional($b->room)->room_no ?? optional($b->room)->number ?? '-',
            'guest'        => optional($b->guest)->name ?? optional($b->guest)->email ?? '-',
            'check_in_at'  => $fmt($b->check_in_at),
            'check_out_at' => $fmt($b->check_out_at),
            'status'       => $b->status ?: '-',
            'notes'        => $b->notes,
        ];
    })->values()->all();

    // logo base64 (helper yang sudah kita punya)
    $logoData = function_exists('buildPdfLogoData') ? buildPdfLogoData($hotel?->logo ?? null) : null;

    $orientation = 'portrait'; // atau 'portrait' (sesuai pilihan)

    $pdf = Pdf::loadView('pdf.report', [
        'title'       => 'Bookings Report',
        'hotel'       => $hotel,
        'logoData'    => $logoData,
        'generatedAt' => now(),                  // timezone diterapkan saat render string
        'totalCount'  => count($data),
        'columns'     => $columns,
        'data'        => $data,
        'orientation' => $orientation,
    ])
        // pilih layout kertas: ganti 'landscape' ↔ 'portrait' sesuai kebutuhan
        ->setPaper('a4', $orientation);

    // dompdf tidak butuh remote url karena logo sudah base64
    $pdf->setOption(['isRemoteEnabled' => false]);

    return response()->stream(fn() => print($pdf->output()), 200, [
        'Content-Type'        => 'application/pdf',
        'Content-Disposition' => 'inline; filename="bookings.pdf"',
    ]);
})->name('bookings.preview-pdf');

Route::get('/admin/banks/preview-pdf', function () {
    $hid   = (int) (session('active_hotel_id') ?? 0);
    $hotel = Hotel::find($hid);

    $rows = Bank::query()
        ->when($hid, fn($q) => $q->where('hotel_id', $hid))
        ->orderBy('name')
        ->get();

    // Helper kecil: cek apakah ada isi pada sebuah field di kumpulan data
    $has = fn(string $field) => $rows->contains(fn($r) => filled($r->{$field}));

    // Definisi kolom untuk view pdf.report (judul, kelas lebar kolom, dan apakah ditampilkan)
    $columns = [
        'name'       => ['title' => 'Name',       'class' => 'col-name',    'show' => true,          'wrap' => true],
        'branch'     => ['title' => 'Branch',     'class' => 'col-name',    'show' => $has('branch'),     'wrap' => true],
        'account_no' => ['title' => 'Account No', 'class' => 'col-doc',     'show' => $has('account_no'), 'wrap' => false],
        'address'    => ['title' => 'Address',    'class' => 'col-address', 'show' => $has('address'),    'wrap' => true],
        'phone'      => ['title' => 'Phone',      'class' => 'col-phone',   'show' => $has('phone'),      'wrap' => false],
        'email'      => ['title' => 'Email',      'class' => 'col-email',   'show' => $has('email'),      'wrap' => true],
    ];

    // Mapping data → array sesuai keys di $columns
    $data = $rows->map(function ($b) {
        return [
            'name'       => $b->name ?: '-',
            'branch'     => $b->branch,
            'account_no' => $b->account_no,
            'address'    => $b->address,
            'phone'      => $b->phone,
            'email'      => $b->email,
        ];
    })->values()->all();

    // Logo base64 (helper yang sudah ada)
    $logoData = function_exists('buildPdfLogoData') ? buildPdfLogoData($hotel?->logo ?? null) : null;

    // Orientasi kertas (query ?o=portrait|landscape)
    $orientation = strtolower(request('o', 'portrait'));
    if (! in_array($orientation, ['portrait', 'landscape'], true)) {
        $orientation = 'portrait';
    }

    $pdf = Pdf::loadView('pdf.report', [
        'title'       => 'Banks',
        'hotel'       => $hotel,
        'logoData'    => $logoData,
        'generatedAt' => now(),         // formatting timezone dilakukan di view
        'totalCount'  => count($data),
        'columns'     => $columns,      // pdf.report akan render <thead> sesuai urutan $columns
        'data'        => $data,         // tiap baris harus punya key yang sama dengan $columns
        'paper'       => 'A4',          // untuk CSS @page di view
        'orientation' => $orientation,  // untuk CSS @page di view
    ])->setPaper('a4', $orientation);

    $pdf->setOption(['isRemoteEnabled' => false]);

    return response()->stream(fn() => print($pdf->output()), 200, [
        'Content-Type'        => 'application/pdf',
        'Content-Disposition' => 'inline; filename="banks.pdf"',
    ]);
})->name('banks.preview-pdf');

Route::get('/admin/bank-ledgers/preview-pdf', function () {
    $hid   = (int) (session('active_hotel_id') ?? 0);
    $hotel = Hotel::find($hid);

    $rows = BankLedger::query()
        ->with('bank')
        ->when($hid, fn($q) => $q->where('hotel_id', $hid))
        ->orderBy('date')        // ledger biasanya urut naik
        ->orderBy('id')
        ->get();

    // Helper kecil untuk cek apakah ada isi pada field tertentu
    $has = fn(string $field) => $rows->contains(fn($r) => filled($r->{$field}));

    // ===== Definisi kolom untuk view pdf.report
    // class kolom menyesuaikan CSS di pdf.report (col-name, col-doc, col-phone, col-address, col-email, col-family)
    $columns = [
        'date'       => ['title' => 'Date',       'class' => 'col-doc',     'show' => true,          'wrap' => false],
        'bank'       => ['title' => 'Bank',       'class' => 'col-name',    'show' => true,          'wrap' => true],
        'account_no' => ['title' => 'Account No', 'class' => 'col-doc',     'show' => $rows->contains(fn($r) => filled(optional($r->bank)->account_no)), 'wrap' => false],
        'notes'      => ['title' => 'Notes',      'class' => 'col-address', 'show' => $has('notes'), 'wrap' => true],
        'deposit'    => ['title' => 'Deposit',    'class' => 'col-phone',   'show' => $has('deposit'),   'wrap' => false],
        'withdraw'   => ['title' => 'Withdraw',   'class' => 'col-phone',   'show' => $has('withdraw'),  'wrap' => false],
    ];

    // Formatter
    $fmtDate = fn($d) => $d ? $d->timezone('Asia/Singapore')->format('Y-m-d') : null;
    $fmtAmt  = fn($v) => $v === null ? null : number_format((float) $v, 0, '.', ',');

    // Mapping data -> sesuai keys di $columns
    $data = $rows->map(function ($x) use ($fmtDate, $fmtAmt) {
        return [
            'date'       => $fmtDate($x->date),
            'bank'       => optional($x->bank)->name ?: '-',
            'account_no' => optional($x->bank)->account_no,
            'notes'      => $x->notes,
            'deposit'    => $fmtAmt($x->deposit),
            'withdraw'   => $fmtAmt($x->withdraw),
        ];
    })->values()->all();

    // Totals
    $totalDeposit  = $rows->sum('deposit');
    $totalWithdraw = $rows->sum('withdraw');

    // Tambah baris TOTAL (di kolom notes atau bank—mana yang tampil)
    if (! empty($data)) {
        $labelColumn = $columns['notes']['show'] ? 'notes' : 'bank';
        // siapkan baris kosong dengan key sesuai kolom
        $totalRow = [];
        foreach ($columns as $key => $cfg) {
            if (! $cfg['show']) continue;
            $totalRow[$key] = null;
        }
        $totalRow[$labelColumn] = 'TOTAL';
        if ($columns['deposit']['show'])  $totalRow['deposit']  = $fmtAmt($totalDeposit);
        if ($columns['withdraw']['show']) $totalRow['withdraw'] = $fmtAmt($totalWithdraw);
        $data[] = $totalRow;
    }

    // Logo base64
    $logoData = function_exists('buildPdfLogoData') ? buildPdfLogoData($hotel?->logo ?? null) : null;

    // Orientasi kertas (?o=portrait|landscape)
    $orientation = strtolower(request('o', 'portrait'));
    if (! in_array($orientation, ['portrait', 'landscape'], true)) {
        $orientation = 'portrait';
    }

    $pdf = Pdf::loadView('pdf.report', [
        'title'        => 'Bank Ledger',
        'hotel'        => $hotel,
        'logoData'     => $logoData,
        'generatedAt'  => now(),
        'totalCount'   => count($data),
        'columns'      => $columns,
        'data'         => $data,
        'paper'        => 'A4',          // dipakai oleh CSS @page di view
        'orientation'  => $orientation,  // dipakai oleh CSS @page di view
    ])->setPaper('a4', $orientation);

    $pdf->setOption(['isRemoteEnabled' => false]);

    return response()->stream(fn() => print($pdf->output()), 200, [
        'Content-Type'        => 'application/pdf',
        'Content-Disposition' => 'inline; filename="bank-ledgers.pdf"',
    ]);
})->name('bank-ledgers.preview-pdf');

Route::get('/admin/account-ledgers/preview-pdf', function () {
    $hid   = (int) (session('active_hotel_id') ?? 0);
    $hotel = Hotel::find($hid);

    $rows = AccountLedger::query()
        ->when($hid, fn($q) => $q->where('hotel_id', $hid))
        ->orderBy('date')        // ledger enaknya urut naik
        ->orderBy('id')
        ->get();

    // Helper: ambil attribute pertama yang terisi dari daftar kandidat
    $pick = function ($row, array $candidates) {
        foreach ($candidates as $k) {
            // gunakan isset + filled untuk atribut Eloquent
            if (array_key_exists($k, $row->getAttributes()) || $row->hasGetMutator($k) || $row->hasAttributeMutator($k)) {
                if (filled($row->{$k})) return $row->{$k};
            } elseif (isset($row->{$k}) && filled($row->{$k})) {
                return $row->{$k};
            }
        }
        return null;
    };

    // Deteksi field yang tersedia di dataset
    $has = fn(string $field) => $rows->contains(fn($r) => filled($r->{$field}));

    $accountKey     = $rows->first() ? (function () use ($rows, $pick) {
        $first = $rows->first();
        return $pick($first, ['account', 'account_name', 'name']) ?:
            null;
    })() : null;

    $descKey        = $rows->first() ? (function () use ($rows, $pick) {
        $first = $rows->first();
        return $pick($first, ['description', 'notes', 'memo', 'remark']) ?:
            null;
    })() : null;

    $refKey         = $rows->first() ? (function () use ($rows, $pick) {
        $first = $rows->first();
        return $pick($first, ['ref_no', 'reference', 'voucher_no', 'doc_no']) ?:
            null;
    })() : null;

    $balanceKey     = $rows->first() ? (function () use ($rows, $pick) {
        $first = $rows->first();
        return $pick($first, ['balance', 'running_balance']) ?:
            null;
    })() : null;

    // Definisi kolom (ikuti kelas CSS pada view pdf.report)
    $columns = [
        'date'    => ['title' => 'Date',    'class' => 'col-doc',     'show' => true,                'wrap' => false],
        'account' => ['title' => 'Account', 'class' => 'col-name',    'show' => (bool) $accountKey,  'wrap' => true],
        'ref'     => ['title' => 'Ref No.', 'class' => 'col-doc',     'show' => (bool) $refKey,      'wrap' => false],
        'desc'    => ['title' => 'Description', 'class' => 'col-address', 'show' => (bool) $descKey,   'wrap' => true],
        'debit'   => ['title' => 'Debit',   'class' => 'col-phone',   'show' => $rows->contains(fn($r) => filled($r->debit)),   'wrap' => false],
        'credit'  => ['title' => 'Credit',  'class' => 'col-phone',   'show' => $rows->contains(fn($r) => filled($r->credit)),  'wrap' => false],
        'balance' => ['title' => 'Balance', 'class' => 'col-phone',   'show' => (bool) $balanceKey,  'wrap' => false],
    ];

    // Formatter
    $fmtDate = fn($d) => $d ? $d->timezone('Asia/Singapore')->format('Y-m-d') : null;
    $fmtAmt  = fn($v) => $v === null ? null : number_format((float) $v, 0, '.', ',');

    // Mapping data → sesuai keys kolom
    $data = $rows->map(function ($r) use ($fmtDate, $fmtAmt, $pick, $accountKey, $descKey, $refKey, $balanceKey) {
        return [
            'date'    => $fmtDate($r->date),
            'account' => $accountKey ? ($pick($r, [$accountKey]) ?? '-') : null,
            'ref'     => $refKey     ? ($pick($r, [$refKey])     ?? '-') : null,
            'desc'    => $descKey    ? ($pick($r, [$descKey])    ?? '-') : null,
            'debit'   => $fmtAmt($r->debit),
            'credit'  => $fmtAmt($r->credit),
            'balance' => $balanceKey ? $fmtAmt($pick($r, [$balanceKey])) : null,
        ];
    })->values()->all();

    // Total
    $totalDebit  = $rows->sum('debit');
    $totalCredit = $rows->sum('credit');

    // Tambahkan baris TOTAL di akhir
    if (! empty($data)) {
        // letakkan label TOTAL di kolom description kalau ada, else di account
        $labelCol = $columns['desc']['show'] ? 'desc' : ($columns['account']['show'] ? 'account' : 'date');
        $totalRow = [];
        foreach ($columns as $key => $cfg) {
            if (! $cfg['show']) continue;
            $totalRow[$key] = null;
        }
        $totalRow[$labelCol] = 'TOTAL';
        if ($columns['debit']['show'])  $totalRow['debit']  = $fmtAmt($totalDebit);
        if ($columns['credit']['show']) $totalRow['credit'] = $fmtAmt($totalCredit);
        $data[] = $totalRow;
    }

    // Logo base64 (aman walau helper tidak ada)
    $logoData = function_exists('buildPdfLogoData') ? buildPdfLogoData($hotel?->logo ?? null) : null;

    // Orientasi (?o=portrait|landscape)
    $orientation = strtolower(request('o', 'portrait'));
    if (! in_array($orientation, ['portrait', 'landscape'], true)) {
        $orientation = 'portrait';
    }

    $pdf = Pdf::loadView('pdf.report', [
        'title'        => 'Account Ledger',
        'hotel'        => $hotel,
        'logoData'     => $logoData,
        'generatedAt'  => now(),
        'totalCount'   => count($data),
        'columns'      => $columns,
        'data'         => $data,
        'paper'        => 'A4',         // dipakai @page di view
        'orientation'  => $orientation, // dipakai @page di view
    ])->setPaper('a4', $orientation);

    $pdf->setOption(['isRemoteEnabled' => false]);

    return response()->stream(fn() => print($pdf->output()), 200, [
        'Content-Type'        => 'application/pdf',
        'Content-Disposition' => 'inline; filename="account-ledgers.pdf"',
    ]);
})->name('account-ledgers.preview-pdf');

if (! function_exists('buildPdfLogoData')) {
    function buildPdfLogoData(?string $hotelLogoPath): ?string
    {
        $candidates = [];

        if ($hotelLogoPath) {
            $candidates[] = ['type' => 'storage', 'path' => $hotelLogoPath];
            $candidates[] = ['type' => 'public',  'path' => $hotelLogoPath];
        }

        $candidates[] = ['type' => 'public',         'path' => 'logo.png'];
        $candidates[] = ['type' => 'public',         'path' => 'images/logo.png'];
        $candidates[] = ['type' => 'storage-public', 'path' => 'images/logo.png'];

        foreach ($candidates as $cand) {
            $abs = null;

            if ($cand['type'] === 'storage' && \Illuminate\Support\Facades\Storage::disk('public')->exists($cand['path'])) {
                $abs = \Illuminate\Support\Facades\Storage::disk('public')->path($cand['path']);
            } elseif ($cand['type'] === 'public') {
                $tmp = public_path($cand['path']);
                if (is_file($tmp)) $abs = $tmp;
            } elseif ($cand['type'] === 'storage-public') {
                $tmp = \Illuminate\Support\Facades\Storage::disk('public')->path($cand['path']);
                if (is_file($tmp)) $abs = $tmp;
            }

            if (! $abs) continue;

            $bytes = @file_get_contents($abs);
            if ($bytes === false || $bytes === '') continue;

            $ext  = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
            $mime = @mime_content_type($abs) ?: null;

            if (! $mime) {
                if (str_starts_with($bytes, "\x89PNG")) {
                    $mime = 'image/png';
                } elseif (str_starts_with($bytes, "\xFF\xD8")) {
                    $mime = 'image/jpeg';
                } elseif (str_starts_with($bytes, "GIF8")) {
                    $mime = 'image/gif';
                } elseif (preg_match('/^<\?xml|<svg/i', substr($bytes, 0, 200))) {
                    $mime = 'image/svg+xml';
                } elseif ($ext === 'webp') {
                    $mime = 'image/webp';
                } else {
                    $mime = match ($ext) {
                        'png' => 'image/png',
                        'jpg', 'jpeg' => 'image/jpeg',
                        'gif' => 'image/gif',
                        'svg' => 'image/svg+xml',
                        'webp' => 'image/webp',
                        default => 'application/octet-stream',
                    };
                }
            }

            // Dompdf tidak support WEBP → coba konversi ke PNG pakai GD
            if ($mime === 'image/webp' || $ext === 'webp') {
                $pngBytes = webpToPngBytes($bytes);
                if ($pngBytes !== null) {
                    return 'data:image/png;base64,' . base64_encode($pngBytes);
                }
                // gagal konversi webp → coba kandidat berikutnya
                continue;
            }

            if ($mime === 'image/svg+xml') {
                return 'data:image/svg+xml;base64,' . base64_encode($bytes);
            }

            if (in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true)) {
                return 'data:' . $mime . ';base64,' . base64_encode($bytes);
            }

            // fallback: paksa jadi PNG pakai GD bila bisa
            $converted = forceToPngBytes($bytes);
            if ($converted !== null) {
                return 'data:image/png;base64,' . base64_encode($converted);
            }
        }

        return null;
    }

    /**
     * Konversi WEBP → PNG pakai GD (tanpa Imagick).
     */
    function webpToPngBytes(string $bytes): ?string
    {
        if (! function_exists('imagecreatefromwebp') || ! function_exists('imagepng')) {
            return null;
        }
        $src = @imagecreatefromwebp('data://image/webp;base64,' . base64_encode($bytes));
        if ($src === false) return null;

        ob_start();
        imagepng($src);
        imagedestroy($src);
        $out = ob_get_clean();

        return $out !== false && $out !== '' ? $out : null;
    }

    /**
     * Paksa gambar (png/jpg/gif/bmp tergantung build GD) → PNG bytes.
     */
    function forceToPngBytes(string $bytes): ?string
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagepng')) {
            return null;
        }
        $src = @imagecreatefromstring($bytes);
        if ($src === false) return null;

        ob_start();
        imagepng($src);
        imagedestroy($src);
        $out = ob_get_clean();

        return $out !== false && $out !== '' ? $out : null;
    }
}
