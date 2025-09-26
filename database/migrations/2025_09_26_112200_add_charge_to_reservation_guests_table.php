<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jalankan migrasi.
     */
    public function up(): void
    {
        Schema::table('reservation_guests', function (Blueprint $table) {
            // Tambahkan kolom charge
            $table->integer('charge')
                ->default(0)
                ->after('service'); // posisinya setelah kolom yang relevan
        });
    }

    /**
     * Rollback migrasi.
     */
    public function down(): void
    {
        Schema::table('reservation_guests', function (Blueprint $table) {
            $table->dropColumn('charge');
        });
    }
};
