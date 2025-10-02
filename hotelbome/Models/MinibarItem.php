<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class MinibarItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'minibar_items';

    // Master opsi
    public const CATEGORIES = [
        'drink'     => 'Drink',
        'snack'     => 'Snack',
        'instant'   => 'Instant / Cup',
        'amenity'   => 'Amenity',
        'alcohol'   => 'Alcohol',
        'cigarette' => 'Cigarette',
        'other'     => 'Other',
    ];

    public const UNITS = [
        'pcs'    => 'pcs',
        'bottle' => 'bottle',
        'can'    => 'can',
        'pack'   => 'pack',
        'box'    => 'box',
        'ml'     => 'ml',
        'l'      => 'l',
        'gram'   => 'gram',
        'kg'     => 'kg',
    ];

    protected $fillable = [
        'hotel_id',
        'sku',
        'name',
        'category',
        'unit',
        'default_cost_price',
        'default_sale_price',
        'current_stock',
        'reorder_level',
        'is_active',
    ];

    protected $casts = [
        'default_cost_price' => 'decimal:2',
        'default_sale_price' => 'decimal:2',
        'is_active'          => 'boolean',
    ];

    /* ---------- Relations ---------- */
    public function hotel()
    {
        return $this->belongsTo(Hotel::class);
    }
    public function receiptItems()
    {
        return $this->hasMany(MinibarReceiptItem::class, 'item_id');
    }
    public function stockMovements()
    {
        return $this->hasMany(MinibarStockMovement::class, 'item_id');
    }

    /* ---------- Scopes ---------- */
    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    /* ---------- Helpers for Filament ---------- */
    public static function categoryOptions(): array
    {
        return self::CATEGORIES;
    }

    public static function unitOptions(): array
    {
        return self::UNITS;
    }

    /* ---------- SKU Auto Generator ---------- */
    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (blank($model->sku)) {
                $model->sku = self::makeUniqueSku($model);
            }
        });

        static::updating(function (self $model) {
            // Jika sku dibiarkan kosong & ada perubahan name/category/unit → generate lagi
            if (
                blank($model->sku) &&
                $model->isDirty(['name', 'category', 'unit'])
            ) {
                $model->sku = self::makeUniqueSku($model);
            }
        });
    }

    protected static function makeUniqueSku(self $model): string
    {
        // 1) Kode kategori ringkas
        $cat = strtolower((string) ($model->category ?: 'other'));
        $catMap = [
            'drink'     => 'DRK',
            'snack'     => 'SNK',
            'instant'   => 'INS',
            'amenity'   => 'AMN',
            'alcohol'   => 'ALC',
            'cigarette' => 'CIG',
            'other'     => 'OTH',
        ];
        $catCode = $catMap[$cat] ?? strtoupper(substr(preg_replace('/[^a-z]/i', '', $cat), 0, 3) ?: 'OTH');

        // 2) Inisial nama + angka di nama (jika ada)
        $name = (string) $model->name;

        // Ambil huruf2 (kata) → bentuk inisial (maks 3 huruf)
        preg_match_all('/[A-Za-z]+/u', $name, $mWords);
        $words = $mWords[0] ?? [];
        $initials = '';
        foreach ($words as $w) {
            $initials .= strtoupper(Str::of($w)->substr(0, 1));
            if (strlen($initials) >= 3) break;
        }
        if ($initials === '') {
            $initials = 'ITM'; // fallback
        }

        // Ambil angka dalam nama (contoh "600ml" → "600"; gabungkan bila lebih dari satu grup)
        $numPart = '';
        if (preg_match_all('/\d+/', $name, $mNums) && !empty($mNums[0])) {
            $numPart = implode('', $mNums[0]);              // "600", "330", "50" ...
            $numPart = substr($numPart, 0, 4);              // batasi 4 digit biar ringkas
        }

        $nameCode = $initials . $numPart;                   // contoh: "AM600", "CC330", "ST50"

        // 3) Bentuk dasar SKU: CAT-NameCode
        $base = $catCode . '-' . $nameCode;

        // Bersihkan karakter tak perlu (jaga-jaga)
        $base = preg_replace('/[^A-Z0-9\-]/', '', $base) ?: 'ITEM';

        // 4) Pastikan unik per hotel (suffix -2, -3, ...)
        $sku = $base;
        $i = 2;
        while (
            self::query()
            ->where('hotel_id', $model->hotel_id)
            ->where('sku', $sku)
            ->exists()
        ) {
            $sku = $base . '-' . $i;
            $i++;
        }

        return $sku;
    }
}
