<?php

namespace App\Domain\Ledger\Services;

use App\Models\Bank;
use App\Models\BankLedger;
use App\Models\AccountLedger;
use Illuminate\Support\Facades\DB;

class LedgerPoster
{
    // ====== ROOM CHECKOUT ======
    public function postReservationCheckout(
        int $hotelId,
        int $reservationId,
        string $date,
        string $method,
        ?int $bankId,
        float $roomRevenue,
        float $tax,
        float $discount,
        float $depositApplied,
        float $paidAmount,
        string $description
    ): void {
        DB::transaction(function () use (
            $hotelId,
            $reservationId,
            $date,
            $method,
            $bankId,
            $roomRevenue,
            $tax,
            $discount,
            $depositApplied,
            $paidAmount,
            $description
        ) {
            $refTable = 'reservations';
            $refId    = $reservationId;

            $put = fn(array $row) => $this->putLedger($hotelId, 'room', $refTable, $refId, $date, $method, $description, $row);

            // Credit revenue & tax
            if ($roomRevenue > 0) $put(['account_code' => 'REV_ROOM',   'debit' => 0,           'credit' => $roomRevenue]);
            if ($tax         > 0) $put(['account_code' => 'TAX_OUTPUT', 'debit' => 0,           'credit' => $tax]);
            // Discount as expense (debit) – atau kurangi revenue, pilih satu konsisten
            if ($discount    > 0) $put(['account_code' => 'DISC_EXP',   'debit' => $discount,   'credit' => 0]);
            // Deposit dipakai: liability turun (debit)
            if ($depositApplied > 0) $put(['account_code' => 'DEPOSIT_LIAB', 'debit' => $depositApplied, 'credit' => 0]);
            // Penerimaan
            if ($paidAmount > 0) {
                if ($method === 'cash') {
                    $put(['account_code' => 'CASH_DRAWER', 'debit' => $paidAmount, 'credit' => 0]);
                } else {
                    $bankCode = $this->bankCodeFromId($bankId);
                    $put(['account_code' => $bankCode, 'debit' => $paidAmount, 'credit' => 0]);

                    // Mutasi bank
                    BankLedger::firstOrCreate([
                        'hotel_id'        => $hotelId,
                        'bank_id'         => $bankId,
                        'date'            => $date,
                        'deposit'         => $paidAmount,
                        'withdraw'        => 0,
                        'method'          => $method,
                        'ledger_type'     => 'room',
                        'reference_table' => $refTable,
                        'reference_id'    => $refId,
                    ], [
                        'description'     => $description,
                    ]);
                }
            }
        });
    }

    // ====== MINIBAR PAID ======
    public function postMinibarPaid(
        int $hotelId,
        int $receiptId,
        string $date,
        string $method,
        ?int $bankId,
        float $amount,
        float $tax,
        ?float $cogs,
        bool $chargeToRoom,
        string $description
    ): void {
        DB::transaction(function () use (
            $hotelId,
            $receiptId,
            $date,
            $method,
            $bankId,
            $amount,
            $tax,
            $cogs,
            $chargeToRoom,
            $description
        ) {
            $refTable = 'minibar_receipts';
            $refId    = $receiptId;
            $revenue  = max(0, $amount - $tax);

            $put = fn(array $row) => $this->putLedger($hotelId, 'minibar', $refTable, $refId, $date, $method, $description, $row);

            // Revenue & tax
            if ($revenue > 0) $put(['account_code' => 'REV_MINIBAR', 'debit' => 0, 'credit' => $revenue]);
            if ($tax     > 0) $put(['account_code' => 'TAX_OUTPUT',  'debit' => 0, 'credit' => $tax]);

            // (Opsional) HPP
            if (($cogs ?? 0) > 0) {
                $put(['account_code' => 'COGS_MINIBAR', 'debit' => $cogs, 'credit' => 0]);
                $put(['account_code' => 'INV_MINIBAR', 'debit' => 0,     'credit' => $cogs]);
            }

            if ($chargeToRoom) {
                $put(['account_code' => 'AR_GUEST', 'debit' => $amount, 'credit' => 0, 'method' => null]);
            } else {
                if ($method === 'cash') {
                    $put(['account_code' => 'CASH_DRAWER', 'debit' => $amount, 'credit' => 0]);
                } else {
                    $bankCode = $this->bankCodeFromId($bankId);
                    $put(['account_code' => $bankCode, 'debit' => $amount, 'credit' => 0]);

                    BankLedger::firstOrCreate([
                        'hotel_id'        => $hotelId,
                        'bank_id'         => $bankId,
                        'date'            => $date,
                        'deposit'         => $amount,
                        'withdraw'        => 0,
                        'method'          => $method,
                        'ledger_type'     => 'minibar',
                        'reference_table' => $refTable,
                        'reference_id'    => $refId,
                    ], [
                        'description'     => $description,
                    ]);
                }
            }
        });
    }

    // ====== Util ======
    private function putLedger(
        int $hotelId,
        string $ledgerType,
        string $refTable,
        int $refId,
        string $date,
        ?string $method,
        ?string $desc,
        array $row
    ): void {
        AccountLedger::firstOrCreate([
            'hotel_id'        => $hotelId,
            'ledger_type'     => $ledgerType,
            'reference_table' => $refTable,
            'reference_id'    => $refId,
            'account_code'    => $row['account_code'],
            'method'          => $row['method'] ?? $method,
            'date'            => $date,
            'debit'           => $row['debit']  ?? 0,
            'credit'          => $row['credit'] ?? 0,
        ], [
            'description'     => $desc,
        ]);
    }

    private function bankCodeFromId(?int $bankId): string
    {
        if (! $bankId) return 'BANK_DEFAULT';
        $bank = Bank::find($bankId);
        return $bank?->short_code ? 'BANK_' . strtoupper($bank->short_code) : 'BANK_DEFAULT';
    }
}
