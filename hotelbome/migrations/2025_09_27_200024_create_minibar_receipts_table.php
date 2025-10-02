<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('minibar_receipts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('hotel_id')->constrained('hotels');
            $table->string('receipt_no');                               // nomor struk unik per hotel
            $table->foreignId('reservation_guest_id')->nullable()->constrained('reservation_guests');
            $table->decimal('subtotal_amount', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('total_cogs', 15, 2)->default(0)->comment('HPP untuk item yang terjual');
            $table->enum('status', ['unpaid', 'paid', 'void'])->default('paid');
            $table->foreignId('created_by')->constrained('users');
            $table->dateTime('issued_at');
            $table->foreignId('closing_id')->nullable()->constrained('minibar_daily_closings')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['hotel_id', 'receipt_no']);
            $table->index(['hotel_id', 'issued_at']);
            $table->index(['reservation_guest_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minibar_receipts');
    }
};
