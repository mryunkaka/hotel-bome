<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TruncateTablesSeeder extends Seeder
{
    public function run(): void
    {
        // Matikan FK sementara (MySQL/MariaDB)
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        // Urutan penting: tabel anak lebih dulu baru induk
        $tables = [
            // transaksi harian
            'guests',
            'bank_ledgers',
            'ledger_accounts',
            'payments',
            'minibar_receipt_items',
            'minibar_receipts',
            'minibar_stock_movements',
            'minibar_daily_closing_items',
            'minibar_daily_closings',
            'room_daily_closings',

            // entitas terkait reservasi
            'reservation_guests',
            'reservations',
            'reservation_groups',

            // master (opsional, hapus jika ingin tetap)
            // 'rooms',
            // 'guests',
            // 'banks',
            // 'tax_settings',
        ];

        foreach ($tables as $table) {
            DB::table($table)->truncate();
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    }
}
