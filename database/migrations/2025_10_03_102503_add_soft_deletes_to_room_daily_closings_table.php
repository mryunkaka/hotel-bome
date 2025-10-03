<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('room_daily_closings', function (Blueprint $table) {
            // letakkan setelah updated_at agar rapi; aman meski urutan tak krusial
            $table->softDeletes()->after('updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('room_daily_closings', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
