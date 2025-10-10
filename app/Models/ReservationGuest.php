<?php

namespace App\Models;

use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use App\Models\Room;                   // TAMBAH
use Illuminate\Support\Carbon;         // TAMBAH
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationGuest extends Model
{
    // Kalau mau ubah harga extra bed, cukup edit ini:
    public const EXTRA_BED_PRICE = 100_000;

    protected $fillable = [
        'hotel_id',
        'reservation_id',
        'guest_id',
        'room_id',
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
        'charge',
        'deposit_room',
        'deposit_card',
        'deposit_cleared_at',
        'bill_no',
        'bill_closed_at',
        'rate_type',
        'created_by',
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
        'charge'            => 'integer',
        'deposit_room'        => 'decimal:2',
        'deposit_card'        => 'decimal:2',
        'deposit_cleared_at'  => 'datetime',
        'bill_closed_at'      => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

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

    protected static function intify(mixed $v): int
    {
        if ($v === null || $v === '') return 0;
        // buang non-digit aman untuk "number" maupun string
        return (int) preg_replace('/\D+/', '', (string) $v);
    }

    /* ===== Mutators agar field numeric bersih ===== */
    public function setMaleAttribute($value): void
    {
        $this->attributes['male'] = max(0, static::intify($value));
    }

    public function setFemaleAttribute($value): void
    {
        $this->attributes['female'] = max(0, static::intify($value));
    }

    public function setChildrenAttribute($value): void
    {
        $this->attributes['children'] = max(0, static::intify($value));
    }

    public function setJumlahOrangAttribute($value): void
    {
        // tetap boleh di-set manual, tapi jaga agar tidak < 1
        $this->attributes['jumlah_orang'] = max(1, static::intify($value));
    }

    /* ===== Recompute jumlah_orang sebelum simpan ===== */
    protected function recomputePeople(): void
    {
        $male     = static::intify($this->male ?? 0);
        $female   = static::intify($this->female ?? 0);
        $children = static::intify($this->children ?? 0);

        $this->attributes['jumlah_orang'] = max(1, $male + $female + $children);
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

            $m->recomputePeople();
        });

        static::updating(function (ReservationGuest $m) {
            $rate = (float) ($m->room_rate ?? 0);

            if ($rate > 0) {
                // HANYA isi default saat KEDUANYA nol.
                if ((float)($m->deposit_room ?? 0) === 0.0 && (float)($m->deposit_card ?? 0) === 0.0) {
                    $m->deposit_card = $rate * 0.5; // default ke kartu
                    $m->deposit_room = 0;           // pastikan room tetap 0
                }
            }

            $m->hotel_id = Session::get('active_hotel_id')
                ?? Auth::user()?->hotel_id
                ?? $m->hotel_id;

            $m->recomputePeople();

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

        static::saving(function (self $m): void {
            // ===== existing =====
            $m->recomputePeople();

            // ====== ⬇️ VALIDASI BARU: DUPLIKASI GUEST & ROOM ⬇️ ======

            // Siapkan nilai pembanding
            $currentId = $m->getKey() ?? 0;
            $hotelId   = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id ?? $m->hotel_id;

            // ---- 1) Cegah guest ganda di tanggal yang sama ----
            if (!empty($m->guest_id)) {
                $dates = [];

                if (!empty($m->expected_checkin)) {
                    $dates[] = Carbon::parse($m->expected_checkin)->toDateString();
                }
                if (!empty($m->actual_checkin)) {
                    $dates[] = Carbon::parse($m->actual_checkin)->toDateString();
                }

                if (!empty($dates)) {
                    $guestConflict = static::query()
                        ->when($hotelId, fn($q) => $q->where('hotel_id', $hotelId))
                        ->where('guest_id', $m->guest_id)
                        ->where('id', '!=', $currentId)
                        ->where(function ($q) use ($dates) {
                            foreach ($dates as $d) {
                                $q->orWhereDate('expected_checkin', $d)
                                    ->orWhereDate('actual_checkin',   $d);
                            }
                        })
                        ->exists();

                    if ($guestConflict) {
                        throw ValidationException::withMessages([
                            'guest_id' => 'Guest sudah ada pada tanggal yang sama (expected / actual check-in).',
                        ]);
                    }
                }
            }

            // ---- 2) Cegah room ganda (kecuali yang lama sudah checkout) ----
            if (!empty($m->room_id)) {
                $roomConflict = static::query()
                    ->when($hotelId, fn($q) => $q->where('hotel_id', $hotelId))
                    ->where('room_id', $m->room_id)
                    ->where('id', '!=', $currentId)
                    ->whereNull('actual_checkout') // hanya blokir jika yang lain belum checkout
                    ->exists();

                if ($roomConflict) {
                    throw ValidationException::withMessages([
                        'room_id' => 'Kamar sudah dipakai oleh tamu lain yang belum checkout.',
                    ]);
                }
            }

            // ====== ⬆️ VALIDASI BARU SELESAI ⬆️ ======
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
    /** Scope: tamu in-house (sudah CI & belum CO) untuk 1 hotel */
    public function scopeCurrentInhouse($q, int $hotelId)
    {
        return $q->where('hotel_id', $hotelId)
            ->whereNotNull('actual_checkin')
            ->whereNull('actual_checkout');
    }

    /** Accessor label untuk dropdown */
    public function getDisplayLabelAttribute(): string
    {
        $guestName = $this->guest->name
            ?? $this->guest_name
            ?? 'Unknown';

        $resCode   = $this->reservation->code
            ?? $this->reservation->reservation_code
            ?? $this->reservation_code
            ?? '-';

        $roomNo    = $this->room->room_no         // ← sesuai model Room
            ?? $this->room_no
            ?? '-';

        return sprintf('%s — %s (Room %s)', $guestName, $resCode, $roomNo);
    }
}
