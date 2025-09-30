<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('minibar_daily_closing_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('daily_closing_id')->constrained('minibar_daily_closings')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('minibar_items');
            $table->integer('opening_qty')->default(0);     // snapshot awal
            $table->integer('restocked_qty')->default(0);
            $table->integer('sold_qty')->default(0);
            $table->integer('wastage_qty')->default(0);
            $table->integer('adjustment_qty')->default(0);
            $table->integer('closing_qty')->default(0);     // snapshot akhir
            $table->decimal('sales_amount', 15, 2)->default(0);
            $table->decimal('cogs_amount', 15, 2)->default(0);
            /* === TAMBAHAN agar cocok dgn model MinibarDailyClosingItem === */
            $table->integer('system_qty')->default(0);        // stok seharusnya (hasil perhitungan)
            $table->integer('variance_qty')->default(0);      // selisih (system vs closing)
            $table->decimal('revenue', 15, 2)->default(0);    // alias pendapatan baris
            $table->decimal('cogs', 15, 2)->default(0);       // alias HPP baris
            $table->decimal('profit', 15, 2)->default(0);     // revenue - cogs (per item)
            $table->text('notes')->nullable();
            /* === AKHIR TAMBAHAN === */
            $table->timestamps();
            $table->index(['daily_closing_id']);
            $table->index(['item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minibar_daily_closing_items');
    }
};
