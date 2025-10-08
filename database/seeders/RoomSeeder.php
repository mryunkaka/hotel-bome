<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoomSeeder extends Seeder
{
    public function run(): void
    {
        $hotelId = \App\Models\Hotel::where('name', 'Hotel Bome')->value('id')
            ?? \App\Models\Hotel::orderBy('id')->value('id');

        $prices = [
            'DELUXE TWIN'     => 400_000,
            'DELUXE PREMIUM'  => 400_000,
            'SUPERIOR QUEEN'  => 350_000,
            'SUPERIOR TWIN'   => 350_000,
            'STANDARD 1'      => 250_000,
            'STANDARD 2'      => 175_000,
        ];

        $rooms = [
            // Lt.1
            ['room_no' => '101', 'type' => 'DELUXE TWIN'],
            ['room_no' => '102', 'type' => 'DELUXE TWIN'],
            ['room_no' => '103', 'type' => 'DELUXE TWIN'],
            ['room_no' => '108', 'type' => 'SUPERIOR QUEEN'],
            ['room_no' => '109', 'type' => 'SUPERIOR QUEEN'],
            ['room_no' => '110', 'type' => 'SUPERIOR QUEEN'],
            // Lt.2
            ['room_no' => '201', 'type' => 'SUPERIOR TWIN'],
            ['room_no' => '202', 'type' => 'STANDARD 1'],
            ['room_no' => '203', 'type' => 'STANDARD 1'],
            ['room_no' => '205', 'type' => 'STANDARD 1'],
            ['room_no' => '206', 'type' => 'DELUXE PREMIUM'],
            ['room_no' => '207', 'type' => 'STANDARD 1'],
            ['room_no' => '208', 'type' => 'STANDARD 1'],
            ['room_no' => '209', 'type' => 'STANDARD 1'],
            ['room_no' => '210', 'type' => 'STANDARD 1'],
            ['room_no' => '211', 'type' => 'STANDARD 1'],
            ['room_no' => '212', 'type' => 'STANDARD 1'],
            ['room_no' => '213', 'type' => 'STANDARD 1'],
            ['room_no' => '215', 'type' => 'STANDARD 2'],
            ['room_no' => '216', 'type' => 'STANDARD 2'],
        ];

        $payload = [];
        foreach ($rooms as $r) {
            $rn = (int) $r['room_no'];
            $payload[] = [
                'hotel_id'   => $hotelId,
                'type'       => $r['type'],
                'room_no'    => $r['room_no'],
                'floor'      => intdiv($rn, 100),
                'price'      => $prices[$r['type']] ?? 0,
                'status'     => \App\Models\Room::ST_VCI ?? 'VCI', // jika model define konstanta
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('rooms')->upsert(
            $payload,
            ['hotel_id', 'room_no'],
            ['type', 'floor', 'price', 'status', 'updated_at']
        );
    }
}
