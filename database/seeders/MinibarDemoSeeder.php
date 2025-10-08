<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MinibarDemoSeeder extends Seeder
{
    public function run(): void
    {
        $hotelId = \App\Models\Hotel::where('name', 'Hotel Bome')->value('id')
            ?? \App\Models\Hotel::orderBy('id')->value('id');

        $performedBy = \App\Models\User::where('hotel_id', $hotelId)
            ->whereHas('roles', fn($q) => $q->where('name', 'resepsionis'))
            ->value('id')
            ?? \App\Models\User::where('hotel_id', $hotelId)
            ->whereHas('roles', fn($q) => $q->where('name', 'supervisor'))
            ->value('id')
            ?? \App\Models\User::whereNull('hotel_id')
            ->whereHas('roles', fn($q) => $q->where('name', 'super admin'))
            ->value('id');

        $item = DB::table('minibar_items')->where('hotel_id', $hotelId)->orderBy('id')->first();
        if (! $item) {
            return;
        }

        // 1) Restock hari ini
        DB::table('minibar_stock_movements')->insert([
            'hotel_id'       => $hotelId,
            'item_id'        => $item->id,
            'movement_type'  => 'restock',
            'quantity'       => 50,
            'unit_cost'      => 3000,
            'unit_price'     => null,
            'vendor_id'      => null,
            'receipt_id'     => null,
            'reservation_guest_id' => null,
            'reference_no'   => 'RESTOCK-' . now()->format('Ymd'),
            'performed_by'   => $performedBy,
            'notes'          => 'Seed restock',
            'happened_at'    => now()->subHours(2),
            'closing_id'     => null,
            'created_at'     => now()->subHours(2),
            'updated_at'     => now()->subHours(2),
        ]);

        // 2) Buat receipt paid (jual 2 item)
        $receiptId = DB::table('minibar_receipts')->insertGetId([
            'hotel_id'             => $hotelId,
            'receipt_no'           => 'MN-' . now()->format('YmdHis'),
            'reservation_guest_id' => null,
            'subtotal_amount'      => 2 * 6000,
            'discount_amount'      => 0,
            'tax_amount'           => 0,
            'total_amount'         => 2 * 6000,
            'total_cogs'           => 2 * 3000,
            'status'               => 'paid',
            'created_by'           => $performedBy,
            'issued_at'            => now()->subHour(),
            'closing_id'           => null,
            'created_at'           => now()->subHour(),
            'updated_at'           => now()->subHour(),
            'deleted_at'           => null,
        ]);

        DB::table('minibar_receipt_items')->insert([
            'receipt_id' => $receiptId,
            'item_id'    => $item->id,
            'quantity'   => 2,
            'unit_price' => 6000,
            'unit_cost'  => 3000,
            'line_total' => 12000,
            'line_cogs'  => 6000,
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        // 3) Movement sale (untuk stok keluar)
        DB::table('minibar_stock_movements')->insert([
            'hotel_id'       => $hotelId,
            'item_id'        => $item->id,
            'movement_type'  => 'sale',
            'quantity'       => -2,
            'unit_cost'      => 3000,
            'unit_price'     => 6000,
            'vendor_id'      => null,
            'receipt_id'     => $receiptId,
            'reservation_guest_id' => null,
            'reference_no'   => 'MN-LINK-' . $receiptId,
            'performed_by'   => $performedBy,
            'notes'          => 'Seed sale link to receipt',
            'happened_at'    => now()->subHour(),
            'closing_id'     => null,
            'created_at'     => now()->subHour(),
            'updated_at'     => now()->subHour(),
        ]);
    }
}
