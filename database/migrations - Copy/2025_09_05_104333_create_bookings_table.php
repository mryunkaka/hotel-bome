<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
            $table->foreignId('guest_id')->constrained('guests')->cascadeOnDelete();

            $table->dateTime('check_in_at');
            $table->dateTime('check_out_at')->nullable();
            $table->string('status', 30)->default('booked'); // booked|checked_in|checked_out|cancelled
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['hotel_id', 'room_id', 'check_in_at']);
            $table->index('guest_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
