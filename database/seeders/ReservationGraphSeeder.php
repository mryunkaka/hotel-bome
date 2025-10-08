<?php

namespace Database\Seeders;

use App\Models\Hotel;
use App\Models\Reservation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

class ReservationGraphSeeder extends Seeder
{
    public function run(): void
    {
        // ====== Context dasar ======
        $hotelId = Hotel::where('name', 'Hotel Bome')->value('id')
            ?? Hotel::orderBy('id')->value('id');

        if (! $hotelId) {
            $this->command?->warn('Hotel belum ada. Jalankan InitialSetupSeeder & RoomSeeder dulu.');
            return;
        }

        // creator (prioritas resepsionis -> supervisor -> super admin)
        $resepsionisId = DB::table('users')->where('hotel_id', $hotelId)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('model_has_roles')
                    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                    ->whereColumn('model_has_roles.model_id', 'users.id')
                    ->where('roles.name', 'resepsionis');
            })
            ->value('id');

        $supervisorId = DB::table('users')->where('hotel_id', $hotelId)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('model_has_roles')
                    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                    ->whereColumn('model_has_roles.model_id', 'users.id')
                    ->where('roles.name', 'supervisor');
            })
            ->value('id');

        $superAdminId = DB::table('users')->whereNull('hotel_id')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('model_has_roles')
                    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                    ->whereColumn('model_has_roles.model_id', 'users.id')
                    ->where('roles.name', 'super admin');
            })
            ->value('id');

        $createdBy = $resepsionisId ?? $supervisorId ?? $superAdminId;

        $idTax = DB::table('tax_settings')
            ->where('is_active', true)
            ->orderBy('id')
            ->value('id');

        // ====== Ambil 20 kamar (sesuai daftar kamu) ======
        $roomNos = [
            '101',
            '102',
            '103',
            '108',
            '109',
            '110',
            '201',
            '202',
            '203',
            '205',
            '206',
            '207',
            '208',
            '209',
            '210',
            '211',
            '212',
            '213',
            '215',
            '216',
        ];

        $rooms = DB::table('rooms')
            ->where('hotel_id', $hotelId)
            ->whereIn('room_no', $roomNos)
            ->orderBy('room_no')
            ->get(['id', 'room_no', 'price']);

        if ($rooms->count() < 20) {
            $this->command?->warn('Jumlah kamar yang tersedia < 20. Pastikan RoomSeeder sudah jalan.');
        }

        // ====== Pastikan minimal 20 guests tersedia (kalau kurang, buat dummy) ======
        $guestIds = DB::table('guests')
            ->where('hotel_id', $hotelId)
            ->orderBy('id')
            ->pluck('id')
            ->values()
            ->all();

        $need = max(0, 20 - count($guestIds));
        for ($i = 0; $i < $need; $i++) {
            $gid = DB::table('guests')->insertGetId([
                'hotel_id'   => $hotelId,
                'name'       => 'Guest ' . Str::padLeft((string) ($i + 1), 2, '0'),
                'email'      => 'guest' . Str::padLeft((string) ($i + 1 + count($guestIds)), 2, '0') . '@example.test',
                'phone'      => '08' . random_int(100000000, 999999999),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $guestIds[] = $gid;
        }

        // ====== Buat 20 reservation WALK-IN (checked-in, not checked-out) ======
        $today14 = Carbon::now()->startOfDay()->addHours(14);
        $tomorrow12 = Carbon::now()->addDay()->startOfDay()->addHours(12);

        DB::transaction(function () use ($rooms, $guestIds, $hotelId, $idTax, $createdBy, $today14, $tomorrow12) {

            // Hapus contoh lama (opsional): hanya kalau kamu memang ingin “bersih”
            // DB::table('reservations')->where('hotel_id', $hotelId)->delete();
            // DB::table('reservation_guests')->where('hotel_id', $hotelId)->delete();

            $i = 0;
            foreach ($rooms as $room) {
                if ($i >= 20) {
                    break;
                }

                $guestId   = $guestIds[$i] ?? $guestIds[array_rand($guestIds)];
                $roomRate  = (int) ($room->price ?? 350000);

                // Nomor reservasi format BARU → gunakan helper model
                // NOTE: helper butuh type 'WALKIN' (uppercase), sedangkan kolom option_reservation simpan 'walkin'
                $reservationNo = Reservation::generateReservationNo($hotelId, 'WALKIN');

                $resId = DB::table('reservations')->insertGetId([
                    'hotel_id'           => $hotelId,
                    'group_id'           => null,
                    'guest_id'           => $guestId,
                    'reservation_no'     => $reservationNo,   // ← pakai format baru
                    'option'             => null,
                    'option_reservation' => 'walkin',
                    'method'             => 'FRONTDESK',
                    'status'             => 'CONFIRM',
                    'expected_arrival'   => $today14->copy(),
                    'expected_departure' => $tomorrow12->copy(),
                    'checkin_date'       => $today14->copy(),
                    'checkout_date'      => null,
                    'deposit_type'       => 'NONE',
                    'deposit'            => 0,
                    'deposit_room'       => 0,
                    'deposit_card'       => 0,
                    'id_tax'             => $idTax,
                    'reserved_title'     => null,
                    'reserved_by'        => 'Walk-in Guest',
                    'reserved_number'    => null,
                    'reserved_by_type'   => 'GUEST',
                    'entry_date'         => now(),
                    'num_guests'         => 1,
                    'card_uid'           => null,
                    'created_by'         => $createdBy,
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ]);

                DB::table('reservation_guests')->insert([
                    'hotel_id'          => $hotelId,
                    'reservation_id'    => $resId,
                    'guest_id'          => $guestId,
                    'room_id'           => $room->id,
                    'expected_checkin'  => $today14->copy(),
                    'expected_checkout' => $tomorrow12->copy(),
                    'actual_checkin'    => $today14->copy(),    // ✅ sudah check-in
                    'actual_checkout'   => null,                // ✅ belum check-out
                    'service'           => 0,
                    'charge'            => 0,
                    'room_rate'         => $roomRate,
                    'extra_bed'         => 0,
                    'breakfast'         => null,
                    'person'            => null,
                    'male'              => 0,
                    'female'            => 0,
                    'children'          => 0,
                    'jumlah_orang'      => 1,
                    'pov'               => null,
                    'note'              => 'In-house',
                    'discount_percent'  => 0,
                    'charge_to'         => null,
                    'rate_type'         => null,
                    'bill_no'           => null,
                    'bill_closed_at'    => null,
                    'created_by'        => $createdBy,
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]);

                $i++;
            }
        });

        $this->command?->info('ReservationGraphSeeder: 20 data in-house (sudah check-in, belum check-out) dibuat dengan nomor format baru.');
    }
}
