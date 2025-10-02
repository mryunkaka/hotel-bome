<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class IncomeItem extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'hotel_id',
        'income_category_id',
        'amount',
        'description',
        'date',
    ];

    protected $casts = [
        'amount' => 'integer',
        'date'   => 'datetime',
    ];

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function incomeCategory(): BelongsTo
    {
        return $this->belongsTo(IncomeCategory::class);
    }

    protected static function booted(): void
    {
        // Kunci ke hotel yang sedang aktif (super admin via session, user biasa via hotel_id)
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
