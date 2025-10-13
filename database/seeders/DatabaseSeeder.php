<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            InitialSetupSeeder::class,
            TaxSettingSeeder::class,
            RoomSeeder::class,
            GuestSeeder::class,
            BankSeeder::class,
            ReservationGroupSeeder::class,
            MinibarVendorSeeder::class,
            MinibarItemSeeder::class,
        ]);
    }
}
