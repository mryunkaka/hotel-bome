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

            // Tipe / sumber entri (room, minibar, resto, laundry, adjustment, dsb)
            $table->string('ledger_type', 50)->index()->comment('Sumber entri, mis. minibar, room, restaurant, dll');

            // Referensi polymorphic ke tabel sumber (tanpa FK agar longgar)
            $table->unsignedBigInteger('reference_id')->nullable()->comment('ID sumber transaksi (daily closing, receipt, dll)');
            $table->string('reference_table', 100)->nullable()->comment('Nama tabel sumber transaksi');

            // Akun & metode pembayaran
            $table->string('account_code', 50)->nullable()->comment('Kode akun untuk integrasi COA');
            $table->string('method', 50)->nullable()->comment('cash, transfer, edc, e-wallet, dll');

            // Nilai transaksi
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);

            // Info transaksi
            $table->date('date')->index();
            $table->string('description', 255)->nullable();

            // Posting status
            $table->boolean('is_posted')->default(false);
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Index kombinasi penting
            $table->index(['hotel_id', 'ledger_type']);
            $table->index(['hotel_id', 'date']);
            $table->index(['hotel_id', 'account_code']);

            // Index bantu untuk audit/jejak sumber & filter method
            $table->index(['hotel_id', 'reference_table', 'reference_id']);
            $table->index(['hotel_id', 'method', 'date']);

            // Idempoten: mencegah double-posting entri yang sama
            $table->unique([
                'hotel_id',
                'ledger_type',
                'reference_table',
                'reference_id',
                'account_code',
                'method',
                'date',
                'debit',
                'credit',
            ], 'uniq_ledger_signature');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_accounts');
    }
};
