<?php

namespace App\Observers;

use App\Models\Payment;
use App\Models\FacilityBooking;
use App\Support\Accounting\LedgerPoster;

class PaymentObserver
{
    public function created(Payment $payment): void
    {
        $this->maybePost($payment);
    }

    public function updated(Payment $payment): void
    {
        $this->maybePost($payment);
    }

    protected function maybePost(Payment $payment): void
    {
        // Sesuaikan field status & method milikmu (mis: status === 'paid' atau is_paid == true)
        $isPaid = $payment->status === 'paid' || (bool) $payment->is_paid === true;
        if (!$isPaid) return;

        // Pastikan payment terkait booking fasilitas
        if ($payment->reference_type === 'facility_bookings' && $payment->reference_id) {
            $booking = FacilityBooking::find($payment->reference_id);
            if ($booking) {
                LedgerPoster::postFacilityBookingPayment($payment, $booking);

                // Opsional: ubah status booking jadi PAID & auto-block
                if ($booking->status !== \App\Models\FacilityBooking::STATUS_PAID) {
                    $booking->status = \App\Models\FacilityBooking::STATUS_PAID;
                }
                if (!$booking->is_blocked) {
                    // buat block (kalau belum)
                    \App\Models\FacilityBlock::firstOrCreate([
                        'hotel_id'            => $booking->hotel_id,
                        'facility_id'         => $booking->facility_id,
                        'facility_booking_id' => $booking->id,
                        'start_at'            => $booking->start_at,
                        'end_at'              => $booking->end_at,
                        'active'              => true,
                        'source'              => 'booking',
                    ], [
                        'reason' => 'Auto-block after payment',
                    ]);
                    $booking->is_blocked = true;
                }
                $booking->save();
            }
        }
    }
}
