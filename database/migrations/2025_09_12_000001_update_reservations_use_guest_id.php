<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            // Tambah kolom guest_id
            if (! Schema::hasColumn('reservations', 'guest_id')) {
                $table->foreignId('guest_id')
                    ->nullable()
                    ->constrained('guests')
                    ->nullOnDelete()
                    ->after('group_id');
            }

            // Hapus kolom lama
            if (Schema::hasColumn('reservations', 'reserved_title')) {
                $table->dropColumn('reserved_title');
            }
            if (Schema::hasColumn('reservations', 'reserved_by')) {
                $table->dropColumn('reserved_by');
            }
            if (Schema::hasColumn('reservations', 'reserved_number')) {
                $table->dropColumn('reserved_number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            // Kembalikan kolom lama
            if (! Schema::hasColumn('reservations', 'reserved_title')) {
                $table->string('reserved_title', 10)->nullable();
            }
            if (! Schema::hasColumn('reservations', 'reserved_by')) {
                $table->string('reserved_by')->nullable();
            }
            if (! Schema::hasColumn('reservations', 'reserved_number')) {
                $table->string('reserved_number')->nullable();
            }

            // Hapus guest_id
            if (Schema::hasColumn('reservations', 'guest_id')) {
                $table->dropConstrainedForeignId('guest_id');
            }
        });
    }
};
