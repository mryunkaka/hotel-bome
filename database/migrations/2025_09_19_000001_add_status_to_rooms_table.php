<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->string('status', 10)->default('VCI')->after('price')->index(); // TAMBAH
            $table->timestamp('status_changed_at')->nullable()->after('status');   // TAMBAH
        });

        // Optional backfill sederhana: OCC untuk kamar yg sedang ditempati, selain itu VC
        // (silakan sesuaikan kalau ada kebijakan lain)
        DB::statement("
            UPDATE rooms r
            LEFT JOIN (
                SELECT room_id
                FROM reservation_guests
                WHERE actual_checkin IS NOT NULL AND actual_checkout IS NULL
                GROUP BY room_id
            ) occ ON occ.room_id = r.id
            SET r.status = CASE WHEN occ.room_id IS NULL THEN 'VC' ELSE 'OCC' END,
                r.status_changed_at = NOW()
        ");
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn(['status', 'status_changed_at']); // TAMBAH
        });
    }
};
