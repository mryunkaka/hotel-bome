<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facility_bookings', function (Blueprint $table) {
            // simpan DP (50% total) — pakai precision umum untuk nilai rupiah
            if (! Schema::hasColumn('facility_bookings', 'dp')) {
                $table->decimal('dp', 12, 2)->default(0)->after('total_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('facility_bookings', function (Blueprint $table) {
            if (Schema::hasColumn('facility_bookings', 'dp')) {
                $table->dropColumn('dp');
            }
        });
    }
};
