<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class Bank extends Model
{
    use SoftDeletes;

    protected $table = 'banks';

    protected $fillable = [
        'hotel_id',
        'name',
        'short_code',
        'branch',
        'account_no',
        'holder_name',
        'address',
        'phone',
        'email',
        'swift_code',
        'currency',     // default: IDR
        'is_active',    // default: true
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /*
     | Relationships
     */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    // Jika kamu sudah punya model BankLedger:
    public function bankLedgers(): HasMany
    {
        return $this->hasMany(BankLedger::class);
    }

    /*
     | Global context: isi hotel_id dari session / user saat create & update
     */
    protected static function booted(): void
    {
        $applyHotel = function (self $model): void {
            $contextHotelId = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;
            if ($contextHotelId) {
                $model->hotel_id = $contextHotelId;
            }
        };

        static::creating($applyHotel);
        static::updating($applyHotel);
    }

    /*
     | Mutators / Normalizers
     */
    public function setEmailAttribute($value): void
    {
        $this->attributes['email'] = $value ? mb_strtolower(trim($value)) : null;
    }

    public function setShortCodeAttribute($value): void
    {
        // Simpan uppercase & tanpa spasi untuk konsistensi (sesuai unique per hotel)
        $this->attributes['short_code'] = $value ? mb_strtoupper(preg_replace('/\s+/', '', $value)) : null;
    }

    public function setAccountNoAttribute($value): void
    {
        // Hilangkan spasi/pemisah umum agar uniknya konsisten
        $this->attributes['account_no'] = $value ? preg_replace('/[\s\-\.]/', '', trim($value)) : null;
    }

    public function setCurrencyAttribute($value): void
    {
        // Simpan sebagai ISO 4217 uppercase (IDR, USD, dll)
        $this->attributes['currency'] = $value ? mb_strtoupper(trim($value)) : 'IDR';
    }

    /*
     | Scopes bantu
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForHotel($query, int $hotelId)
    {
        return $query->where('hotel_id', $hotelId);
    }

    /*
     | Accessor opsional: kode akun (kalau ingin dipakai di ledger)
     | ex: BANK_BCA, BANK_BRI, dll
     */
    public function getAccountCodeAttribute(): ?string
    {
        return $this->short_code ? ('BANK_' . $this->short_code) : null;
    }
}
