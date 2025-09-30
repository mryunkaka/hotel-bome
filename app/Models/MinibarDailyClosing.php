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
        'closing_start_at',
        'closing_end_at',
        'total_sales',
        'total_cogs',
        'total_profit',
        'variance_amount',
        'cash_actual',
        'is_balanced',
        'is_locked',
        'notes',
        'closed_by',
        'closed_at',
    ];

    protected $casts = [
        'closing_date'     => 'date',
        'closing_start_at' => 'datetime',
        'closing_end_at'   => 'datetime',
        'closed_at'        => 'datetime',
        'is_balanced'      => 'boolean',
        'is_locked'        => 'boolean',
        'total_sales'      => 'integer',
        'total_cogs'       => 'integer',
        'total_profit'     => 'integer',
        'variance_amount'  => 'integer',
        'cash_actual'      => 'integer',
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
