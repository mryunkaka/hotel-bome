<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use App\Domain\Ledger\Events\ReservationCheckedOut;
use App\Domain\Ledger\Listeners\PostReservationCheckoutToLedger;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        ReservationCheckedOut::class => [
            PostReservationCheckoutToLedger::class,
        ],
    ];
}
