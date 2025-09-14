<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class ReservationGuest extends Model
{
    // Kalau mau ubah harga extra bed, cukup edit ini:
    public const EXTRA_BED_PRICE = 100_000;

    protected $fillable = [
        'hotel_id',
        'reservation_id',
        'guest_id',
        'room_id',
        'id_tax',
        'person',
        'jumlah_orang',
        'male',
        'female',
        'children',
        'charge_to',
        'room_rate',
        'expected_checkin',
        'expected_checkout',
        'actual_checkin',
        'actual_checkout',
        'pov',
        'breakfast',
        'note',
        'extra_bed',
        'discount_percent',
    ];

    protected $casts = [
        'room_rate'         => 'decimal:2',   // string "100000.00" → pastikan cast ke float saat hitung
        'discount_percent'  => 'decimal:2',   // tambahkan cast supaya ada nilai default numeric
        'expected_checkin'  => 'datetime',
        'expected_checkout' => 'datetime',
        'actual_checkin'    => 'datetime',
        'actual_checkout'   => 'datetime',
        'jumlah_orang'      => 'integer',
        'male'              => 'integer',
        'female'            => 'integer',
        'children'          => 'integer',
        'extra_bed'         => 'integer',
    ];

    /**
     * Biar ikut saat ->toArray() / json (mis. kalau kamu mapping ke $rows)
     */
    protected $appends = [
        'tax_percent',
        'extra_bed_total',
        'rate_after_discount',
        'rate_after_tax',
        'final_rate',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */
    public function tax(): BelongsTo
    {
        return $this->belongsTo(TaxSetting::class, 'id_tax');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
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
    | Accessors (Perhitungan Harga)
    |--------------------------------------------------------------------------
    */

    public function getTaxPercentAttribute(): float
    {
        // persen pajak dari relasi tax (0 jika tidak ada)
        return (float) ($this->tax->percent ?? 0);
    }

    public function getExtraBedTotalAttribute(): float
    {
        return (int) ($this->extra_bed ?? 0) * static::EXTRA_BED_PRICE;
    }

    public function getRateAfterDiscountAttribute(): float
    {
        $base = (float) ($this->room_rate ?? 0);
        $disc = (float) ($this->discount_percent ?? 0);
        $disc = max(0, min(100, $disc));
        return max(0, $base * (1 - $disc / 100));
    }

    public function getRateAfterTaxAttribute(): float
    {
        $tax = (float) $this->tax_percent;
        $tax = max(0, min(100, $tax));
        return $this->rate_after_discount * (1 + $tax / 100);
    }

    public function getFinalRateAttribute(): float
    {
        return $this->rate_after_tax + $this->extra_bed_total;
    }

    /*
    |--------------------------------------------------------------------------
    | Booted
    |--------------------------------------------------------------------------
    */
    protected static function booted(): void
    {
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
