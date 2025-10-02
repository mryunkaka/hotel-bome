<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_guests', function (Blueprint $table) {
            $table->id();

            // Scope & Header
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->constrained('reservations')->cascadeOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained('guests')->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();

            // Perkiraan & Realisasi
            $table->dateTime('expected_checkin')->nullable();
            $table->dateTime('expected_checkout')->nullable();
            $table->dateTime('actual_checkin')->nullable();
            $table->dateTime('actual_checkout')->nullable();

            // Biaya per-guest/per-room (tanpa pajak karena pajak sudah di reservations)
            $table->integer('service')->default(0);
            $table->integer('charge')->default(0); // ditambahkan setelah 'service'
            $table->decimal('room_rate', 15, 2)->default(0);

            // Informasi penagihan per-guest
            $table->string('bill_no', 50)->nullable();
            $table->dateTime('bill_closed_at')->nullable();

            // Audit
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Index
            $table->index(['hotel_id', 'expected_checkout']);
            $table->index(['hotel_id', 'reservation_id']);

            // Unik: satu baris per (reservation, guest, room)
            // Catatan: room_id nullable → MySQL mengizinkan beberapa baris dengan room_id NULL
            $table->unique(['reservation_id', 'guest_id', 'room_id'], 'resguest_res_guest_room_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_guests');
    }
};
