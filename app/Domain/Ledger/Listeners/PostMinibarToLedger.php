<?php

namespace App\Domain\Ledger\Listeners;

use App\Domain\Ledger\Events\MinibarPaid;
use App\Domain\Ledger\Services\LedgerPoster;

class PostMinibarToLedger
{
    public function __construct(private LedgerPoster $poster) {}

    public function handle(MinibarPaid $e): void
    {
        $this->poster->postMinibarPaid(
            $e->hotelId,
            $e->receiptId,
            $e->date,
            $e->method,
            $e->bankId,
            $e->amount,
            $e->tax,
            $e->cogs,
            $e->chargeToRoom,
            $e->description
        );
    }
}
