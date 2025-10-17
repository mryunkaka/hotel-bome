<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CateringPackage extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'hotel_id',
        'code',
        'name',
        'description',
        'min_pax',
        'price_per_pax',
        'is_active',
    ];

    protected $casts = [
        'price_per_pax' => 'decimal:2',
        'is_active' => 'bool',
        'min_pax' => 'int',
    ];

    // RELATIONS
    public function hotel()
    {
        return $this->belongsTo(Hotel::class);
    }

    public function bookingDetails()
    {
        return $this->hasMany(FacilityBookingCatering::class);
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
}
