<?php

namespace App\Domain\Ledger\Events;

use Illuminate\Foundation\Events\Dispatchable;

class ReservationCheckedOut
{
    use Dispatchable;

    public function __construct(
        public int    $hotelId,
        public int    $reservationId,
        public string $date,            // Y-m-d
        public string $method,          // 'cash' | 'edc' | 'transfer' | 'other'
        public ?int   $bankId,          // wajib saat edc/transfer, null saat cash/other
        public int    $roomRevenue,     // tanpa pajak
        public int    $tax,
        public int    $discount,
        public int    $depositUsed,
        public int    $paidAmount,      // uang yang benar2 diterima di kas/bank
        public string $description,
    ) {}
}
