<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('minibar_stock_movements', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('hotel_id')->constrained('hotels');
            $table->foreignId('item_id')->constrained('minibar_items');
            $table->enum('movement_type', ['restock', 'sale', 'adjustment', 'wastage', 'return'])->index();
            $table->integer('quantity');                                 // + untuk masuk, - untuk keluar
            $table->decimal('unit_cost', 15, 2)->nullable()->comment('relevan untuk restock/adjustment/wastage');
            $table->decimal('unit_price', 15, 2)->nullable()->comment('relevan untuk sale');
            $table->foreignId('vendor_id')->nullable()->constrained('minibar_vendors');
            $table->foreignId('receipt_id')->nullable()->constrained('minibar_receipts')->nullOnDelete();
            $table->foreignId('reservation_guest_id')->nullable()->constrained('reservation_guests');
            $table->string('reference_no')->nullable();                   // no invoice vendor / dokumen referensi
            $table->foreignId('performed_by')->constrained('users');
            $table->text('notes')->nullable();
            $table->dateTime('happened_at')->index();                     // kapan kejadian
            $table->timestamps();

            $table->index(['hotel_id', 'item_id', 'happened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minibar_stock_movements');
    }
};
