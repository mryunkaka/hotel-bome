<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('income_items', function (Blueprint $table) {
            $table->id();

            // Scope per hotel
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();

            // Relasi kategori pemasukan
            $table->foreignId('income_category_id')
                ->constrained('income_categories')
                ->cascadeOnDelete();

            // Data transaksi
            $table->unsignedBigInteger('amount')->default(0);
            $table->string('description', 255)->nullable();
            $table->dateTime('date'); // tanggal + waktu

            $table->timestamps();
            $table->softDeletes();

            // Index bantu
            $table->index(['hotel_id', 'income_category_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('income_items');
    }
};
