<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('uid'); // UID RFID (hex string)
            $table->string('serial_number')->nullable(); // label fisik (opsional)
            $table->string('status')->default('available'); // available|in_use|lost|disabled
            $table->foreignId('last_reservation_id')->nullable()->constrained('reservations')->nullOnDelete();
            $table->timestamps();

            $table->unique(['hotel_id', 'uid']); // UID unik per hotel
            $table->index(['hotel_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cards');
    }
};
