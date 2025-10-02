<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyClosing extends Model
{
    protected $fillable = [
        'hotel_id',
        'date',
        'cash_total',
        'noncash_total',
        'overall_total',
        'closed_by',
        'note',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
