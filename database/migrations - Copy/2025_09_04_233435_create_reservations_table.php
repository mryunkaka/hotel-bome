<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();

            // Scope hotel (wajib)
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();

            // Relasi opsional
            $table->foreignId('group_id')
                ->nullable()
                ->constrained('reservation_groups')
                ->nullOnDelete();

            $table->foreignId('guest_id')
                ->nullable()
                ->constrained('guests')
                ->nullOnDelete();

            // Identitas reservasi
            $table->string('reservation_no', 30)
                ->nullable()
                ->unique(); // contoh: HOTEL-RESV250900001

            $table->string('option', 20)->nullable();
            $table->string('method', 20)->nullable()->index();
            $table->string('status', 20)->default('CONFIRM');

            // Perkiraan jadwal global (default untuk detail)
            $table->dateTime('expected_arrival')->nullable();
            $table->dateTime('expected_departure')->nullable();

            // Realisasi global
            $table->dateTime('checkin_date')->nullable();
            $table->dateTime('checkout_date')->nullable();

            // Deposit
            $table->string('deposit_type', 20)->nullable(); // DP/FOC/NONE
            $table->unsignedBigInteger('deposit')->default(0);

            // Identitas pemesan (bukan tamu utama)
            $table->string('reserved_title', 10)->nullable(); // MR/MRS/MS, dll.
            $table->string('reserved_by')->nullable();        // nama/instansi
            $table->string('reserved_number')->nullable();    // no. telp/hp
            $table->string('reserved_by_type', 10)->default('GUEST')->index(); // GUEST/GROUP/COMPANY/AGENCY/...
            $table->dateTime('entry_date')->nullable();

            // Lain-lain
            $table->unsignedTinyInteger('num_guests')->default(1);
            $table->string('card_uid')->nullable();

            // Audit
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Index tambahan
            $table->index(['hotel_id', 'expected_departure']);
            $table->index(['hotel_id', 'expected_arrival']);
            $table->index(['hotel_id', 'group_id']);
            $table->index(['hotel_id', 'status']);
            $table->index(['hotel_id', 'card_uid']);
            $table->index(['hotel_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
