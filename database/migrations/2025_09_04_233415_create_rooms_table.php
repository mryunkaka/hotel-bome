<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();

            // Scope per hotel
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();

            $table->string('type', 100)->nullable();

            $table->string('room_no', 50);     // RoomNo
            $table->unsignedInteger('floor')->default(1);
            $table->unsignedBigInteger('price')->default(0);

            $table->string('status', 20)->default('VCI');
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['hotel_id']);
            // Unik per hotel: 1 nomor kamar tidak boleh ganda dalam 1 hotel
            $table->unique(['hotel_id', 'room_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
