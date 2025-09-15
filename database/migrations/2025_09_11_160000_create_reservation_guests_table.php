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

            /**
             * Scope & Header
             */
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->constrained('reservations')->cascadeOnDelete();

            /**
             * Relasi Tamu & Kamar (kamar opsional di awal)
             */
            $table->foreignId('guest_id')->constrained('guests')->cascadeOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();

            /**
             * Identitas / Penanggung per-guest
             */
            $table->string('person', 150)->nullable();      // PIC baris ini (jika berbeda)
            $table->string('pov', 150)->nullable();         // purpose of visit (opsional)
            $table->string('breakfast', 150)->nullable();
            $table->unsignedInteger('extra_bed')->nullable();

            /**
             * Komposisi Pax
             */
            $table->unsignedSmallInteger('jumlah_orang')->default(1); // total pax untuk baris ini
            $table->unsignedSmallInteger('male')->default(0);
            $table->unsignedSmallInteger('female')->default(0);
            $table->unsignedSmallInteger('children')->default(0);

            /**
             * Pembebanan & Tarif
             * - charge_to: GUEST / COMPANY / AGENCY / ...
             * - discount_percent: persen diskon 0–100 (contoh 10.00 = 10%)
             *   *umumnya diskon diterapkan sebelum pajak*
             */
            $table->string('charge_to', 30)->nullable();
            $table->unsignedBigInteger('room_rate')->default(0);
            $table->unsignedBigInteger('service')->nullable(); // biaya tambahan/service per kamar (opsional)
            $table->decimal('discount_percent', 5, 2)->default(0); // 0–100, contoh 12.50 = 12.5%

            // Pajak (opsional): referensi ke tax_settings
            $table->foreignId('id_tax')
                ->nullable()
                ->constrained('tax_settings')
                ->nullOnDelete();

            /**
             * Jadwal PER-GUEST
             * expected_* = rencana (editable dari "Information Guest & Room Assignment")
             * actual_*   = realisasi (saat check-in/out)
             */
            $table->dateTime('expected_checkin')->nullable();
            $table->dateTime('expected_checkout')->nullable();
            $table->dateTime('actual_checkin')->nullable();
            $table->dateTime('actual_checkout')->nullable();

            /**
             * Lain-lain
             */
            $table->text('note')->nullable();

            $table->timestamps();
            $table->softDeletes();

            /**
             * Index & Keunikan
             */
            $table->index(['hotel_id', 'reservation_id']);
            $table->index(['hotel_id', 'room_id']);
            $table->index(['hotel_id', 'guest_id']);
            $table->index(['hotel_id', 'expected_checkin']);
            $table->index(['hotel_id', 'expected_checkout']);
            $table->index(['hotel_id', 'id_tax']); // filter pajak per-hotel lebih efisien

            // Satu baris unik per (reservation, guest, room).
            // Catatan: karena room_id nullable, MySQL mengizinkan beberapa baris dgn room_id NULL.
            $table->unique(['reservation_id', 'guest_id', 'room_id'], 'resguest_res_guest_room_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_guests');
    }
};
