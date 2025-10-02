<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class TaxSetting extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'hotel_id',
        'name',
        'percent',
        'is_active',
    ];

    protected $casts = [
        'percent'   => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    protected static function booted(): void
    {
        // Selalu tempel ke hotel konteks aktif
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
