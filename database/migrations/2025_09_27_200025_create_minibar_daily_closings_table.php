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
            $table->integer('opening_qty')->default(0);                   // stok awal (hasil snapshot)
            $table->integer('restocked_qty')->default(0);                 // masuk selama hari itu
            $table->integer('sold_qty')->default(0);                      // terjual (dari struk)
            $table->integer('wastage_qty')->default(0);                   // rusak/kedaluwarsa
            $table->integer('adjustment_qty')->default(0);                // koreksi manual (+/-)
            $table->integer('closing_qty')->default(0);                   // fisik akhir (input)
            $table->integer('system_qty')->default(0)->comment('stok akhir menurut sistem (perhitungan)');
            $table->integer('variance_qty')->default(0)->comment('closing_qty - system_qty');
            $table->decimal('revenue', 15, 2)->default(0);                // nilai penjualan item
            $table->decimal('cogs', 15, 2)->default(0);                   // HPP item terjual
            $table->decimal('profit', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['daily_closing_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minibar_daily_closing_items');
    }
};
