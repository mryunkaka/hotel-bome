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
            $table->date('closing_date');                        // tanggal yang diclose
            $table->boolean('is_balanced')->default(false);

            // jumlah2 sesuai model kamu
            $table->decimal('total_sales', 15, 2)->default(0);
            $table->decimal('total_cogs', 15, 2)->default(0);
            $table->decimal('total_profit', 15, 2)->default(0);
            $table->decimal('total_restock_cost', 15, 2)->default(0);
            $table->decimal('variance_amount', 15, 2)->default(0);

            $table->json('checklist')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->dateTime('closed_at')->nullable();

            $table->timestamps();

            // mencegah double closing pada tanggal yang sama per hotel
            $table->unique(['hotel_id', 'closing_date']);
            $table->index(['hotel_id', 'closing_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minibar_daily_closings');
    }
};
