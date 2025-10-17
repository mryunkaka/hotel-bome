<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facility_bookings', function (Blueprint $table) {
            // Lepas FK lama jika ada
            if (Schema::hasColumn('facility_bookings', 'reservation_id')) {
                try {
                    $table->dropForeign(['reservation_id']);
                } catch (\Throwable $e) {
                }
                $table->renameColumn('reservation_id', 'guest_id');
            }
        });

        Schema::table('facility_bookings', function (Blueprint $table) {
            if (Schema::hasColumn('facility_bookings', 'guest_id')) {
                // Sesuaikan strategi delete sesuai kebutuhan (nullOnDelete / cascadeOnDelete)
                try {
                    $table->foreign('guest_id')->references('id')->on('guests')->nullOnDelete();
                } catch (\Throwable $e) {
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('facility_bookings', function (Blueprint $table) {
            if (Schema::hasColumn('facility_bookings', 'guest_id')) {
                try {
                    $table->dropForeign(['guest_id']);
                } catch (\Throwable $e) {
                }
                $table->renameColumn('guest_id', 'reservation_id');
            }
        });

        Schema::table('facility_bookings', function (Blueprint $table) {
            if (Schema::hasColumn('facility_bookings', 'reservation_id')) {
                // Kembalikan FK lama jika perlu
                try {
                    $table->foreign('reservation_id')->references('id')->on('reservations')->nullOnDelete();
                } catch (\Throwable $e) {
                }
            }
        });
    }
};
