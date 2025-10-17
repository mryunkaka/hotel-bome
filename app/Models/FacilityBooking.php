<?php

namespace App\Models;

use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FacilityBooking extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'hotel_id',
        'facility_id',
        'guest_id',
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
        'dp',
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
        'dp' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            $m->created_by = $m->created_by ?: Auth::id();
            $m->updated_by = $m->updated_by ?: Auth::id();

            // include_catering auto dari nominal
            $m->include_catering = (bool) ((float) $m->catering_total_amount > 0);

            // is_blocked mengikuti status
            if (in_array($m->status, [self::STATUS_CONFIRM, self::STATUS_PAID], true)) {
                $m->is_blocked = true;
            } elseif (in_array($m->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)) {
                $m->is_blocked = false;
            }
        });

        static::updating(function (self $m) {
            $m->updated_by = Auth::id();

            // refresh include_catering saat update
            $m->include_catering = (bool) ((float) $m->catering_total_amount > 0);

            // jika status berubah, kunci/unlock block
            if ($m->isDirty('status')) {
                if (in_array($m->status, [self::STATUS_CONFIRM, self::STATUS_PAID], true)) {
                    $m->is_blocked = true;
                }
                if (in_array($m->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)) {
                    $m->is_blocked = false;
                }
            }
        });
    }

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

    public function guest()
    {
        return $this->belongsTo(Guest::class, 'guest_id');
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
