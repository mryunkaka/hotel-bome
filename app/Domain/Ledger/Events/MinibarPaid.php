<?php

namespace App\Domain\Ledger\Events;

use Illuminate\Foundation\Events\Dispatchable;

class MinibarPaid
{
    use Dispatchable;

    public function __construct(
        public int $hotelId,
        public int $receiptId,
        public string $date,            // Y-m-d
        public string $method,          // cash|transfer|...
        public ?int $bankId,
        public float $amount,           // total tagihan
        public float $tax,              // pajak (0 jika tidak ada)
        public ?float $cogs = 0.0,      // opsional HPP
        public bool $chargeToRoom = false,
        public string $description = 'Minibar',
    ) {}
}
