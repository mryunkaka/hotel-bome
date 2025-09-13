<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Guest;
use Faker\Factory as Faker;

class GuestSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create('id_ID');

        // fallback hotel_id = 1 untuk seeder
        $hotelId = 1;

        for ($i = 0; $i < 100; $i++) {
            Guest::create([
                'hotel_id'     => $hotelId,

                // identitas dasar
                'name'        => $faker->name,
                'salutation'  => $faker->randomElement(['MR', 'MRS', 'MISS']),
                'guest_type'  => $faker->randomElement(['DOMESTIC', 'INTERNATIONAL']), // ✅ update
                'nationality' => $faker->randomElement(['Indonesia', 'Malaysia', 'Singapore', 'Japan', 'Australia']),

                // kontak & alamat
                'address'     => $faker->address,
                'city'        => $faker->city,
                'profession'  => $faker->jobTitle,
                'email'       => $faker->unique()->safeEmail,
                'phone'       => $faker->unique()->phoneNumber,

                // identitas
                'id_type'      => $faker->randomElement(['KTP', 'PASSPORT', 'SIM']),
                'id_card'      => $faker->numerify('###########'),
                'id_card_file' => null, // dummy, bisa isi 'guests/id/dummy.pdf'

                // tempat & tanggal
                'birth_place'  => $faker->city,
                'birth_date'   => $faker->optional()->date(),
                'issued_place' => $faker->city,   // ✅ string (bukan date)
                'issued_date'  => $faker->optional()->date(),

                // keluarga
                'father' => $faker->optional()->name('male'),
                'mother' => $faker->optional()->name('female'),
                'spouse' => $faker->optional()->name,
            ]);
        }
    }
}
