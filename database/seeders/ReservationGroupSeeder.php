<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ReservationGroupSeeder extends Seeder
{
    public function run(): void
    {
        $hotelId = \App\Models\Hotel::where('name', 'Hotel Bome')->value('id')
            ?? \App\Models\Hotel::orderBy('id')->value('id');

        DB::table('reservation_groups')->updateOrInsert(
            ['hotel_id' => $hotelId, 'name' => 'PT Nusantara Tour'],
            [
                'address'     => 'Jl. Mawar No. 5',
                'city'        => 'Makassar',
                'phone'       => '0411-555000',
                'email'       => 'sales@nusantaratour.id',
                'remark_ci'   => 'Early CI possible',
                'long_remark' => 'Group corporate.',
                'created_at'  => now(),
                'updated_at'  => now(),
            ]
        );
    }
}
