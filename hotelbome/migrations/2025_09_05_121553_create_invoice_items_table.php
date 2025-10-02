<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('invoice_id')
                ->constrained('invoices')
                ->cascadeOnDelete();

            // BARIS ITEM SEDERHANA
            $table->string('item_name', 150);        // contoh: Room, Extra Bed, Parkir Mobil
            $table->string('description', 255)->nullable(); // bebas: keterangan tambahan
            $table->unsignedBigInteger('qty')->default(1);
            $table->unsignedBigInteger('unit_price')->default(0);
            $table->unsignedBigInteger('amount')->default(0);

            $table->timestamps();
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
