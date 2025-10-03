<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('banks', function (Blueprint $table) {
            $table->id();

            // Scope per hotel
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();

            // Identitas rekening
            $table->string('name', 100);                // Nama bank (BCA, BRI, Mandiri, dll)
            $table->string('short_code', 20);           // Kode singkat utk mapping, ex: 'BCA', 'BRI'
            $table->string('branch', 100)->nullable();
            $table->string('account_no', 50);           // Nomor rekening
            $table->string('holder_name', 100)->nullable(); // Nama pemilik rekening (opsional)

            // Info tambahan
            $table->string('address', 255)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('swift_code', 20)->nullable();
            $table->string('currency', 3)->default('IDR');  // ISO 4217

            // Status
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes & Uniques
            $table->index('hotel_id');
            $table->index('email');

            // Unik per hotel:
            $table->unique(['hotel_id', 'account_no']);   // nomor rekening tidak boleh duplikat di hotel yg sama
            $table->unique(['hotel_id', 'short_code']);   // short_code unik per hotel, dipakai untuk account_code (BANK_<SHORTCODE>)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banks');
    }
};
