<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoomDailyClosing extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'room_daily_closings';

    protected $fillable = [
        'hotel_id',
        'closing_date',
        'is_balanced',
        'total_room_revenue',
        'total_tax',
        'total_discount',
        'total_deposit',
        'total_refund',
        'total_payment',
        'variance_amount',
        'checklist',
        'notes',
        'closing_start_at',
        'closing_end_at',
        'cash_actual',
        'is_locked',
        'closed_by',
        'closed_at',
    ];

    protected $casts = [
        'closing_date'      => 'date',
        'is_balanced'       => 'boolean',
        'is_locked'         => 'boolean',
        'checklist'         => 'array',
        'closing_start_at'  => 'datetime',
        'closing_end_at'    => 'datetime',
        'closed_at'         => 'datetime',
        'total_room_revenue' => 'decimal:2',
        'total_tax'         => 'decimal:2',
        'total_discount'    => 'decimal:2',
        'total_deposit'     => 'decimal:2',
        'total_refund'      => 'decimal:2',
        'total_payment'     => 'decimal:2',
        'variance_amount'   => 'decimal:2',
        'cash_actual'       => 'decimal:2',
    ];

    /*
    |--------------------------------------------------------------------------
    | RELATIONSHIPS
    |--------------------------------------------------------------------------
    */

    /** Hotel induk closing ini */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    /** User yang menutup closing */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /*
    |--------------------------------------------------------------------------
    | ACCESSORS / HELPERS
    |--------------------------------------------------------------------------
    */

    /** Label status closing */
    public function getStatusLabelAttribute(): string
    {
        return $this->is_locked
            ? 'Locked'
            : ($this->is_balanced ? 'Balanced' : 'Unbalanced');
    }

    /** Apakah sudah closed sepenuhnya */
    public function getIsClosedAttribute(): bool
    {
        return !is_null($this->closed_at);
    }

    /** Format nominal total closing */
    public function getFormattedTotalAttribute(): string
    {
        return 'Rp ' . number_format((float)$this->total_payment, 0, ',', '.');
    }
}
