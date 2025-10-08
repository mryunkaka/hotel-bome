<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Faker\Factory as Faker;

class MinibarVendorSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create('id_ID');

        $hotelIds = \App\Models\Hotel::pluck('id');
        if ($hotelIds->isEmpty()) {
            return;
        }

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
                'email' => 'order.id@ccep.com',
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
        ];

        foreach ($hotelIds as $hotelId) {
            foreach ($defaults as $v) {
                DB::table('minibar_vendors')->updateOrInsert(
                    ['hotel_id' => $hotelId, 'name' => $v['name']],
                    [
                        'contact_person' => $v['contact_person'],
                        'phone'          => $v['phone'],
                        'email'          => strtolower($v['email']),
                        'address'        => $v['address'],
                        'notes'          => $v['notes'],
                        'updated_at'     => now(),
                        'created_at'     => now(),
                        'deleted_at'     => null,
                    ]
                );
            }

            for ($i = 0; $i < 5; $i++) {
                $company = $faker->unique()->company;
                DB::table('minibar_vendors')->updateOrInsert(
                    ['hotel_id' => $hotelId, 'name' => $company],
                    [
                        'contact_person' => $faker->name(),
                        'phone'          => '+62' . $faker->numerify('8##########'),
                        'email'          => strtolower(preg_replace('/\s+/', '.', $company)) . '@example.com',
                        'address'        => $faker->address(),
                        'notes'          => $faker->randomElement([
                            'Pembayaran 30 hari.',
                            'Diskon kuantitas tersedia.',
                            'Hubungi sebelum pengiriman.',
                            'Termasuk PPN.',
                        ]),
                        'updated_at'     => now(),
                        'created_at'     => now(),
                        'deleted_at'     => null,
                    ]
                );
            }
        }
    }
}
