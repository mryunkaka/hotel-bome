<?php

namespace App\Support\Accounting;

use App\Models\Bank;
use App\Models\Payment;
use App\Models\BankLedger;
use Illuminate\Support\Str;
use App\Models\AccountLedger;
use App\Models\FacilityBooking;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class LedgerPoster
{
    /**
     * Post jurnal & mutasi bank untuk payment facility booking.
     * - Debit: Kas/Bank
     * - Kredit: Pendapatan (facility + optional catering) &/atau AR
     * - Jika payment melunasi AR reservation/group → buat entry pelunasan (optional, tergantung alurmu).
     */
    public static function postFacilityBookingPayment(Payment $payment, FacilityBooking $booking): void
    {
        // Safety: hanya jalan sekali & amount > 0
        if ((float) $payment->amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Nominal pembayaran harus > 0']);
        }

        // Jika kamu punya flag agar tak dobel-posting, bisa cek di sini (mis. payment->is_posted)
        if (method_exists($payment, 'getAttribute') && (bool) $payment->getAttribute('is_posted') === true) {
            return;
        }

        // Map akun (PENTING: sesuaikan ID akun sesuai master ledger mu)
        $map = self::resolveAccountMap($booking);

        // Pecah komponen revenue: dasar facility + catering + pajak (jika pajak dipisah ke liability)
        $facilityRevenue = max(0, (float) bcsub((string)$booking->subtotal_amount, (string)$booking->discount_amount, 2));
        $cateringRevenue = max(0, (float) $booking->catering_total_amount);
        $taxAmount       = max(0, (float) $booking->tax_amount);

        // Jika total_amount bukan penjumlahan tiga komponen di atas, gunakan total payment saja sbg kontrol
        $grossShould = round($facilityRevenue + $cateringRevenue + $taxAmount, 2);
        $paid        = round((float) $payment->amount, 2);

        DB::transaction(function () use (
            $payment,
            $booking,
            $map,
            $facilityRevenue,
            $cateringRevenue,
            $taxAmount,
            $grossShould,
            $paid
        ) {
            $hotelId = $booking->hotel_id;
            $now     = $payment->paid_at ?? now();
            $userId  = $payment->cashier_id ?? $payment->created_by ?? null;

            // 1) DEBIT Kas / Bank
            self::createAccountLedger([
                'hotel_id'   => $hotelId,
                'date'       => $now,
                'account_id' => $map['cash_or_bank_account_id'],
                'description' => 'Payment Facility Booking #' . $booking->id,
                'debit'      => $paid,
                'credit'     => 0,
                'reference_type' => 'payments',
                'reference_id'   => $payment->id,
                'user_id'        => $userId,
            ]);

            // 1a) Jika metode bank → catat BankLedger juga
            if ($payment->bank_id) {
                self::createBankLedger([
                    'hotel_id'  => $hotelId,
                    'bank_id'   => $payment->bank_id,
                    'date'      => $now,
                    'description' => 'Incoming payment Facility Booking #' . $booking->id,
                    'debit'     => $paid,
                    'credit'    => 0,
                    'reference_type' => 'payments',
                    'reference_id'   => $payment->id,
                    'user_id'        => $userId,
                ]);
            }

            // 2) KREDIT Pendapatan Facility
            if ($facilityRevenue > 0) {
                self::createAccountLedger([
                    'hotel_id'   => $hotelId,
                    'date'       => $now,
                    'account_id' => $map['facility_revenue_account_id'],
                    'description' => 'Facility revenue Booking #' . $booking->id,
                    'debit'      => 0,
                    'credit'     => $facilityRevenue,
                    'reference_type' => 'facility_bookings',
                    'reference_id'   => $booking->id,
                    'user_id'        => $userId,
                ]);
            }

            // 3) KREDIT Pendapatan Catering (jika ada)
            if ($cateringRevenue > 0) {
                self::createAccountLedger([
                    'hotel_id'   => $hotelId,
                    'date'       => $now,
                    'account_id' => $map['catering_revenue_account_id'],
                    'description' => 'Catering revenue Booking #' . $booking->id,
                    'debit'      => 0,
                    'credit'     => $cateringRevenue,
                    'reference_type' => 'facility_bookings',
                    'reference_id'   => $booking->id,
                    'user_id'        => $userId,
                ]);
            }

            // 4) KREDIT Pajak (jika pajak dipisah ke kewajiban)
            if ($taxAmount > 0 && !empty($map['tax_liability_account_id'])) {
                self::createAccountLedger([
                    'hotel_id'   => $hotelId,
                    'date'       => $now,
                    'account_id' => $map['tax_liability_account_id'],
                    'description' => 'Tax payable Booking #' . $booking->id,
                    'debit'      => 0,
                    'credit'     => $taxAmount,
                    'reference_type' => 'facility_bookings',
                    'reference_id'   => $booking->id,
                    'user_id'        => $userId,
                ]);
            }

            // 5) (Opsional) Jika booking sebelumnya menimbulkan AR dan kamu mau pelunasan AR:
            //    DR Kas/Bank ; CR Piutang — ALIH-alih CR pendapatan langsung (tergantung kebijakan pengakuan).
            //    Kalau pakai pendekatan ini, ganti langkah 2–4 di atas dengan: kredit ke AR.
            //    Contoh disiapkan tapi dimatikan; aktifkan jika alurmu berbasis AR.
            /*
            if ($booking->reservation_id && !empty($map['accounts_receivable_account_id'])) {
                self::createAccountLedger([
                    'hotel_id'   => $hotelId,
                    'date'       => $now,
                    'account_id' => $map['accounts_receivable_account_id'],
                    'description'=> 'AR settlement Booking #'.$booking->id,
                    'debit'      => 0,
                    'credit'     => $paid,
                    'reference_type' => 'reservations',
                    'reference_id'   => $booking->reservation_id,
                    'user_id'        => $userId,
                ]);
            }
            */

            // Tandai payment posted (kalau kolomnya ada)
            if ($payment->isFillable('is_posted')) {
                $payment->forceFill(['is_posted' => true])->save();
            }
        });
    }

    /**
     * Pemetaan akun default (edit di sini untuk hubungkan ke master chart of accounts kamu).
     * Return: [
     *   'cash_or_bank_account_id'     => int,
     *   'facility_revenue_account_id' => int,
     *   'catering_revenue_account_id' => int,
     *   'tax_liability_account_id'    => int|null,
     *   'accounts_receivable_account_id' => int|null,
     * ]
     */
    private static function resolveAccountMap(FacilityBooking $booking): array
    {
        // ====== CONTOH: ambil dari LedgerAccount::byCode() / helper milikmu ======
        // Silakan ganti logic ini agar sesuai dengan master akun di DB kamu.
        $hotelId = $booking->hotel_id;

        $cashOrBankId     = self::guessCashOrBankAccountId($hotelId, $booking);
        $facilityRevenue  = self::findAccountIdByCode($hotelId, 'REVENUE_FACILITY') ?? self::findAccountIdByName($hotelId, 'Pendapatan Fasilitas');
        $cateringRevenue  = self::findAccountIdByCode($hotelId, 'REVENUE_CATERING') ?? self::findAccountIdByName($hotelId, 'Pendapatan Catering');
        $taxLiability     = self::findAccountIdByCode($hotelId, 'TAX_OUT') ?? self::findAccountIdByName($hotelId, 'PPN Keluaran');
        $arAccount        = self::findAccountIdByCode($hotelId, 'AR') ?? self::findAccountIdByName($hotelId, 'Piutang Usaha');

        return [
            'cash_or_bank_account_id'        => $cashOrBankId,
            'facility_revenue_account_id'    => $facilityRevenue,
            'catering_revenue_account_id'    => $cateringRevenue,
            'tax_liability_account_id'       => $taxLiability,
            'accounts_receivable_account_id' => $arAccount,
        ];
    }

    private static function guessCashOrBankAccountId(int $hotelId, FacilityBooking $booking): int
    {
        // Jika payment ada bank_id → gunakan akun bank terkait; jika kasir → akun kas.
        // Sesuaikan mapping bank→ledger_account sesuai struktur kamu.
        /** @var Payment|null $p */
        $p = $booking->relationLoaded('payments') ? $booking->payments->first() : null;
        $bankId = $p?->bank_id ?? null;

        if ($bankId) {
            // Misal: Bank punya kolom ledger_account_id
            $bank = Bank::find($bankId);
            if ($bank && $bank->ledger_account_id) {
                return (int) $bank->ledger_account_id;
            }
        }

        // Fallback: akun KAS
        return self::findAccountIdByCode($hotelId, 'CASH') ?? self::findAccountIdByName($hotelId, 'Kas');
    }

    private static function findAccountIdByCode(int $hotelId, string $code): ?int
    {
        $acc = AccountLedger::query()
            ->where('hotel_id', $hotelId)
            ->where('code', $code)
            ->first();
        return $acc?->id;
    }

    private static function findAccountIdByName(int $hotelId, string $name): ?int
    {
        $acc = AccountLedger::query()
            ->where('hotel_id', $hotelId)
            ->where('name', $name)
            ->first();
        return $acc?->id;
    }

    private static function createAccountLedger(array $data): AccountLedger
    {
        // Sesuaikan key dengan kolom aslimu kalau ada perbedaan
        return AccountLedger::create([
            'hotel_id'       => $data['hotel_id'],
            'date'           => $data['date'],
            'account_id'     => $data['account_id'],
            'description'    => $data['description'],
            'debit'          => $data['debit'],
            'credit'         => $data['credit'],
            'reference_type' => $data['reference_type'] ?? null,
            'reference_id'   => $data['reference_id'] ?? null,
            'user_id'        => $data['user_id'] ?? null,
        ]);
    }

    private static function createBankLedger(array $data): BankLedger
    {
        return BankLedger::create([
            'hotel_id'       => $data['hotel_id'],
            'bank_id'        => $data['bank_id'],
            'date'           => $data['date'],
            'description'    => $data['description'],
            'debit'          => $data['debit'],
            'credit'         => $data['credit'],
            'reference_type' => $data['reference_type'] ?? null,
            'reference_id'   => $data['reference_id'] ?? null,
            'user_id'        => $data['user_id'] ?? null,
        ]);
    }

    /**
     * Reverse (void) jurnal payment — misal saat payment dihapus.
     */
    public static function reverseFacilityBookingPayment(Payment $payment, FacilityBooking $booking): void
    {
        DB::transaction(function () use ($payment, $booking) {
            $ekBase = self::entryKey('facility_booking.payment', $booking->id, $payment->id);

            // Hapus baris yang pernah dibuat untuk payment ini (berdasarkan entry_key)
            AccountLedger::query()->where('entry_key', 'like', $ekBase . '%')->delete();
            BankLedger::query()->where('entry_key', $ekBase . '.bank')->delete();
        });
    }

    private static function entryKey(string $prefix, int $bookingId, int $paymentId): string
    {
        // hasil misalnya: facility_booking.payment:123:456:7b0b...
        return $prefix . ':' . $bookingId . ':' . $paymentId . ':' . Str::substr(md5($bookingId . '|' . $paymentId), 0, 8);
    }
}
