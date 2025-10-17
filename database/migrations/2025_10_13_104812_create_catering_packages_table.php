<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('catering_packages', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('hotel_id')->constrained('hotels')->cascadeOnDelete();

            $table->string('code', 50)->nullable();      // unik per hotel (opsional)
            $table->string('name', 150);                 // "Buffet Silver", "Buffet Gold", dll
            $table->text('description')->nullable();

            $table->unsignedInteger('min_pax')->default(1);
            $table->decimal('price_per_pax', 15, 2)->default(0);   // harga per pax

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['hotel_id', 'code']);
            $table->index(['hotel_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catering_packages');
    }
};
