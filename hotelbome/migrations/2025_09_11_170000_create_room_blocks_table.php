<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('room_blocks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();

            // opsional: tautkan ke reservasi (bisa null jika block manual)
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('start_at');          // biasanya expected_arrival
            $table->timestamp('end_at');            // biasanya expected_departure (12:00)
            $table->string('reason')->nullable();   // alasan block / catatan
            $table->boolean('active')->default(true);

            $table->timestamps();

            // untuk query cepat & deteksi overlap
            $table->index(['hotel_id', 'room_id', 'active']);
            $table->index(['start_at', 'end_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_blocks');
    }
};
