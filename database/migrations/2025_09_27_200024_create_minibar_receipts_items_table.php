<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Jika tabel sudah ada, hentikan supaya tidak FAIL saat migrate ulang
        if (Schema::hasTable('minibar_receipt_items')) {
            return;
        }

        Schema::create('minibar_receipt_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('receipt_id')->constrained('minibar_receipts')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('item_id')->constrained('minibar_items')->restrictOnDelete()->cascadeOnUpdate();

            $table->integer('quantity');
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('unit_cost', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);
            $table->decimal('line_cogs', 15, 2)->default(0); // quantity * unit_cost

            $table->timestamps();
            $table->index(['receipt_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minibar_receipt_items');
    }
};
