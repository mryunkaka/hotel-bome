<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Hotel;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Hash;

class InitialSetupSeeder extends Seeder
{
    public function run(): void
    {
        // === 1. Buat 2 hotel ===
        $hotel1 = Hotel::create([
            'name' => 'Hotel Bome',
            'address' => 'Jl. Merdeka No. 123',
            'phone' => '0511-123456',
            'email' => 'info@hotelbome.test',
        ]);

        $hotel2 = Hotel::create([
            'name' => 'Hotel Suka Maju',
            'address' => 'Jl. Pahlawan No. 45',
            'phone' => '0511-654321',
            'email' => 'info@hotelsukamaju.test',
        ]);

        // === 2. Roles ===
        foreach (['super admin', 'supervisor', 'resepsionis'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        // === 3. Super Admin ===
        $superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'admin@system.test',
            'password' => Hash::make('password'),
            'hotel_id' => null,
        ]);
        $superAdmin->assignRole('super admin');

        // === 4. Hotel 1 Users ===
        $sup1 = User::create([
            'name' => 'Supervisor Bome',
            'email' => 'supervisor.bome@test.com',
            'password' => Hash::make('password'),
            'hotel_id' => $hotel1->id,
        ]);
        $sup1->assignRole('supervisor');

        $res1 = User::create([
            'name' => 'Resepsionis Bome',
            'email' => 'resepsionis.bome@test.com',
            'password' => Hash::make('password'),
            'hotel_id' => $hotel1->id,
        ]);
        $res1->assignRole('resepsionis');

        // === 5. Hotel 2 Users ===
        $sup2 = User::create([
            'name' => 'Supervisor Suka Maju',
            'email' => 'supervisor.sukamaju@test.com',
            'password' => Hash::make('password'),
            'hotel_id' => $hotel2->id,
        ]);
        $sup2->assignRole('supervisor');

        $res2 = User::create([
            'name' => 'Resepsionis Suka Maju',
            'email' => 'resepsionis.sukamaju@test.com',
            'password' => Hash::make('password'),
            'hotel_id' => $hotel2->id,
        ]);
        $res2->assignRole('resepsionis');

        $this->command->info('Seeder sukses: 2 hotel, 1 super admin, 2 supervisor, 2 resepsionis.');
    }
}
