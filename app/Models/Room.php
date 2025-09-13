<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'hotel_id',
        // 'room_type_id', // jika pakai tabel room_types
        'type',           // jika tidak pakai tabel room_types
        'room_no',
        'floor',
        'price',
    ];

    protected $casts = [
        'price'    => 'decimal:2',
        'floor'    => 'integer',
    ];

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function bookings(): HasMany
    {
        // Kalau kolom FK berbeda, sesuaikan: hasMany(Booking::class, 'room_id', 'id')
        return $this->hasMany(\App\Models\Booking::class);
    }

    public function blocks()
    {
        return $this->hasMany(\App\Models\RoomBlock::class);
    }

    /** Room yang tersedia untuk periode (mengecualikan yang ter-block aktif & overlap) */
    public function scopeAvailableFor($q, int $hotelId, $start, $end)
    {
        return $q->where('hotel_id', $hotelId)
            ->whereDoesntHave('blocks', function ($b) use ($start, $end) {
                $b->active()->overlaps($start, $end);
            });
    }

    protected static function booted(): void
    {
        // Kunci selalu ke hotel konteks aktif (super admin via session, user biasa via hotel_id)
        static::creating(function (self $m): void {
            $m->hotel_id = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id ?? $m->hotel_id;
        });

        static::updating(function (self $m): void {
            $m->hotel_id = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id ?? $m->hotel_id;
        });
    }
}
