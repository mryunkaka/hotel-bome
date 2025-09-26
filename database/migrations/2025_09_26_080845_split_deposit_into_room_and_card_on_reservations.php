<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            // Kolom baru
            $table->unsignedBigInteger('deposit_room')->default(0)->after('status'); // DP Reservasi
            $table->unsignedBigInteger('deposit_card')->default(0)->after('deposit_room'); // Jaminan saat check-in
        });

        // Migrasi data lama -> mapping ke dua kolom baru
        if (Schema::hasColumn('reservations', 'deposit') && Schema::hasColumn('reservations', 'deposit_type')) {
            DB::table('reservations')->where('deposit_type', 'DP')
                ->update(['deposit_room' => DB::raw('COALESCE(deposit,0)')]);

            DB::table('reservations')->where('deposit_type', 'CARD')
                ->update(['deposit_card' => DB::raw('COALESCE(deposit,0)')]);
        }

        // Hapus kolom lama kalau ada
        Schema::table('reservations', function (Blueprint $table) {
            if (Schema::hasColumn('reservations', 'deposit_type')) {
                $table->dropColumn('deposit_type');
            }
            if (Schema::hasColumn('reservations', 'deposit')) {
                $table->dropColumn('deposit');
            }
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->string('deposit_type', 20)->nullable()->after('status');
            $table->unsignedBigInteger('deposit')->default(0)->after('deposit_type');
        });

        // Mapping balik sederhana: utamakan CARD jika ada, selain itu DP
        DB::table('reservations')->where('deposit_card', '>', 0)
            ->update(['deposit_type' => 'CARD', 'deposit' => DB::raw('deposit_card')]);

        DB::table('reservations')->where('deposit_card', '=', 0)->where('deposit_room', '>', 0)
            ->update(['deposit_type' => 'DP', 'deposit' => DB::raw('deposit_room')]);

        Schema::table('reservations', function (Blueprint $table) {
            if (Schema::hasColumn('reservations', 'deposit_room')) {
                $table->dropColumn('deposit_room');
            }
            if (Schema::hasColumn('reservations', 'deposit_card')) {
                $table->dropColumn('deposit_card');
            }
        });
    }
};
