<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FacilityBookingCatering extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'hotel_id',
        'facility_booking_id',
        'catering_package_id',
        'pax',
        'price_per_pax',
        'subtotal_amount',
        'notes',
    ];

    protected $casts = [
        'pax' => 'int',
        'price_per_pax' => 'decimal:2',
        'subtotal_amount' => 'decimal:2',
    ];

    // RELATIONS
    public function hotel()
    {
        return $this->belongsTo(Hotel::class);
    }

    public function booking()
    {
        return $this->belongsTo(FacilityBooking::class, 'facility_booking_id');
    }

    public function package()
    {
        return $this->belongsTo(CateringPackage::class, 'catering_package_id');
    }

    // HELPERS
    public function recalcSubtotal(): void
    {
        $this->subtotal_amount = (string) bcmul((string) $this->price_per_pax, (string) $this->pax, 2);
    }
}
