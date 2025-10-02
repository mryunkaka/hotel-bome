<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
{
    use SoftDeletes;

    // TAMBAH: kode status standar housekeeping / FO
    public const ST_VC  = 'VC';   // Vacant Clean (ready) Pengertian: Kamar Kosong Bersih.
    public const ST_VCI = 'VCI';  // Vacant Clean Inspected (ready+inspected) Pengertian: Kamar Kosong Bersih dan Sudah Diperiksa.
    public const ST_VD  = 'VD';   // Vacant Dirty Pengertian: Kamar Kosong Kotor.
    public const ST_OCC = 'OCC';  // Occupied Pengertian: Kamar Terisi.
    public const ST_LS  = 'LS';   // Long Stay
    public const ST_ED  = 'ED';   // Expected Departure Pengertian: Diharapkan Berangkat (Check-out) Hari Ini.
    public const ST_OOO = 'OOO';  // Out Of Order Pengertian: Kamar Rusak / Dalam Perbaikan.
    public const ST_HU  = 'HU';   // House Use Pengertian: Digunakan oleh Pihak Hotel.
    public const ST_RS  = 'RS';   // Reserved Pengertian: Dipesan, belum check-in.   // <— DITAMBAHKAN

    protected $fillable = [

        'hotel_id',
        'type',
        'room_no',
        'price',
        'status',
        'status_changed_at'
    ];

    protected $casts = [
        'status_changed_at' => 'datetime', // TAMBAH
        'price'    => 'decimal:2',
        'floor'    => 'integer',
    ];

    public function setStatus(string $code): void
    {
        $this->forceFill([
            'status' => $code,
            'status_changed_at' => now(),
        ])->saveQuietly();
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function reservationGuests()
    {
        return $this->hasMany(ReservationGuest::class);
    }

    public function bookings(): HasMany
    {
        // Kalau kolom FK berbeda, sesuaikan: hasMany(Booking::class, 'room_id', 'id')
        return $this->hasMany(\App\Models\Booking::class);
    }

    public function blocks()
    {
        return $this->hasMany(\App\Models\RoomBlock::class);
    }

    /** Room yang tersedia untuk periode (mengecualikan yang ter-block aktif & overlap) */
    public function scopeAvailableFor($q, int $hotelId, $start, $end)
    {
        return $q->where('hotel_id', $hotelId)
            ->whereDoesntHave('blocks', function ($b) use ($start, $end) {
                $b->active()->overlaps($start, $end);
            });
    }

    public function scopeAssignable($q)
    {
        return $q
            ->whereIn('status', [self::ST_VC, self::ST_VCI])                      // ready only
            ->whereNotExists(function ($sub) {                                    // bukan yg sedang ditempati
                $sub->from('reservation_guests as rg')
                    ->whereColumn('rg.room_id', 'rooms.id')
                    ->whereNotNull('rg.actual_checkin')
                    ->whereNull('rg.actual_checkout');
            });
    }

    protected static function booted(): void
    {
        // Kunci selalu ke hotel konteks aktif (super admin via session, user biasa via hotel_id)
        static::creating(function (self $m): void {
            $m->hotel_id = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id ?? $m->hotel_id;
        });

        static::updating(function (self $m): void {
            $m->hotel_id = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id ?? $m->hotel_id;
        });
    }
}
