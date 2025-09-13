<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();

            // Scope hotel
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();

            // Nilai transaksi
            $table->unsignedBigInteger('debit')->default(0);
            $table->unsignedBigInteger('credit')->default(0);

            // Info transaksi
            $table->date('date');
            $table->string('method', 50)->nullable();     // cash, transfer, edc, dll.
            $table->string('description')->nullable();    // keterangan singkat

            $table->timestamps();
            $table->softDeletes();

            // Index yang umum dipakai
            $table->index('date');
            $table->index(['hotel_id', 'date']);
            $table->index(['hotel_id', 'method']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_accounts');
    }
};
