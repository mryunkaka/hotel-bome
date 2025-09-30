<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MinibarStockMovement extends Model
{
    use HasFactory;

    protected $table = 'minibar_stock_movements';

    public const TYPE_RESTOCK   = 'restock';
    public const TYPE_SALE      = 'sale';
    public const TYPE_ADJUST    = 'adjustment';
    public const TYPE_WASTAGE   = 'wastage';
    public const TYPE_RETURN    = 'return';

    protected $fillable = [
        'hotel_id',
        'item_id',
        'movement_type',
        'quantity',
        'unit_cost',
        'unit_price',
        'vendor_id',
        'receipt_id',
        'reservation_guest_id',
        'reference_no',
        'performed_by',
        'notes',
        'happened_at',
    ];

    protected $casts = [
        'unit_cost'  => 'decimal:2',
        'unit_price' => 'decimal:2',
        'happened_at' => 'datetime',
    ];

    /* Relationships */
    public function hotel()
    {
        return $this->belongsTo(Hotel::class);
    }
    public function item()
    {
        return $this->belongsTo(MinibarItem::class, 'item_id');
    }
    public function vendor()
    {
        return $this->belongsTo(MinibarVendor::class, 'vendor_id');
    }
    public function receipt()
    {
        return $this->belongsTo(MinibarReceipt::class, 'receipt_id');
    }
    public function reservationGuest()
    {
        return $this->belongsTo(ReservationGuest::class);
    }
    public function performer()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    /* Scopes */
    public function scopeOfType($q, string $type)
    {
        return $q->where('movement_type', $type);
    }

    public function scopeBetween($q, $start, $end)
    {
        return $q->whereBetween('happened_at', [$start, $end]);
    }

    protected static function booted(): void
    {
        static::creating(function ($model) {
            // Jika vendor diisi
            if ($model->vendor_id) {
                $vendor = \App\Models\MinibarVendor::find($model->vendor_id);
                if ($vendor) {
                    // Ambil 4 huruf pertama dari nama vendor tanpa spasi
                    $prefix = strtoupper(substr(str_replace(' ', '', $vendor->name), 0, 4));
                    $model->reference_no = $prefix . now()->format('YmdHis');
                } else {
                    $model->reference_no = 'RSK' . now()->format('YmdHis');
                }
            } else {
                // Jika tanpa vendor
                $model->reference_no = 'RSK' . now()->format('YmdHis');
            }
        });
    }
}
