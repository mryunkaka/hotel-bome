<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('facility_blocks', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('hotel_id')->constrained('hotels')->cascadeOnDelete();
            $table->foreignId('facility_id')->constrained('facilities')->cascadeOnDelete();

            // opsional: refer ke booking (jika block karena transaksi)
            $table->foreignId('facility_booking_id')->nullable()->constrained('facility_bookings')->nullOnDelete();

            // Opsional: refer ke reservation jika kamu mau menautkan langsung
            $table->foreignId('reservation_id')->nullable()->constrained('reservations')->nullOnDelete();

            $table->dateTime('start_at')->index();
            $table->dateTime('end_at')->index();

            $table->boolean('active')->default(true);
            $table->string('source', 20)->default('booking');  // booking/manual/other
            $table->string('reason', 150)->nullable();         // "Paid booking #123", "Maintenance", dll
            $table->text('notes')->nullable();

            $table->timestamps();

            // Indeks bantu & query overlap (cek di aplikasi)
            $table->index(['hotel_id', 'facility_id', 'active']);
            $table->index(['start_at', 'end_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_blocks');
    }
};
