<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            TruncateTablesSeeder::class,
            InitialSetupSeeder::class,   // hotel & users (roles)
            TaxSettingSeeder::class,     // pajak aktif global
            RoomSeeder::class,           // kamar
            GuestSeeder::class,          // tamu dummy
            ReservationGroupSeeder::class,
            // ReservationGraphSeeder::class, // skenario A/B/C
            MinibarVendorSeeder::class,
            MinibarItemSeeder::class,
            MinibarDemoSeeder::class,    // demo struk & movement
        ]);
    }
}
