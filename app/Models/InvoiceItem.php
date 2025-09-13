<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;          // <— pakai DB::afterCommit
use App\Models\Invoice;                      // <— pastikan di-import

/**
 * @property-read \App\Models\Invoice|null $invoice
 */
class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id',
        'item_name',
        'description',
        'qty',
        'unit_price',
        'amount',
    ];

    protected $casts = [
        'qty'        => 'decimal:2',
        'unit_price' => 'decimal:2',
        'amount'     => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    protected static function booted(): void
    {
        // Hitung amount otomatis sebelum simpan
        static::saving(function (self $item): void {
            $qty       = (float) ($item->qty ?? 0);
            $unitPrice = (float) ($item->unit_price ?? 0);
            $item->amount = round($qty * $unitPrice, 2);
        });

        // Setelah tersimpan (baru/ubah)
        static::saved(function (self $item): void {
            DB::afterCommit(function () use ($item) {
                // Recalc invoice saat ini
                $invoice = $item->invoice; // bisa null
                if ($invoice instanceof Invoice) {
                    $invoice->recalculateTotals();
                }

                // Jika invoice_id berubah, recalc juga invoice lama
                if ($item->wasChanged('invoice_id')) {
                    $originalId = $item->getOriginal('invoice_id');
                    if (!empty($originalId) && $originalId !== $item->invoice_id) {
                        $original = Invoice::find($originalId);
                        if ($original instanceof Invoice) {
                            $original->recalculateTotals();
                        }
                    }
                }
            });
        });

        // Saat dihapus
        static::deleted(function (self $item): void {
            DB::afterCommit(function () use ($item) {
                $invoice = $item->invoice ?? Invoice::find($item->invoice_id);
                if ($invoice instanceof Invoice) {
                    $invoice->recalculateTotals();
                }
            });
        });
    }
}
