<?php

namespace App\Models;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Session;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reservation extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'hotel_id',
        'group_id',
        'guest_id',
        'id_tax',
        'reservation_no',
        'option',
        'method',
        'status',
        'expected_arrival',
        'expected_departure',
        'checkin_date',
        'checkout_date',
        'deposit_room',
        'deposit_card',
        'reserved_by_type',
        'entry_date',
        'num_guests',
        'card_uid',
        'created_by',
        'option_reservation',
    ];

    protected $casts = [
        'expected_arrival'  => 'datetime',
        'expected_departure' => 'datetime',
        'checkin_date'      => 'datetime',
        'checkout_date'     => 'datetime',
        'entry_date'        => 'datetime',
        'deposit_room'      => 'integer',
        'deposit_card'      => 'integer',
    ];

    public function getLengthOfStayAttribute(): ?int
    {
        if (! $this->expected_arrival || ! $this->expected_departure) {
            return null;
        }

        return $this->expected_arrival->copy()->startOfDay()
            ->diffInDays($this->expected_departure->copy()->startOfDay());
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(TaxSetting::class, 'id_tax');
    }

    // Group reservasi (optional)
    public function group(): BelongsTo
    {
        return $this->belongsTo(ReservationGroup::class, 'group_id');
    }


    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'guest_id');
    }

    // Detail tamu per kamar
    public function guests(): HasMany
    {
        return $this->hasMany(ReservationGuest::class);
    }

    // User pembuat
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Booted
    |--------------------------------------------------------------------------
    */
    protected static function booted(): void
    {
        static::created(fn(self $r) => $r->syncRoomStatuses());
        static::updated(function (self $r): void {
            // Jika header tanggal berubah, sinkronkan ke anak
            if ($r->wasChanged(['expected_arrival', 'expected_departure'])) {
                $payload = [];
                if ($r->expected_arrival) {
                    $payload['expected_checkin'] = $r->expected_arrival;
                }
                if ($r->expected_departure) {
                    $payload['expected_checkout'] = $r->expected_departure;
                }
                if (! empty($payload)) {
                    $r->reservationGuests()->update($payload);
                }
            }

            // Hook milikmu untuk status kamar tetap jalan
            $r->syncRoomStatuses();
        });

        // Kunci ke hotel konteks aktif (super admin via session, user biasa via hotel_id)
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

    public function syncRoomStatuses(): void
    {
        foreach ($this->reservationGuests as $rg) {
            \App\Models\ReservationGuest::syncRoomStatus($rg);
        }
    }

    // Alias agar Repeater::relationship('reservationGuests') bekerja
    public function reservationGuests(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ReservationGuest::class);
    }

    /**
     * Generator nomor reservasi: HOTEL-RESVYYMM##### (contoh)
     * Ubah pola sesuai kebutuhanmu.
     */
    public static function nextReservationNo(?int $hotelId = null): string
    {
        $hid = $hotelId ?? (Session::get('active_hotel_id') ?? Auth::user()?->hotel_id ?? 0);
        $prefix = 'HOTEL-RESV' . now()->format('ym');
        $last = static::query()
            ->when($hid, fn($q) => $q->where('hotel_id', $hid))
            ->where('reservation_no', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->value('reservation_no');

        $seq = 1;
        if ($last && preg_match('/(\d+)$/', $last, $m)) {
            $seq = ((int)$m[1]) + 1;
        }
        return sprintf('%s%05d', $prefix, $seq);
    }

    /**
     * Buat nomor unik untuk Reservation/Walk-in
     * - Reservation: HOTEL-RESVyymmxxxx
     * - Walk-in: 0001-WALKyymm/HotelName
     */
    public static function generateReservationNo(?int $hotelId = null, string $type = 'RESERVATION'): string
    {
        $hid = $hotelId
            ?? (Session::get('active_hotel_id') ?? Auth::user()?->hotel_id ?? null);

        $month = Carbon::now()->format('m'); // 09
        $year  = Carbon::now()->format('y'); // 25

        // Nama hotel untuk suffix
        $hotelName = optional(Hotel::find($hid))->name ?? 'HOTEL';
        $hotelName = trim(preg_replace('/[^\p{L}\p{N}\s\-\._]/u', '', $hotelName));

        if ($type === 'WALKIN') {
            $marker = "-WALK{$year}{$month}/";

            $query = static::query()
                ->when($hid, fn($q) => $q->where('hotel_id', $hid))
                ->where('reservation_no', 'like', "%{$marker}%");

            $last = $query->orderByDesc('id')->value('reservation_no');
            $next = 1;

            if ($last && preg_match('/^(\d{4})-WALK' . $year . $month . '\//', $last, $m)) {
                $next = ((int) $m[1]) + 1;
            }

            $seq = str_pad((string) $next, 4, '0', STR_PAD_LEFT);

            return "{$seq}{$marker}{$hotelName}";
        }

        // Default: RESERVATION
        $marker = "-RESV{$month}{$year}/";

        $query = static::query()
            ->when($hid, fn($q) => $q->where('hotel_id', $hid))
            ->where('reservation_no', 'like', "%{$marker}%");

        $last = $query->orderByDesc('id')->value('reservation_no');
        $next = 1;

        if ($last && preg_match('/^(\d{4})-RESV' . $month . $year . '\//', $last, $m)) {
            $next = ((int) $m[1]) + 1;
        }

        $seq = str_pad((string) $next, 4, '0', STR_PAD_LEFT);

        return "{$seq}{$marker}{$hotelName}";
    }
}
