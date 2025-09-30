<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Guest;
use Faker\Factory as Faker;
use Illuminate\Support\Str;

class GuestSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create('id_ID');

        $hotelId = 1;

        // helper nomor telepon Indonesia (E.164): +628xxxxxxxxxx
        $makePhone = function () {
            // 8–11 digit setelah 08 → total 10–13 digit setelah +62
            $len = random_int(9, 11);
            $digits = '';
            for ($i = 0; $i < $len; $i++) {
                $digits .= random_int(0, 9);
            }
            // hindari mulai dengan 0 setelah +62
            if ($digits !== '' && $digits[0] === '0') {
                $digits[0] = (string) random_int(1, 9);
            }
            return '+62' . $digits;
        };

        // helper ID number (KTP/SIM/Passport-like)
        $makeIdNumber = function (string $type) {
            return match ($type) {
                'Passport'       => strtoupper(Str::random(2)) . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
                'Driver License' => str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT),
                default          => str_pad((string) random_int(0, 9999999999999), 13, '0', STR_PAD_LEFT), // National ID
            };
        };

        for ($i = 0; $i < 20; $i++) {
            // salutation + gendered name
            $salutation = $faker->randomElement(['MR', 'MRS', 'MISS']);
            $gender     = $salutation === 'MR' ? 'male' : 'female';
            $fullName   = $faker->name($gender);

            // guest type & nationality
            $guestType   = $faker->randomElement(['DOMESTIC', 'INTERNATIONAL']);
            $nationality = $guestType === 'DOMESTIC'
                ? 'Indonesia'
                : $faker->randomElement(['Malaysia', 'Singapore', 'Japan', 'Australia', 'Thailand', 'Philippines', 'Vietnam']);

            // id type & number
            $idTypeOptions = ['ID', 'PASSPORT', 'DRIVER_LICENSE', 'OTHER'];
            $idType        = $faker->randomElement($idTypeOptions);
            $idNumber      = $makeIdNumber($idType);

            // tanggal lahir & terbit (issued_date >= birth_date + 17 tahun)
            $birthDateYear  = (int) now()->subYears(random_int(18, 70))->format('Y');
            $birthDate      = $faker->dateTimeBetween("{$birthDateYear}-01-01", "{$birthDateYear}-12-31");
            $issuedMin      = (clone $birthDate)->modify('+17 years');
            // guard: kalau issuedMin di masa depan, mundurkan ke -1 tahun dari sekarang
            if ($issuedMin > new \DateTime()) {
                $issuedMin = (new \DateTime())->modify('-1 year');
            }
            $issuedDate     = $faker->dateTimeBetween($issuedMin, 'now');

            // file id (fake path yang valid, bukan null)
            $idCardFile = 'uploads/ids/' . Str::slug($fullName) . '-' . Str::random(6) . '.jpg';

            Guest::create([
                'hotel_id'     => $hotelId,

                // identitas dasar
                'salutation'   => $salutation,
                'name'         => $fullName,
                'guest_type'   => $guestType,
                'nationality'  => $nationality,

                // kontak & alamat
                'address'      => $faker->streetAddress . ', ' . $faker->streetName,
                'city'         => $faker->city,
                'profession'   => $faker->jobTitle,
                'email'        => $faker->unique()->safeEmail(),
                'phone'        => $makePhone(),

                // identitas resmi
                'id_type'      => $idType,
                'id_card'      => $idNumber,
                'id_card_file' => $idCardFile,

                // tempat & tanggal
                'birth_place'  => $faker->city,
                'birth_date'   => $birthDate->format('Y-m-d'),
                'issued_place' => $faker->city,
                'issued_date'  => $issuedDate->format('Y-m-d'),
            ]);
        }
    }
}
