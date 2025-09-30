<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MinibarDailyClosing extends Model
{
    use HasFactory;

    protected $table = 'minibar_daily_closings';

    protected $fillable = [
        'hotel_id',
        'closing_date',
        'is_balanced',
        'total_sales',
        'total_cogs',
        'total_profit',
        'variance_amount',
        'checklist',
        'notes',
        'closed_by',
        'closed_at',
    ];

    protected $casts = [
        'closing_date'       => 'date',
        'is_balanced'        => 'boolean',
        'total_sales'        => 'decimal:2',
        'total_cogs'         => 'decimal:2',
        'total_profit'       => 'decimal:2',
        'variance_amount'    => 'decimal:2',
        'checklist'          => 'array',
        'closed_at'          => 'datetime',
    ];

    /* Relationships */
    public function hotel()
    {
        return $this->belongsTo(Hotel::class);
    }
    public function items()
    {
        return $this->hasMany(MinibarDailyClosingItem::class, 'daily_closing_id');
    }
    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /* Scopes */
    public function scopeOnDate($q, $date)
    {
        return $q->whereDate('closing_date', $date);
    }
}
