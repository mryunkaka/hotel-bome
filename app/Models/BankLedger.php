<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class BankLedger extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'hotel_id',
        'bank_id',
        'deposit',
        'withdraw',
        'date',
        'description',
    ];

    protected $casts = [
        'deposit'  => 'decimal:2',
        'withdraw' => 'decimal:2',
        'date'     => 'date',
    ];

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    protected static function booted(): void
    {
        // Kunci ke hotel konteks aktif (super admin via session, user biasa via hotel_id)
        static::creating(function (self $m): void {
            $m->hotel_id = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id ?? $m->hotel_id;
        });

        static::updating(function (self $m): void {
            $m->hotel_id = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id ?? $m->hotel_id;
        });
    }
}
