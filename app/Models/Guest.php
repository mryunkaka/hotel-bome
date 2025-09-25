<?php

namespace App\Models;

use BackedEnum;
use App\Enums\Salutation;
use Illuminate\Support\Arr;
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

    public function reservationGuests()
    {
        return $this->hasMany(ReservationGuest::class);
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

    public static function optionsForSelect(
        ?string $search = null,
        ?int $currentGuestId = null,
        ?int $limit = 200
    ): array {
        $hid = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;

        // --- Ambil daftar guest yang sudah dipilih di repeater / form saat ini (belum tersimpan) ---
        // a) dari param yang bisa dikirim caller
        $selectedFromParam = (array) request()->input('selected_guest_ids_for_filter', []); // FIX
        // b) fallback: baca langsung dari payload form Livewire: data.reservationGuests.*.guest_id  // FIX
        $selectedFromForm = Arr::flatten(
            (array) Arr::get(request()->input(), 'data.reservationGuests.*.guest_id', [])
        );
        // Gabungkan & normalisasi                                                     // FIX
        $selectedGuestIds = collect($selectedFromParam)
            ->merge($selectedFromForm)
            ->filter(fn($id) => (int) $id > 0 && (int) $id !== (int) $currentGuestId)
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $q = static::query()
            ->where(function ($q) use ($hid, $selectedGuestIds, $currentGuestId, $search) {
                // Cabang 1: kandidat normal (BUKAN duplikat di form & BUKAN aktif di DB)     // FIX
                $q->where('hotel_id', $hid)
                    ->when(!empty($selectedGuestIds), fn($qq) => $qq->whereNotIn('id', $selectedGuestIds))
                    ->when(filled($search), fn($qq) => $qq->where(function ($s) use ($search) {
                        $s->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%")
                            ->orWhere('id_card', 'like', "%{$search}%");
                    }))
                    ->whereNotExists(function ($sub) use ($hid) {
                        // Exclude SEMUA yang masih aktif (reservasi ATAU sudah check-in)         // FIX
                        $sub->from('reservation_guests as rg')
                            ->whereColumn('rg.guest_id', 'guests.id')
                            ->where('rg.hotel_id', $hid)
                            ->whereNull('rg.actual_checkout'); // belum checkout = masih aktif
                    });

                // Cabang 2: selalu sertakan current selection supaya tidak hilang saat edit
                if ($currentGuestId > 0) {
                    $q->orWhere('id', $currentGuestId);
                }
            })
            ->orderBy('name')
            ->limit($limit ?? 200)
            ->get(['id', 'name', 'id_card']);

        return $q->mapWithKeys(function ($g) {
            $idCard = trim((string) ($g->id_card ?? ''));
            $label = $g->name . ($idCard !== '' && $idCard !== '-' ? " ({$idCard})" : '');
            return [$g->id => $label];
        })->toArray();
    }

    public static function labelForSelect(int $id): ?string
    {
        $g = static::query()->select('name', 'id_card')->find($id);
        if (! $g) return null;
        $idCard = trim((string) ($g->id_card ?? ''));
        return $g->name . ($idCard !== '' && $idCard !== '-' ? " ({$idCard})" : '');
    }
}
