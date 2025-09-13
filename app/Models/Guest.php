<?php

namespace App\Models;

use BackedEnum;
use App\Enums\Salutation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Session;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Guest extends Model
{
    use SoftDeletes;

    protected $table = 'guests';

    protected $fillable = [
        'name',
        'salutation',
        'guest_type',
        'address',
        'city',
        'nationality',
        'profession',
        'id_type',
        'id_card',
        'id_card_file',
        'birth_place',
        'birth_date',
        'issued_place',
        'issued_date',
        'phone',
        'email',
        'hotel_id',
    ];

    protected $casts = [
        'salutation' => Salutation::class,
        'birth_date'  => 'date',
        'issued_date' => 'date',
    ];

    public function setIdCardAttribute($value): void
    {
        $v = trim((string) $value);
        $this->attributes['id_card'] = ($v === '' || $v === '-') ? null : $v;
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    // Normalisasi ringan
    public function setEmailAttribute($value): void
    {
        $this->attributes['email'] = $value ? mb_strtolower(trim($value)) : null;
    }

    public function setPhoneAttribute($value): void
    {
        if (! $value) {
            $this->attributes['phone'] = null;
            return;
        }
        $v = trim((string) $value);
        // pertahankan '+' di posisi awal, hapus non-digit lainnya
        $hasPlus = str_starts_with($v, '+');
        $digits  = preg_replace('/\D+/', '', $v);
        $this->attributes['phone'] = $hasPlus ? ('+' . $digits) : $digits;
    }

    protected static function booted(): void
    {
        static::creating(function (self $m): void {
            $m->hotel_id = $m->hotel_id
                ?? (Session::get('active_hotel_id') ?? Auth::user()?->hotel_id);
        });

        // hindari overwrite saat update jika sudah ada nilai
        static::updating(function (self $m): void {
            $m->hotel_id = $m->hotel_id
                ?? (Session::get('active_hotel_id') ?? Auth::user()?->hotel_id);
        });
    }
    public function getDisplayNameAttribute(): string
    {
        $title = $this->salutation;

        // Konversi Enum -> string dengan aman
        if ($title instanceof BackedEnum) {
            $title = $title->value;   // untuk Backed Enum (punya ->value)
        } elseif ($title instanceof \UnitEnum) {
            $title = $title->name;    // untuk Unit Enum (punya ->name)
        }

        $title = $title ?: null; // kalau kosong biar nggak " " doang

        return trim(($title ? "{$title} " : '') . ($this->name ?? ''));
    }
}
