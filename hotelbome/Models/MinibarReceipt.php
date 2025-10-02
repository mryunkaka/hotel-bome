<?php

namespace App\Models;

use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Session;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MinibarReceipt extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'minibar_receipts';

    protected $fillable = [
        'hotel_id',
        'receipt_no',
        'issued_at',
        'reservation_guest_id',
        'subtotal_amount',
        'discount_amount',
        'tax_amount',
        'total_amount',
        'total_cogs',
        'status',
        'created_by',
    ];

    protected $casts = [
        'issued_at'       => 'datetime', // ✅ ini yang benar untuk receipts
        'subtotal_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount'      => 'decimal:2',
        'total_amount'    => 'decimal:2',
        'total_cogs'      => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            // selaraskan dengan migrasi: isi kolom NOT NULL
            $m->hotel_id   ??= Session::get('active_hotel_id');
            $m->created_by ??= Auth::id();
            $m->status     ??= 'paid';   // sesuaikan default migrasi (kalau ada)
        });
    }

    /* Relationships */
    public function hotel()
    {
        return $this->belongsTo(Hotel::class);
    }

    public function reservationGuest()
    {
        return $this->belongsTo(ReservationGuest::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // created_by -> users.id (sesuai field di migrasi kamu)
    public function user()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items()
    {
        return $this->hasMany(MinibarReceiptItem::class, 'receipt_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* Scopes */
    public function scopePaid($q)
    {
        return $q->where('status', 'paid');
    }

    public function scopeIssuedBetween($q, $start, $end)
    {
        return $q->whereBetween('issued_at', [$start, $end]);
    }
}
