<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bank_ledgers', function (Blueprint $table) {
            $table->id();

            // Scope per hotel
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();

            // Rekening bank
            $table->foreignId('bank_id')->constrained('banks')->cascadeOnDelete();

            // Nilai transaksi (gunakan decimal untuk konsistensi akuntansi)
            $table->decimal('deposit', 15, 2)->default(0);   // uang masuk
            $table->decimal('withdraw', 15, 2)->default(0);  // uang keluar

            // Tanggal & keterangan
            $table->date('date');
            $table->string('description', 255)->nullable();

            // Informasi metode & sumber (tanpa FK ke modul, longgar)
            $table->string('method', 50)->nullable()->comment('cash, transfer, edc, e-wallet, dll');
            $table->string('ledger_type', 50)->nullable()->comment('room, minibar, resto, adjustment, dll');
            $table->unsignedBigInteger('reference_id')->nullable()->comment('ID sumber transaksi');
            $table->string('reference_table', 100)->nullable()->comment('Nama tabel sumber transaksi');

            // Status posting (selaras dengan ledger_accounts)
            $table->boolean('is_posted')->default(false);
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Index bantu
            $table->index(['hotel_id', 'bank_id', 'date']);
            $table->index(['hotel_id', 'ledger_type']);
            $table->index(['hotel_id', 'reference_table', 'reference_id']);
            $table->index(['hotel_id', 'method', 'date']);

            // Idempoten: mencegah duplikasi mutasi bank
            $table->unique([
                'hotel_id',
                'bank_id',
                'date',
                'deposit',
                'withdraw',
                'method',
                'ledger_type',
                'reference_table',
                'reference_id',
            ], 'uniq_bank_ledger_signature');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_ledgers');
    }
};
