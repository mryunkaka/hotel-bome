<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('reservation_guests', function (Blueprint $table) {
            // Jadikan guest_id dan room_id nullable
            $table->unsignedBigInteger('guest_id')->nullable()->change();
            $table->unsignedBigInteger('room_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('reservation_guests', function (Blueprint $table) {
            $table->unsignedBigInteger('guest_id')->nullable(false)->change();
            $table->unsignedBigInteger('room_id')->nullable(false)->change();
        });
    }
};
