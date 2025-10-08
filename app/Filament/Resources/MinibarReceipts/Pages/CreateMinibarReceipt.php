<?php

namespace App\Filament\Resources\MinibarReceipts\Pages;

use App\Models\MinibarItem;
use App\Models\MinibarReceipt;
use App\Models\ReservationGuest;
use App\Support\Minibar as MinibarHelper;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;

// ⬇️ Tambahkan ini
use App\Models\AccountLedger;
use App\Models\BankLedger;

class CreateMinibarReceipt extends CreateRecord
{
    protected static string $resource = \App\Filament\Resources\MinibarReceipts\MinibarReceiptResource::class;

    /** URL print yang akan dibuka di tab baru */
    public ?string $printUrl = null;

    /** Setelah save, arahkan ke list */
    protected function getRedirectUrl(): string
    {
        return \App\Filament\Resources\MinibarReceipts\MinibarReceiptResource::getUrl('index');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (($data['method'] ?? null) === 'charge_to_room' && empty($data['reservation_guest_id'])) {
            throw ValidationException::withMessages([
                'reservation_guest_id' => 'Reservation guest wajib untuk "Charge to Room".',
            ]);
        }

        $data['status']      = MinibarHelper::paymentStatusFromMethod($data['method'] ?? 'cash');
        $data['hotel_id']    = $data['hotel_id'] ?? Session::get('active_hotel_id');
        $data['created_by']  = Auth::id();
        $data['receipt_no']  = 'SL' . now()->format('YmdHisv');

        $data['subtotal_amount'] = 0;
        $data['discount_amount'] = 0;
        $data['tax_amount']      = 0;
        $data['total_amount']    = 0;
        $data['total_cogs']      = 0;

        unset($data['notes'], $data['method']); // ← BIARKAN, kita baca method dari $this->data di afterCreate()

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data) {
            $rgId      = $data['reservation_guest_id'] ?? null;
            $hotelId   = $data['hotel_id'];
            $formItems = array_values($this->data['items'] ?? []);

            // cari receipt target (merge ke unpaid jika RG masih in-house)
            $receipt = null;
            if ($rgId && ReservationGuest::whereKey($rgId)->whereNull('actual_checkout')->exists()) {
                $receipt = MinibarReceipt::query()
                    ->where('hotel_id', $hotelId)
                    ->where('reservation_guest_id', $rgId)
                    ->where('status', 'unpaid')
                    ->latest('issued_at')
                    ->first();
            }
            if (! $receipt) {
                $receipt = MinibarReceipt::create($data);
            }

            // normalisasi & insert items
            $toCreate = collect($formItems)
                ->filter(fn($row) => !empty($row['item_id']) && (int)($row['quantity'] ?? 0) > 0)
                ->map(function ($row) {
                    $item  = MinibarItem::find($row['item_id']);
                    $qty   = (int) ($row['quantity'] ?? 0);
                    $price = (float) ($row['unit_price'] ?? ($item->default_sale_price ?? ($item->default_selling_price ?? 0)));
                    $cost  = (float) ($row['unit_cost']  ?? ($item->default_cost_price ?? 0));

                    return [
                        'item_id'    => $row['item_id'],
                        'quantity'   => $qty,
                        'unit_price' => $price,
                        'unit_cost'  => $cost,
                        'line_total' => $price * $qty,
                        'line_cogs'  => $cost  * $qty,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                })
                ->values()
                ->all();

            if (!empty($toCreate)) {
                $receipt->items()->createMany($toCreate);
                foreach ($toCreate as $r) {
                    MinibarItem::whereKey($r['item_id'])->decrement('current_stock', (int)$r['quantity']);
                }
            }

            // recalc totals
            $subtotal  = (float) $receipt->items()->sum('line_total');
            $totalCogs = (float) $receipt->items()->sum('line_cogs');
            $discount  = (float) ($receipt->discount_amount ?? 0);
            $tax       = (float) ($receipt->tax_amount ?? 0);

            $receipt->forceFill([
                'subtotal_amount' => $subtotal,
                'discount_amount' => $discount,
                'tax_amount'      => $tax,
                'total_amount'    => $subtotal - $discount + $tax,
                'total_cogs'      => $totalCogs,
            ])->save();

            // siapkan URL print & dispatch event
            $this->printUrl = route('minibar-receipts.print', ['receipt' => $receipt->getKey()]);
            $this->dispatch('mbr-print', url: $this->printUrl);

            // kosongkan state repeater
            $this->data['items'] = [];

            return $receipt;
        });
    }

    // ⬇️ Tambahkan hook ini — tidak mengubah kode di atas
    protected function afterCreate(): void
    {
        $receipt = $this->record;

        // Baca pilihan form asli (karena 'method' di-unset saat create)
        $method = strtolower((string) ($this->data['method'] ?? 'cash'));
        $bankId = (int) ($this->data['bank_id'] ?? 0);

        // Abaikan ledger kalau charge to room
        if ($method === 'charge_to_room') {
            return;
        }

        $amount = (int) ($receipt->total_amount ?? 0);
        if ($amount <= 0) {
            return;
        }

        $hotelId = (int) $receipt->hotel_id;
        $date    = optional($receipt->issued_at)->toDateString() ?? now()->toDateString();
        $desc    = 'Minibar Receipt #' . $receipt->id;
        $userId  = Auth::id();

        DB::transaction(function () use ($method, $bankId, $amount, $hotelId, $date, $desc, $userId, $receipt) {
            if ($method === 'cash') {
                AccountLedger::create([
                    'hotel_id'        => $hotelId,
                    'ledger_type'     => 'minibar',
                    'reference_table' => 'minibar_receipts',
                    'reference_id'    => (int) $receipt->id,
                    'account_code'    => 'CASH_ON_HAND',
                    'method'          => 'cash',
                    'debit'           => $amount,
                    'credit'          => 0,
                    'date'            => $date,
                    'description'     => $desc,
                    'is_posted'       => true,
                    'posted_at'       => now(),
                    'posted_by'       => $userId,
                ]);
                return;
            }

            if (in_array($method, ['transfer', 'edc'], true) && $bankId > 0) {
                BankLedger::create([
                    'hotel_id'        => $hotelId,
                    'bank_id'         => $bankId,
                    'deposit'         => $amount,
                    'withdraw'        => 0,
                    'date'            => $date,
                    'description'     => $desc,
                    'method'          => $method === 'edc' ? 'edc' : 'transfer',
                    'ledger_type'     => 'minibar',
                    'reference_table' => 'minibar_receipts',
                    'reference_id'    => (int) $receipt->id,
                    'is_posted'       => true,
                    'posted_at'       => now(),
                    'posted_by'       => $userId,
                ]);
            }
        });
    }
}
