<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacilityBlock extends Model
{
    protected $fillable = [
        'hotel_id',
        'facility_id',
        'facility_booking_id',
        'reservation_id',
        'start_at',
        'end_at',
        'active',
        'source',
        'reason',
        'notes',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'active' => 'bool',
    ];

    // RELATIONS
    public function hotel()
    {
        return $this->belongsTo(Hotel::class);
    }

    public function facility()
    {
        return $this->belongsTo(Facility::class);
    }

    public function booking()
    {
        return $this->belongsTo(FacilityBooking::class, 'facility_booking_id');
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    // SCOPES
    public function scopeActive($q)
    {
        return $q->where('active', true);
    }
    public function scopeForHotel($q, $hotelId)
    {
        return $q->where('hotel_id', $hotelId);
    }

    /**
     * Cari block yang overlap.
     * Overlap jika: existing.start < newEnd && existing.end > newStart
     */
    public function scopeOverlapping($q, int $facilityId, string|\DateTimeInterface $start, string|\DateTimeInterface $end)
    {
        return $q->where('facility_id', $facilityId)
            ->where('start_at', '<', $end)
            ->where('end_at', '>', $start);
    }
}
