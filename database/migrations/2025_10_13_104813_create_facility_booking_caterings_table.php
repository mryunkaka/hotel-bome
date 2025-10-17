<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('facility_booking_caterings', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('hotel_id')->constrained('hotels')->cascadeOnDelete();

            $table->foreignId('facility_booking_id')->constrained('facility_bookings')->cascadeOnDelete();
            $table->foreignId('catering_package_id')->constrained('catering_packages')->cascadeOnDelete();

            // Snapshot harga saat itu (hindari berubah jika master paket diubah)
            $table->unsignedInteger('pax')->default(0);
            $table->decimal('price_per_pax', 15, 2)->default(0);
            $table->decimal('subtotal_amount', 15, 2)->default(0);

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['hotel_id', 'facility_booking_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_booking_caterings');
    }
};
