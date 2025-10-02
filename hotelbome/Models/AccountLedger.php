<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class AccountLedger extends Model
{
    use SoftDeletes;

    protected $table = 'ledger_accounts';

    // Perlu 'hotel_id' diisi via Filament ->create(), jadi biarkan fillable.
    protected $fillable = [
        'hotel_id',
        'debit',
        'credit',
        'date',
        'method',
        'description',
    ];

    protected $casts = [
        'debit'  => 'decimal:2', // dikembalikan string (presisi)
        'credit' => 'decimal:2',
        'date'   => 'date',
    ];

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            // Utamakan konteks hotel dari session (super admin / user biasa).
            $contextHotelId = Session::get('active_hotel_id')
                ?? Auth::user()?->hotel_id;

            if ($contextHotelId) {
                $model->hotel_id = $contextHotelId;
            }
        });

        static::updating(function (self $model): void {
            // Kunci lagi saat update agar tidak bisa pindah hotel lewat payload.
            $contextHotelId = Session::get('active_hotel_id')
                ?? Auth::user()?->hotel_id;

            if ($contextHotelId) {
                $model->hotel_id = $contextHotelId;
            }
        });
    }
}
