<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hotel_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('path'); // path foto yang diupload
            $table->string('caption')->nullable(); // opsional, keterangan foto
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_photos');
    }
};
