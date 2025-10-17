<?php

namespace App\Listeners;

use App\Models\FacilityBooking;
use App\Support\Accounting\LedgerPoster;

class PostLedgerOnPayment
{
    public function handle($event): void
    {
        $payment = $event->payment; // pastikan event expose $payment
        if ($payment->reference_type === 'facility_bookings') {
            if ($booking = FacilityBooking::find($payment->reference_id)) {
                LedgerPoster::postFacilityBookingPayment($payment, $booking);
            }
        }
    }
}
