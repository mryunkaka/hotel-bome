<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bank_ledgers', function (Blueprint $table) {
            $table->id();

            // Scope per hotel (mengikuti pola sebelumnya)
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();

            // Relasi ke bank
            $table->foreignId('bank_id')->constrained('banks')->cascadeOnDelete();

            // Field transaksi
            $table->unsignedBigInteger('deposit')->default(0);
            $table->unsignedBigInteger('withdraw')->default(0);
            $table->date('date');
            $table->string('description', 255)->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Index bantu
            $table->index(['hotel_id', 'bank_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_ledgers');
    }
};
