<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FacilityBooking extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'hotel_id',
        'facility_id',
        'reservation_id',
        'group_id',
        'start_at',
        'end_at',
        'title',
        'notes',
        'pricing_mode',
        'unit_price',
        'quantity',
        'subtotal_amount',
        'discount_amount',
        'tax_amount',
        'total_amount',
        'include_catering',
        'catering_total_pax',
        'catering_total_amount',
        'status',
        'is_blocked',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at'   => 'datetime',
        'unit_price' => 'decimal:2',
        'quantity'   => 'decimal:2',
        'subtotal_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount'      => 'decimal:2',
        'total_amount'    => 'decimal:2',
        'include_catering' => 'bool',
        'catering_total_amount' => 'decimal:2',
        'catering_total_pax' => 'int',
        'is_blocked' => 'bool',
    ];

    public const STATUS_DRAFT     = 'DRAFT';
    public const STATUS_CONFIRM   = 'CONFIRM';
    public const STATUS_PAID      = 'PAID';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_CANCELLED = 'CANCELLED';

    public const PRICING_PER_HOUR = 'per_hour';
    public const PRICING_PER_DAY  = 'per_day';
    public const PRICING_FIXED    = 'fixed';

    // RELATIONS
    public function hotel()
    {
        return $this->belongsTo(Hotel::class);
    }

    public function facility()
    {
        return $this->belongsTo(Facility::class);
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function group()
    {
        return $this->belongsTo(ReservationGroup::class, 'group_id');
    }

    public function cateringItems()
    {
        return $this->hasMany(FacilityBookingCatering::class);
    }

    public function blocks()
    {
        return $this->hasMany(FacilityBlock::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // HELPERS
    public function recalcCateringTotals(): void
    {
        $pax = (int) $this->cateringItems()->sum('pax');
        $amt = (string) $this->cateringItems()->sum('subtotal_amount');

        $this->catering_total_pax    = $pax;
        $this->catering_total_amount = $amt;
    }

    public function recalcTotals(): void
    {
        // Hitung subtotal dasar booking (di luar catering)
        // subtotal = unit_price * quantity (untuk per_hour/per_day), atau unit_price (fixed→quantity biasanya 1)
        $base = (string) bcmul((string) $this->unit_price, (string) $this->quantity, 2);

        $this->subtotal_amount = $base;

        // total sebelum pajak = base - discount + catering
        $beforeTax = bcadd(bcsub($base, (string) $this->discount_amount, 2), (string) $this->catering_total_amount, 2);

        // tax_amount sudah bisa diisi dari logic eksternal (ReservationMath) → jika kosong, anggap 0
        $tax = (string) $this->tax_amount;

        $this->total_amount = bcadd($beforeTax, $tax, 2);
    }

    // SCOPES
    public function scopeForHotel($q, $hotelId)
    {
        return $q->where('hotel_id', $hotelId);
    }
    public function scopeActiveStatus($q)
    {
        return $q->whereIn('status', [self::STATUS_CONFIRM, self::STATUS_PAID, self::STATUS_COMPLETED]);
    }
    public function scopeBetween($q, $start, $end)
    {
        return $q->where('start_at', '<', $end)->where('end_at', '>', $start);
    }
    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
}
