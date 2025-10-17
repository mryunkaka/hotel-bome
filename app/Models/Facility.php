<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Facility extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'hotel_id',
        'code',
        'name',
        'type',
        'capacity',
        'is_allow_catering',
        'base_pricing_mode',
        'base_price',
        'is_active',
        'description',
    ];

    protected $casts = [
        'is_allow_catering' => 'bool',
        'is_active' => 'bool',
        'base_price' => 'decimal:2',
    ];

    public const TYPE_VENUE     = 'venue';
    public const TYPE_VEHICLE   = 'vehicle';
    public const TYPE_EQUIPMENT = 'equipment';
    public const TYPE_SERVICE   = 'service';
    public const TYPE_OTHER     = 'other';

    public const PRICING_PER_HOUR = 'per_hour';
    public const PRICING_PER_DAY  = 'per_day';
    public const PRICING_FIXED    = 'fixed';

    // RELATIONS
    public function hotel()
    {
        return $this->belongsTo(Hotel::class);
    }

    public function bookings()
    {
        return $this->hasMany(FacilityBooking::class);
    }

    public function blocks()
    {
        return $this->hasMany(FacilityBlock::class);
    }

    // SCOPES
    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
    public function scopeForHotel($q, $hotelId)
    {
        return $q->where('hotel_id', $hotelId);
    }
    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (empty($model->code) && $model->hotel_id) {
                $model->code = \App\Support\FacilityCodeHelper::generate((int)$model->hotel_id);
            }
        });
    }
}
