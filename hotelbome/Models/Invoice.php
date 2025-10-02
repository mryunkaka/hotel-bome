<?php

namespace App\Models;

use App\Models\InvoiceItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Session;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'hotel_id',
        'booking_id',
        'invoice_no',
        'title',
        'date',
        'subtotal',
        'tax_total',
        'total',
        'payment_method',
        'status',
        'notes',
        'tax_setting_id',
    ];

    protected $casts = [
        'date'      => 'datetime',
        'subtotal'  => 'integer',
        'tax_total' => 'integer',
        'total'     => 'integer',
    ];

    // relations
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
    // relasi
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    // app/Models/Invoice.php
    public function taxSetting(): BelongsTo
    {
        return $this->belongsTo(TaxSetting::class)->withTrashed();
    }

    public function getTaxRate(): float
    {
        // pastikan kolom di tax_settings bernama "rate" (dalam persen)
        return (float) ($this->taxSetting->percent ?? 0);
    }

    public function recalculateTotals(?float $taxPercent = null): void
    {
        // subtotal dari item (pastikan amount = qty * unit_price sudah disimpan)
        $subtotal = (float) $this->items()->sum('amount');

        // tentukan rate: prioritas parameter, otherwise dari relasi taxSetting
        $rate = $taxPercent !== null
            ? max(0.0, (float) $taxPercent)
            : max(0.0, $this->getTaxRate());

        $tax   = round($subtotal * ($rate / 100), 2);
        $total = $subtotal + $tax;

        $this->forceFill([
            'subtotal'  => $subtotal,
            'tax_total' => $tax,
            'total'     => $total,
        ])->saveQuietly();
    }

    protected static function booted(): void
    {
        // isi hotel_id dari booking jika ada, fallback ke session/user
        static::creating(function (self $m) {
            if ($m->booking && $m->booking->hotel_id) {
                $m->hotel_id = $m->booking->hotel_id;
            } else {
                $m->hotel_id = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id ?? $m->hotel_id;
            }
            $m->date ??= now();
        });

        // setelah invoice dibuat, booking otomatis checkout
        static::created(function (self $m) {
            if ($m->booking) {
                $m->booking->markCheckedOut(); // set check_out_at = now(), status = checked_out
            }
        });
    }
}
