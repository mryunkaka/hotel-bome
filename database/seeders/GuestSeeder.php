<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Faker\Factory as Faker;
use App\Enums\Salutation;

class GuestSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create('id_ID');

        $hotelIds = DB::table('hotels')->pluck('id');
        if ($hotelIds->isEmpty()) {
            $hotelIds = collect([1]);
        }

        // Backing values enum Salutation, mis: ['MR','MRS','MS','BPK','IBU', ...]
        $salutationValues = array_map(fn($c) => $c->value, Salutation::cases());

        $idTypes    = ['ID', 'PASSPORT', 'DRIVER_LICENSE', 'OTHER'];
        $guestTypes = ['DOMESTIC', 'INTERNATIONAL']; // ⬅️ disesuaikan dgn Select pv_guest_type

        foreach ($hotelIds as $hid) {
            $faker->unique(true); // reset scope unique per hotel

            for ($i = 1; $i <= 50; $i++) {
                $name  = $faker->name();
                $email = strtolower(str_replace(' ', '.', $name)) . "+h{$hid}@example.com";

                DB::table('guests')->insert([
                    'hotel_id'      => $hid,
                    'name'          => $name,

                    // gunakan backing value enum; 80% berisi, 20% null
                    'salutation'    => $faker->optional(0.8)->randomElement($salutationValues),

                    // ⬅️ hanya DOMESTIC / INTERNATIONAL
                    'guest_type'    => $faker->randomElement($guestTypes),

                    'id_type'       => $faker->randomElement($idTypes),

                    'birth_place'   => $faker->city,
                    'birth_date'    => $faker->dateTimeBetween('-60 years', '-20 years')->format('Y-m-d'),
                    'issued_place'  => $faker->city,
                    'issued_date'   => $faker->dateTimeBetween('-10 years', 'now')->format('Y-m-d'),

                    'email'         => $email,
                    'phone'         => '08' . $faker->numerify('##########'),
                    'city'          => $faker->city,
                    'nationality'   => 'Indonesia',
                    'profession'    => $faker->jobTitle,
                    'address'       => $faker->address,

                    // unik per hotel_id + id_card + deleted_at
                    'id_card'       => $faker->unique()->numerify('3273############'),
                    'id_card_file'  => null,

                    'father'        => $faker->firstNameMale . ' ' . $faker->lastName,
                    'mother'        => $faker->firstNameFemale . ' ' . $faker->lastName,
                    'spouse'        => $faker->optional()->name(),

                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            }
        }
    }
}
