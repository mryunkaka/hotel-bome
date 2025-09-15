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

        for ($i = 0; $i < 20; $i++) {
            Guest::create([
                'hotel_id'     => $hotelId,

                // identitas dasar
                'salutation'  => $faker->randomElement(['MR', 'MRS', 'MISS']),
                'name'        => $faker->name,
                'guest_type'  => $faker->randomElement(['DOMESTIC', 'INTERNATIONAL']),
                'nationality' => $faker->randomElement(['Indonesia', 'Malaysia', 'Singapore', 'Japan', 'Australia']),

                // kontak & alamat
                'address'     => $faker->address,
                'city'        => $faker->city,
                'profession'  => $faker->jobTitle,
                'email'       => $faker->unique()->safeEmail,
                'phone'       => $faker->unique()->phoneNumber,

                // identitas resmi
                'id_type'      => $faker->randomElement(['Passport', 'National ID', 'Driver License']),
                'id_card'      => $faker->numerify('###########'),
                'id_card_file' => null,

                // tempat & tanggal
                'birth_place'  => $faker->city,
                'birth_date'   => $faker->optional()->date(),
                'issued_place' => $faker->city,
                'issued_date'  => $faker->optional()->date(),

                // keluarga
                'father' => $faker->optional()->name('male'),
                'mother' => $faker->optional()->name('female'),
                'spouse' => $faker->optional()->name,
            ]);
        }
    }
}
