<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class Booking extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'hotel_id',
        'room_id',
        'guest_id',
        'check_in_at',
        'check_out_at',
        'status',
        'notes',
    ];

    protected $casts = [
        'check_in_at'  => 'datetime',
        'check_out_at' => 'datetime',
    ];

    // relations
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    // helper: checkout
    public function markCheckedOut(?\DateTimeInterface $at = null): void
    {
        $this->forceFill([
            'check_out_at' => $at ?? now(),
            'status'       => 'checked_out',
        ])->save();
    }

    protected static function booted(): void
    {
        // tempel hotel aktif
        static::creating(function (self $m) {
            $m->hotel_id = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id ?? $m->hotel_id;
        });
        static::updating(function (self $m) {
            $m->hotel_id = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id ?? $m->hotel_id;
        });
    }
}
