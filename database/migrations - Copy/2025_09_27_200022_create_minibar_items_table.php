<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('minibar_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('hotel_id')->constrained('hotels');
            $table->string('sku')->nullable();
            $table->string('name');
            $table->string('category')->nullable();       // makanan, minuman, dll
            $table->string('unit')->default('pcs');       // botol, kaleng, dll
            $table->decimal('default_cost_price', 15, 2)->default(0); // patokan beli
            $table->decimal('default_sale_price', 15, 2)->default(0); // patokan jual
            $table->integer('current_stock')->default(0); // cache stok (akan disinkron lewat ledger)
            $table->integer('reorder_level')->default(0)->comment('tetapkan ambang restock');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['hotel_id', 'sku']);
            $table->index(['hotel_id', 'name']);
            $table->index(['hotel_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minibar_items');
    }
};
