<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('income_categories', function (Blueprint $table) {
            $table->id();

            // scope per hotel
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();

            $table->string('name', 150);
            $table->string('description', 255)->nullable();

            $table->timestamps();
            $table->softDeletes();

            // indeks & unik per hotel
            $table->index('hotel_id');
            $table->unique(['hotel_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('income_categories');
    }
};
