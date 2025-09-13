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

            // Scope hotel & header reservasi
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->constrained('reservations')->cascadeOnDelete();

            // Tamu & Kamar (kamar opsional saat awal)
            $table->foreignId('guest_id')->constrained('guests')->cascadeOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();

            // Identitas / penanggung per-guest
            $table->string('person', 150)->nullable();              // PIC baris ini (jika berbeda)
            $table->string('pov', 150)->nullable();
            $table->string('breakfast', 150)->nullable();
            $table->unsignedSmallInteger('jumlah_orang')->default(1); // total pax untuk baris ini
            $table->unsignedSmallInteger('male')->default(0);
            $table->unsignedSmallInteger('female')->default(0);
            $table->unsignedSmallInteger('children')->default(0);

            // Pembebanan & tarif kamar per-guest
            $table->string('charge_to', 30)->nullable();            // GUEST / COMPANY / AGENCY / ...
            $table->unsignedBigInteger('room_rate')->default(0);
            // Jadwal PER-GUEST
            // expected_* = rencana (editable dari "Information Guest & Room Assignment")
            // actual_*   = realisasi (saat check-in/out)
            $table->dateTime('expected_checkin')->nullable();
            $table->dateTime('expected_checkout')->nullable();
            $table->dateTime('actual_checkin')->nullable();
            $table->dateTime('actual_checkout')->nullable();

            // Lain-lain
            $table->text('note')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Index & keunikan
            $table->index(['hotel_id', 'reservation_id']);
            $table->index(['hotel_id', 'room_id']);
            $table->index(['hotel_id', 'guest_id']);
            $table->index(['hotel_id', 'expected_checkin']);
            $table->index(['hotel_id', 'expected_checkout']);

            // Satu baris unik per (reservation, guest, room).
            // Catatan: karena room_id nullable, MySQL mengizinkan beberapa baris dgn room_id NULL.
            // Ini oke kalau kamu memang ingin mendukung multi-baris sementara tanpa kamar.
            $table->unique(['reservation_id', 'guest_id', 'room_id'], 'resguest_res_guest_room_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_guests');
    }
};
