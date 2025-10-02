<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class Bank extends Model
{
    use SoftDeletes;

    protected $table = 'banks';

    // Izinkan mass assignment, tapi tetap dikunci via hook di bawah
    protected $fillable = [
        'hotel_id',
        'name',
        'branch',
        'account_no',
        'address',
        'phone',
        'email',
    ];

    protected $casts = [
        // tambahkan casts jika perlu
    ];

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    protected static function booted(): void
    {
        // Pastikan semua create/update selalu menempel ke hotel konteks aktif
        static::creating(function (self $model): void {
            $contextHotelId = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;
            if ($contextHotelId) {
                $model->hotel_id = $contextHotelId;
            }
        });

        static::updating(function (self $model): void {
            $contextHotelId = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;
            if ($contextHotelId) {
                $model->hotel_id = $contextHotelId;
            }
        });
    }

    // (Opsional) normalisasi email
    public function setEmailAttribute($value): void
    {
        $this->attributes['email'] = $value ? mb_strtolower(trim($value)) : null;
    }
}
