<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('minibar_daily_closings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('hotel_id')->constrained('hotels');
            $table->date('closing_date');                                // tanggal yang diclose
            $table->boolean('is_balanced')->default(false)->comment('ceklist: pas/tidak');
            $table->decimal('total_sales', 15, 2)->default(0);
            $table->decimal('total_cogs', 15, 2)->default(0);
            $table->decimal('total_profit', 15, 2)->default(0);
            $table->decimal('total_restock_cost', 15, 2)->default(0)->comment('biaya restock hari itu (bukan expense, hanya informasi)');
            $table->decimal('variance_amount', 15, 2)->default(0)->comment('nilai selisih jika ada');
            $table->json('checklist')->nullable()->comment('opsional: array centang/temuan');
            $table->text('notes')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['hotel_id', 'closing_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minibar_daily_closings');
    }
};
