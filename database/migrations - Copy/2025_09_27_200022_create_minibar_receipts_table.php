<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('minibar_receipts', function (Blueprint $table) {
            $table->bigIncrements('id');

            // FK ke hotels dan reservation_guests (pastikan tabel tsb sudah dibuat lebih dulu)
            $table->foreignId('hotel_id')->constrained('hotels');
            $table->string('receipt_no');

            $table->foreignId('reservation_guest_id')
                ->nullable()
                ->constrained('reservation_guests');

            // urutan nominal — TANPA ->after()
            $table->decimal('subtotal_amount', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('total_cogs', 15, 2)->default(0); // dipakai di model

            $table->enum('status', ['draft', 'issued', 'paid', 'void'])
                ->default('draft')
                ->index();

            // user terkait
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->dateTime('issued_at')->nullable()->index();
            $table->text('notes')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users');

            $table->timestamps();
            $table->softDeletes();

            // index/unique
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
