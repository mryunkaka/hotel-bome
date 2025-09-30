<?php

namespace Database\Seeders;

use App\Models\Hotel;
use App\Models\MinibarItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MinibarItemSeeder extends Seeder
{
    public function run(): void
    {
        // Ambil semua hotel; kalau belum ada, fallback ke ID 1.
        $hotels = Hotel::query()->pluck('id')->all();
        if (empty($hotels)) {
            $hotels = [1];
        }

        // Katalog default (tanpa hotel_id & sku karena akan diisi otomatis).
        $catalog = [
            // Drinks
            ['name' => 'Air Mineral 600ml',     'category' => 'drink',    'unit' => 'bottle', 'default_cost_price' => 3000,  'default_sale_price' => 6000,  'reorder_level' => 24],
            ['name' => 'Cola 330ml',            'category' => 'drink',    'unit' => 'can',    'default_cost_price' => 4500,  'default_sale_price' => 9000,  'reorder_level' => 24],
            ['name' => 'Jus Kotak 250ml',       'category' => 'drink',    'unit' => 'pack',   'default_cost_price' => 5000,  'default_sale_price' => 10000, 'reorder_level' => 12],

            // Snacks
            ['name' => 'Potato Chips 50g',      'category' => 'snack',    'unit' => 'pack',   'default_cost_price' => 4000,  'default_sale_price' => 8000,  'reorder_level' => 20],
            ['name' => 'Chocolate Bar 40g',     'category' => 'snack',    'unit' => 'pcs',    'default_cost_price' => 5000,  'default_sale_price' => 10000, 'reorder_level' => 20],

            // Instant / Cup
            ['name' => 'Cup Noodles',           'category' => 'instant',  'unit' => 'pcs',    'default_cost_price' => 6000,  'default_sale_price' => 12000, 'reorder_level' => 24],
            ['name' => 'Kopi Instan 20g',       'category' => 'instant',  'unit' => 'pcs',    'default_cost_price' => 2000,  'default_sale_price' => 5000,  'reorder_level' => 30],

            // Amenities
            ['name' => 'Toothbrush Set',        'category' => 'amenity',  'unit' => 'pcs',    'default_cost_price' => 3500,  'default_sale_price' => 7000,  'reorder_level' => 30],
            ['name' => 'Shower Cap',            'category' => 'amenity',  'unit' => 'pcs',    'default_cost_price' => 1500,  'default_sale_price' => 4000,  'reorder_level' => 30],

            // Alcohol (opsional, bisa dihapus kalau hotel non-alkohol)
            ['name' => 'Beer 330ml',            'category' => 'alcohol',  'unit' => 'bottle', 'default_cost_price' => 15000, 'default_sale_price' => 30000, 'reorder_level' => 24],

            // Cigarette (opsional)
            ['name' => 'Cigarettes 12',         'category' => 'cigarette', 'unit' => 'pack',   'default_cost_price' => 18000, 'default_sale_price' => 30000, 'reorder_level' => 10],

            // Other
            ['name' => 'Laundry Bag',           'category' => 'other',    'unit' => 'pcs',    'default_cost_price' => 2000,  'default_sale_price' => 5000,  'reorder_level' => 10],
        ];

        DB::transaction(function () use ($hotels, $catalog) {
            foreach ($hotels as $hotelId) {
                foreach ($catalog as $row) {
                    MinibarItem::query()->firstOrCreate(
                        [
                            'hotel_id' => $hotelId,
                            'name'     => $row['name'],
                        ],
                        [
                            'hotel_id'            => $hotelId,
                            'sku'                 => null,                 // biarkan model generate
                            'category'            => $row['category'],
                            'unit'                => $row['unit'],
                            'default_cost_price'  => $row['default_cost_price'],
                            'default_sale_price'  => $row['default_sale_price'],
                            'current_stock'       => 0,                    // stok awal 0 → restock via halaman
                            'reorder_level'       => $row['reorder_level'],
                            'is_active'           => true,
                        ]
                    );
                }
            }
        });
    }
}
