<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class ReservationGuest extends Model
{
    protected $fillable = [
        'hotel_id',
        'reservation_id',
        'guest_id',
        'room_id',
        'person',
        'jumlah_orang',
        'male',
        'female',
        'children',
        'charge_to',
        'room_rate',
        'expected_checkin',
        'expected_checkout',
        'actual_checkin',
        'actual_checkout',
        'note',
    ];

    protected $casts = [
        'room_rate'         => 'decimal:2',
        'expected_checkin'  => 'datetime',
        'expected_checkout' => 'datetime',
        'actual_checkin'    => 'datetime',
        'actual_checkout'   => 'datetime',
        'jumlah_orang'      => 'integer',
        'male'              => 'integer',
        'female'            => 'integer',
        'children'          => 'integer',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeForActiveHotel($query)
    {
        $hid = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;
        return $hid ? $query->where('hotel_id', $hid) : $query;
    }

    /*
    |--------------------------------------------------------------------------
    | Booted
    |--------------------------------------------------------------------------
    */
    protected static function booted(): void
    {
        // Kunci ke hotel konteks aktif (super admin via session, user biasa via hotel_id)
        static::creating(function (self $m): void {
            $m->hotel_id = Session::get('active_hotel_id')
                ?? Auth::user()?->hotel_id
                ?? $m->hotel_id;
        });

        static::updating(function (self $m): void {
            $m->hotel_id = Session::get('active_hotel_id')
                ?? Auth::user()?->hotel_id
                ?? $m->hotel_id;
        });
    }
}
