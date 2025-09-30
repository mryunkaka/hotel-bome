<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('minibar_receipt_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('receipt_id')->constrained('minibar_receipts')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('minibar_items');
            $table->integer('quantity');
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('unit_cost', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);
            $table->decimal('line_cogs', 15, 2)->default(0);
            $table->timestamps();

            $table->index(['receipt_id']);
            $table->index(['item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minibar_receipt_items');
    }
};
