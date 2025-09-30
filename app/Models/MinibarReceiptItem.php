<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MinibarReceiptItem extends Model
{
    use HasFactory;

    protected $table = 'minibar_receipt_items';

    protected $fillable = [
        'receipt_id',
        'item_id',
        'quantity',
        'unit_price',
        'unit_cost',
        'line_total',
        'line_cogs',
    ];

    protected $casts = [
        'quantity'   => 'int',
        'unit_price' => 'decimal:2',
        'unit_cost'  => 'decimal:2',
        'line_total' => 'decimal:2',
        'line_cogs'  => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (MinibarReceiptItem $m) {
            $item = $m->item ?? MinibarItem::find($m->item_id);

            // Ambil harga default kalau belum diisi dari form
            if ($m->unit_price === null || $m->unit_price == 0) {
                $m->unit_price = $item?->default_sale_price ?? 0;
            }
            if ($m->unit_cost === null || $m->unit_cost == 0) {
                $m->unit_cost = $item?->default_cost_price ?? 0;
            }

            $qty = (int) ($m->quantity ?? 0);
            $m->line_total = ($m->unit_price ?? 0) * $qty;
            $m->line_cogs  = ($m->unit_cost  ?? 0) * $qty;
        });

        static::updating(function (MinibarReceiptItem $m) {
            $qty = (int) ($m->quantity ?? 0);
            $m->line_total = ($m->unit_price ?? 0) * $qty;
            $m->line_cogs  = ($m->unit_cost  ?? 0) * $qty;
        });
    }

    /* Relationships */
    public function receipt()
    {
        return $this->belongsTo(MinibarReceipt::class, 'receipt_id');
    }

    public function item()
    {
        return $this->belongsTo(MinibarItem::class, 'item_id');
    }
}
