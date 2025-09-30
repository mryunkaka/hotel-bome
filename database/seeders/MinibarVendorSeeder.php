<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Faker\Factory as Faker;

// Jika kamu punya model App\Models\MinibarVendor, aktifkan baris berikut dan gunakan Eloquent.
// use App\Models\MinibarVendor;

class MinibarVendorSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create('id_ID');

        // Ambil daftar hotel; fallback ke [1] bila tabel hotels kosong
        $hotelIds = DB::table('hotels')->pluck('id');
        if ($hotelIds->isEmpty()) {
            $hotelIds = collect([1]);
        }

        // Daftar vendor default (realistis)
        $defaults = [
            [
                'name' => 'Aqua Danone',
                'contact_person' => 'Rizky Pratama',
                'phone' => '+62812' . $faker->numerify('########'),
                'email' => 'sales@aqua.co.id',
                'address' => 'Jl. Raya Bogor KM 26, Jakarta',
                'notes' => 'Supplier air mineral kemasan.',
            ],
            [
                'name' => 'Coca-Cola Europacific Partners',
                'contact_person' => 'Santi Lestari',
                'phone' => '+62813' . $faker->numerify('########'),
                'email' => 'order.id@ccEP.com',
                'address' => 'Kawasan Industri, Cibitung',
                'notes' => 'Soft drinks: Coke, Sprite, Fanta.',
            ],
            [
                'name' => 'Mayora Snacks Distributor',
                'contact_person' => 'Hendra Gunawan',
                'phone' => '+62815' . $faker->numerify('########'),
                'email' => 'sales@mayora.co.id',
                'address' => 'Tangerang, Banten',
                'notes' => 'Biskuit & wafer (Roma, Astor).',
            ],
            [
                'name' => 'Indofood Noodles',
                'contact_person' => 'Ayu Putri',
                'phone' => '+62816' . $faker->numerify('########'),
                'email' => 'order@indofood.co.id',
                'address' => 'Jl. Jend. Sudirman, Jakarta',
                'notes' => 'Mi instan & snack.',
            ],
            [
                'name' => 'Garudafood Distributor',
                'contact_person' => 'Bagus Saputra',
                'phone' => '+62817' . $faker->numerify('########'),
                'email' => 'cs@garudafood.co.id',
                'address' => 'Kawasan Industri MM2100, Bekasi',
                'notes' => 'Kacang & snack.',
            ],
        ];

        foreach ($hotelIds as $hotelId) {
            // Seed vendor default (idempotent)
            foreach ($defaults as $v) {
                DB::table('minibar_vendors')->updateOrInsert(
                    ['hotel_id' => $hotelId, 'name' => $v['name']],
                    [
                        'contact_person' => $v['contact_person'],
                        'phone' => $v['phone'],
                        'email' => strtolower($v['email']),
                        'address' => $v['address'],
                        'notes' => $v['notes'],
                        'updated_at' => now(),
                        'created_at' => now(),
                        'deleted_at' => null,
                    ]
                );
            }

            // Tambah 8 vendor acak (nama unik per hotel)
            for ($i = 0; $i < 8; $i++) {
                $company = $faker->unique()->company;
                DB::table('minibar_vendors')->updateOrInsert(
                    ['hotel_id' => $hotelId, 'name' => $company],
                    [
                        'contact_person' => $faker->name(),
                        'phone' => '+62' . $faker->numerify('8##########'),
                        'email' => strtolower(
                            preg_replace('/\s+/', '.', $company)
                        ) . '@example.com',
                        'address' => $faker->address(),
                        'notes' => $faker->randomElement([
                            'Pembayaran 30 hari.',
                            'Diskon kuantitas tersedia.',
                            'Hubungi sebelum pengiriman.',
                            'Termasuk PPN.',
                        ]),
                        'updated_at' => now(),
                        'created_at' => now(),
                        'deleted_at' => null,
                    ]
                );
            }
        }
    }
}
