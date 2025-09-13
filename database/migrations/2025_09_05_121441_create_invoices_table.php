<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();

            // relasi ke booking — unique = 1 invoice untuk 1 booking (hapus unique jika butuh multi-invoice)
            $table->foreignId('booking_id')->index()
                ->constrained('bookings')->cascadeOnDelete();

            $table->foreignId('tax_setting_id')
                ->nullable()
                ->constrained('tax_settings') // sesuaikan nama tabel setting pajakmu
                ->nullOnDelete();

            $table->string('invoice_no', 50)->nullable();
            $table->string('title', 150)->nullable();    // mis. "PBB"
            $table->dateTime('date');

            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('tax_total')->default(0);
            $table->unsignedBigInteger('total')->default(0);

            $table->string('payment_method', 50)->nullable(); // cash/bank/transfer/card/ewallet
            $table->string('status', 30)->default('issued');  // issued|paid|void
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['hotel_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
