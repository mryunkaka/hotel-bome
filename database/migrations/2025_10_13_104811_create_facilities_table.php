<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('facilities', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('hotel_id')->constrained('hotels')->cascadeOnDelete();

            // Kode unik per hotel (opsional), dan nama fasilitas yang bisa diubah-ubah (Ballroom A, Meeting Room B, Avanza, dll)
            $table->string('code', 50)->nullable();
            $table->string('name', 150);

            // Tipe fleksibel: venue (ballroom/meeting), vehicle (mobil), equipment, service, other
            $table->enum('type', ['venue', 'vehicle', 'equipment', 'service', 'other'])->default('venue')->index();

            // Spesifik venue
            $table->unsignedInteger('capacity')->nullable();           // kapasitas pax (jika venue)
            $table->boolean('is_allow_catering')->default(true);       // boleh include catering?

            // Harga dasar & mode (bisa override di booking)
            // per_hour = tarif per jam, per_day = tarif per hari, fixed = satuan flat (misal paket 1x acara)
            $table->enum('base_pricing_mode', ['per_hour', 'per_day', 'fixed'])->default('per_hour');
            $table->decimal('base_price', 15, 2)->default(0);

            // Status aktif/nonaktif di katalog
            $table->boolean('is_active')->default(true);

            $table->text('description')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['hotel_id', 'code']);
            $table->index(['hotel_id', 'name']);
            $table->index(['hotel_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facilities');
    }
};
