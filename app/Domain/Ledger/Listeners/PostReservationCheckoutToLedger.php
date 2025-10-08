<?php

namespace App\Domain\Ledger\Listeners;

use App\Models\BankLedger;
use App\Models\AccountLedger;
use Illuminate\Support\Facades\DB;
use App\Domain\Ledger\Events\ReservationCheckedOut;

class PostReservationCheckoutToLedger
{
    public function handle(ReservationCheckedOut $e): void
    {
        DB::transaction(function () use ($e) {
            // Jika method = CASH => post ke ledger kas (bukan bank)
            if ($e->method === 'cash' || $e->bankId === null) {
                // Contoh akun kas: cari atau buat
                $cash = AccountLedger::firstOrCreate(
                    ['hotel_id' => $e->hotelId, 'code' => 'CASH_ON_HAND'],
                    ['name' => 'Cash on Hand', 'type' => 'asset']
                );

                $cash->entries()->create([
                    'hotel_id'       => $e->hotelId,
                    'date'           => $e->date,
                    'description'    => $e->description,
                    'debit'          => $e->paidAmount,
                    'credit'         => 0,
                    'reference_type' => 'reservation',
                    'reference_id'   => $e->reservationId,
                ]);

                return;
            }

            // Selain cash => anggap masuk ke BANK ledger
            BankLedger::create([
                'hotel_id'       => $e->hotelId,
                'bank_id'        => $e->bankId,
                'date'           => $e->date,
                'description'    => $e->description,
                'amount_in'      => $e->paidAmount, // kolom sesuaikan dg migrasi kamu
                'amount_out'     => 0,
                'reference_type' => 'reservation',
                'reference_id'   => $e->reservationId,
                'posted'         => true,
                'posted_at'      => now(),
            ]);
        });
    }
}
