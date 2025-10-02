<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MinibarDailyClosingItem extends Model
{
    use HasFactory;

    protected $table = 'minibar_daily_closing_items';

    protected $fillable = [
        'daily_closing_id',
        'item_id',
        'opening_qty',
        'restocked_qty',
        'sold_qty',
        'wastage_qty',
        'adjustment_qty',
        'closing_qty',
        'system_qty',
        'variance_qty',
        'revenue',
        'cogs',
        'profit',
        'notes',
    ];

    protected $casts = [
        'revenue' => 'decimal:2',
        'cogs'    => 'decimal:2',
        'profit'  => 'decimal:2',
    ];

    /* Relationships */
    public function dailyClosing()
    {
        return $this->belongsTo(MinibarDailyClosing::class, 'daily_closing_id');
    }

    public function item()
    {
        return $this->belongsTo(MinibarItem::class, 'item_id');
    }
}
