<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Card extends Model
{
    protected $fillable = [
        'hotel_id',
        'uid',
        'serial_number',
        'status',
        'last_reservation_id',
    ];

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function lastReservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'last_reservation_id');
    }
}
