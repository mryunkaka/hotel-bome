<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('facility_bookings', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('hotel_id')->constrained('hotels')->cascadeOnDelete();

            // Koneksi ke katalog fasilitas
            $table->foreignId('facility_id')->constrained('facilities')->cascadeOnDelete();

            // Relasi opsional ke Reservasi (bisa dikaitkan ke group atau reservation tunggal; pilih salah satu atau keduanya sesuai alur)
            $table->foreignId('reservation_id')->nullable()->constrained('reservations')->nullOnDelete();
            $table->foreignId('group_id')->nullable()->constrained('reservation_groups')->nullOnDelete();

            // Informasi jadwal pemakaian
            $table->dateTime('start_at')->index();
            $table->dateTime('end_at')->index();

            // Metadata acara / keterangan
            $table->string('title', 150)->nullable();       // "Seminar XYZ", "Rapat Bulanan", dll
            $table->text('notes')->nullable();

            // Pricing (boleh override dari facilities)
            $table->enum('pricing_mode', ['per_hour', 'per_day', 'fixed'])->default('per_hour');
            $table->decimal('unit_price', 15, 2)->default(0);    // tarif dasar per unit sesuai mode
            $table->decimal('quantity', 10, 2)->default(1);      // jam/hari jika per_hour/per_day; 1 jika fixed
            $table->decimal('subtotal_amount', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);

            // Catering flag & angka ringkas (detailnya di facility_booking_caterings)
            $table->boolean('include_catering')->default(false);
            $table->unsignedInteger('catering_total_pax')->default(0);
            $table->decimal('catering_total_amount', 15, 2)->default(0);

            // Status booking (alur bebas, tapi tipikal: DRAFT -> CONFIRM -> PAID -> COMPLETED/CANCELLED)
            $table->enum('status', ['DRAFT', 'CONFIRM', 'PAID', 'COMPLETED', 'CANCELLED'])->default('DRAFT')->index();

            // Penanda logic (misal: sudah dibuat block atau belum)
            $table->boolean('is_blocked')->default(false); // ketika PAID/CONFIRM, app akan buat baris di facility_blocks lalu set true

            // Auditor
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Indeks bantu untuk deteksi bentrok via query
            $table->index(['hotel_id', 'facility_id', 'start_at']);
            $table->index(['hotel_id', 'facility_id', 'end_at']);
            $table->index(['hotel_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_bookings');
    }
};
