<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomBlock extends Model
{
    protected $fillable = [
        'hotel_id',
        'room_id',
        'reservation_id',
        'start_at',
        'end_at',
        'reason',
        'active',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at'   => 'datetime',
        'active'   => 'bool',
    ];

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /* scopes */
    public function scopeActive($q)
    {
        return $q->where('active', true);
    }

    /** blok yang overlap dengan periode [start, end) */
    public function scopeOverlaps($q, $start, $end)
    {
        return $q->where('start_at', '<', $end)
            ->where('end_at',   '>', $start);
    }
}
