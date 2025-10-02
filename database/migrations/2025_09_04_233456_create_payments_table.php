<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            // Relations
            $table->foreignId('reservation_id')->constrained('reservations')->cascadeOnDelete();
            $table->foreignId('hotel_id')->constrained('hotels')->cascadeOnDelete();
            // relasi langsung ke reservation_guest (opsional)
            $table->foreignId('reservation_guest_id')->nullable()->constrained('reservation_guests')->nullOnDelete();

            // Nilai & deposit tracking
            $table->unsignedInteger('amount');             // rupiah tanpa desimal
            $table->integer('deposit_used')->default(0);    // total deposit yang dipakai pada transaksi ini
            $table->boolean('is_deposit_refund')->default(false);
            $table->string('deposit_refund_note')->nullable();

            // Meta pembayaran
            $table->string('method')->nullable();          // cash|card|transfer|others
            $table->dateTime('payment_date')->nullable();
            $table->string('reference_no', 100)->nullable();            // nomor transaksi
            $table->text('notes')->nullable();

            // Audit
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Indexes
            $table->index(['hotel_id', 'payment_date']);
            $table->index(['hotel_id', 'reservation_guest_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
