<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class ReservationGroup extends Model
{
    use SoftDeletes;

    protected $table = 'reservation_groups';

    protected $fillable = [
        'hotel_id',
        'name',
        'address',
        'city',
        'phone',
        'handphone',
        'fax',
        'email',
        'remark_ci',
        'long_remark',
    ];

    protected $casts = [
        // tambahkan casts jika diperlukan di masa depan
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'group_id');
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
