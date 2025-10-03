<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('room_daily_closings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('hotel_id')->constrained('hotels');

            // Tanggal dan status closing
            $table->date('closing_date'); // tanggal yang diclose
            $table->boolean('is_balanced')->default(false)->comment('ceklist: pas/tidak');

            // Total ringkasan keuangan
            $table->decimal('total_room_revenue', 15, 2)->default(0)->comment('Pendapatan kamar (netto sebelum pajak)');
            $table->decimal('total_tax', 15, 2)->default(0)->comment('Total pajak kamar (jika dipisah)');
            $table->decimal('total_discount', 15, 2)->default(0)->comment('Total diskon kamar');
            $table->decimal('total_deposit', 15, 2)->default(0)->comment('Total deposit yang diterima hari itu');
            $table->decimal('total_refund', 15, 2)->default(0)->comment('Total refund tamu');
            $table->decimal('total_payment', 15, 2)->default(0)->comment('Total pembayaran kamar (tunai + non-tunai)');
            $table->decimal('variance_amount', 15, 2)->default(0)->comment('Selisih kas dengan hasil sistem jika ada');

            // Checklist & catatan tambahan
            $table->json('checklist')->nullable()->comment('opsional: array centang/temuan');
            $table->text('notes')->nullable();

            // Waktu proses closing
            $table->dateTime('closing_start_at')->nullable();
            $table->dateTime('closing_end_at')->nullable();

            // Kas fisik aktual (untuk cash drawer)
            $table->decimal('cash_actual', 15, 2)->default(0);

            // Status & user
            $table->boolean('is_locked')->default(false);
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->dateTime('closed_at')->nullable();

            $table->timestamps();

            // Unik per hotel & tanggal
            $table->unique(['hotel_id', 'closing_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_daily_closings');
    }
};
