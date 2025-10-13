<?php

namespace Database\Seeders;

use App\Models\Bank;
use App\Models\Hotel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class BankSeeder extends Seeder
{
    public function run(): void
    {
        // Ambil semua hotel aktif
        $hotels = Hotel::query()->get(['id', 'name']);

        if ($hotels->isEmpty()) {
            $this->command->warn('⚠️ Tidak ada data hotel. Jalankan HotelSeeder dulu.');
            return;
        }

        // Daftar bank umum Indonesia (contoh seed)
        $banks = [
            [
                'name'        => 'Bank Central Asia',
                'short_code'  => 'BCA',
                'branch'      => 'Cabang Utama Jakarta',
                'account_no'  => '1234567890',
                'holder_name' => 'PT Hotel Bome Nusantara',
                'currency'    => 'IDR',
                'swift_code'  => 'CENAIDJA',
            ],
            [
                'name'        => 'Bank Mandiri',
                'short_code'  => 'MANDIRI',
                'branch'      => 'Cabang Banjarbaru',
                'account_no'  => '9876543210',
                'holder_name' => 'PT Hotel Bome Nusantara',
                'currency'    => 'IDR',
                'swift_code'  => 'BMRIIDJA',
            ],
            [
                'name'        => 'Bank Negara Indonesia',
                'short_code'  => 'BNI',
                'branch'      => 'Cabang Banjarmasin',
                'account_no'  => '1122334455',
                'holder_name' => 'PT Hotel Bome Nusantara',
                'currency'    => 'IDR',
                'swift_code'  => 'BNINIDJA',
            ],
            [
                'name'        => 'Bank Rakyat Indonesia',
                'short_code'  => 'BRI',
                'branch'      => 'Cabang Martapura',
                'account_no'  => '5566778899',
                'holder_name' => 'PT Hotel Bome Nusantara',
                'currency'    => 'IDR',
                'swift_code'  => 'BRINIDJA',
            ],
        ];

        foreach ($hotels as $hotel) {
            foreach ($banks as $bank) {
                Bank::updateOrCreate(
                    [
                        'hotel_id'   => $hotel->id,
                        'short_code' => $bank['short_code'],
                    ],
                    [
                        'name'        => $bank['name'],
                        'branch'      => $bank['branch'],
                        'account_no'  => $bank['account_no'],
                        'holder_name' => $bank['holder_name'],
                        'currency'    => $bank['currency'],
                        'swift_code'  => $bank['swift_code'],
                        'is_active'   => true,
                        'notes'       => 'Data default seeder untuk ' . $hotel->name,
                        'email'       => strtolower($bank['short_code']) . '@' . Str::slug($hotel->name) . '.com',
                    ]
                );
            }
        }

        $this->command->info('✅ BankSeeder selesai — data bank default sudah dimasukkan untuk semua hotel.');
    }
}
