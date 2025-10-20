<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Class CardAssignment
 *
 * Menyimpan relasi antara kartu (MIFARE) dan tamu (ReservationGuest),
 * lengkap dengan periode berlaku dan aturan akses pintu.
 *
 * Kolom penting:
 *  - card_id
 *  - reservation_guest_id
 *  - hotel_id
 *  - valid_from / valid_to
 *  - door_mask (opsional: kode akses pintu atau grup)
 *  - created_by (user id staff yang membuat kartu)
 */
class CardAssignment extends Model
{
    use HasFactory;

    protected $table = 'card_assignments';

    protected $fillable = [
        'card_id',
        'reservation_guest_id',
        'hotel_id',
        'valid_from',
        'valid_to',
        'door_mask',
        'created_by',
    ];

    protected $casts = [
        'valid_from' => 'datetime',
        'valid_to'   => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | RELATIONS
    |--------------------------------------------------------------------------
    */

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    public function reservationGuest(): BelongsTo
    {
        return $this->belongsTo(ReservationGuest::class);
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /*
    |--------------------------------------------------------------------------
    | ACCESSORS & HELPERS
    |--------------------------------------------------------------------------
    */

    /**
     * Menentukan apakah kartu masih valid (belum kadaluarsa).
     */
    public function getIsActiveAttribute(): bool
    {
        if (blank($this->valid_to)) {
            return true;
        }

        return now()->lte($this->valid_to);
    }

    /**
     * Label ringkas untuk tampilan admin (Filament / laporan).
     */
    public function getDisplayLabelAttribute(): string
    {
        $uid = $this->card?->uid ?? '—';
        $guest = $this->reservationGuest?->guest?->full_name ?? '—';
        $expiry = $this->valid_to?->format('d/m/Y H:i') ?? '∞';

        return "{$uid} ({$guest}) — sampai {$expiry}";
    }

    /*
    |--------------------------------------------------------------------------
    | SCOPES
    |--------------------------------------------------------------------------
    */

    /**
     * Scope: hanya assignment yang masih aktif.
     */
    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('valid_to')
                ->orWhere('valid_to', '>=', now());
        });
    }

    /**
     * Scope: filter berdasarkan hotel aktif.
     */
    public function scopeForHotel($query, ?int $hotelId = null)
    {
        $hotelId ??= session('active_hotel_id') ?? Auth::user()?->hotel_id;

        return $query->where('hotel_id', $hotelId);
    }
}
