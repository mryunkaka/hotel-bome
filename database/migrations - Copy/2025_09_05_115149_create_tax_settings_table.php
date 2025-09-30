<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tax_settings', function (Blueprint $table) {
            $table->id();

            // scope per hotel
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();

            $table->string('name', 100);
            // nilai persen: simpan 0–100 (mis. 10.00 = 10%)
            $table->decimal('percent', 5, 2)->default(0);

            // status aktif / tidak
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            // unik per hotel
            $table->unique(['hotel_id', 'name']);
            $table->index(['hotel_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_settings');
    }
};
