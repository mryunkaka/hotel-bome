<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Carbon;         // TAMBAH
use App\Models\Room;                   // TAMBAH

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
        'service',
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
        'room_rate'         => 'decimal:2',
        'discount_percent'  => 'decimal:2',
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
        // Pajak sekarang global di reservation
        return (float) ($this->reservation?->tax?->percent ?? 0);
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
        $tax = max(0, min(100, (float) $this->tax_percent));
        return $this->rate_after_discount * (1 + $tax / 100);
    }

    public function getFinalRateAttribute(): float
    {
        return $this->rate_after_tax + $this->extra_bed_total;
    }

    /*
    |--------------------------------------------------------------------------
    | Booted (event hooks)
    |--------------------------------------------------------------------------
    */
    protected static function booted(): void
    {
        // Sudah ada di aslimu → tetap dipertahankan
        static::creating(function (self $m): void {
            $m->hotel_id = Session::get('active_hotel_id')
                ?? Auth::user()?->hotel_id
                ?? $m->hotel_id;
        });

        static::updating(function (self $m): void {
            $m->hotel_id = Session::get('active_hotel_id')
                ?? Auth::user()?->hotel_id
                ?? $m->hotel_id;

            // Jika pindah kamar, pulihkan status kamar lama (jika tidak ada tamu aktif lain)
            if ($m->isDirty('room_id')) {
                $oldRoomId = $m->getOriginal('room_id');
                if ($oldRoomId) {
                    // Jangan set VC kalau masih ada tamu aktif lain di kamar lama
                    if (! self::roomHasOtherActiveGuests($oldRoomId, $m->getKey())) {
                        Room::find($oldRoomId)?->setStatus(Room::ST_VC);
                    }
                }
            }
        });

        // === TAMBAHAN: sinkron saat created/updated/deleted ===

        static::created(function (self $m): void {
            self::syncRoomStatus($m);
        });

        static::updated(function (self $m): void {
            self::syncRoomStatus($m);
        });

        static::deleted(function (self $m): void {
            // Saat baris RG dihapus → kalau tidak ada tamu aktif lain di kamar tsb, set VC
            if ($m->room_id && ! self::roomHasOtherActiveGuests($m->room_id, $m->getKey())) {
                Room::find($m->room_id)?->setStatus(Room::ST_VC);
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers sinkronisasi status kamar
    |--------------------------------------------------------------------------
    */

    /** Apakah di kamar ini ada tamu aktif lain ( selain RG saat ini )? */
    protected static function roomHasOtherActiveGuests(int $roomId, ?int $exceptRgId = null): bool
    {
        return static::query()
            ->where('room_id', $roomId)
            ->when($exceptRgId, fn($q) => $q->where('id', '!=', $exceptRgId))
            ->whereNotNull('actual_checkin')
            ->whereNull('actual_checkout')
            ->exists();
    }

    /** Hitung rencana jumlah malam (untuk deteksi Long Stay) */
    protected static function plannedNights(self $rg): ?int
    {
        $start = $rg->expected_checkin ? Carbon::parse($rg->expected_checkin) : null;
        $end   = $rg->expected_checkout ? Carbon::parse($rg->expected_checkout) : null;

        if (! $start || ! $end) {
            return null;
        }

        return max(1, $start->startOfDay()->diffInDays($end->startOfDay()));
    }

    /** Sinkronkan status Room berdasar kondisi RG ini */
    // App\Models\ReservationGuest.php

    protected static function syncRoomStatus(self $rg): void
    {
        if (! $rg->room_id) return;

        $room = \App\Models\Room::find($rg->room_id);
        if (! $room) return;

        // 1) Checkout → VD (jika tidak ada tamu aktif lain)
        if ($rg->actual_checkout) {
            if (! self::roomHasOtherActiveGuests($room->id, $rg->getKey())) {
                $room->setStatus(\App\Models\Room::ST_VD);
            }
            return;
        }

        // 2) Sudah check-in & belum checkout → OCC / LS
        if ($rg->actual_checkin && ! $rg->actual_checkout) {
            $nights    = self::plannedNights($rg);
            $threshold = (int) config('hotel.long_stay_nights', 7);
            $status    = ($nights !== null && $nights >= $threshold)
                ? \App\Models\Room::ST_LS
                : \App\Models\Room::ST_OCC;

            $room->setStatus($status);
            return;
        }

        // 3) Belum check-in (baru dipesan/di-assign) → RS (Reserved)
        //    Hanya set RS jika tidak ada tamu aktif lain di kamar tsb.
        if (! self::roomHasOtherActiveGuests($room->id, $rg->getKey())) {
            $room->setStatus(\App\Models\Room::ST_RS);
        }

        // 4) Opsional ED: hanya untuk yang sudah in-house (hindari menandai ED saat belum check-in)
        if ($rg->actual_checkin && $rg->expected_checkout && ! $rg->actual_checkout) {
            if (\Illuminate\Support\Carbon::parse($rg->expected_checkout)->isToday()) {
                if (! self::roomHasOtherActiveGuests($room->id, $rg->getKey())) {
                    $room->setStatus(\App\Models\Room::ST_ED);
                }
            }
        }
    }
}
