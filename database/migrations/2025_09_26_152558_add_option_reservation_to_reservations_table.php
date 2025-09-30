<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->enum('option_reservation', ['reservation', 'walkin'])
                ->default('reservation')
                ->after('option'); // sesuaikan dengan kolom yang paling dekat
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('option_reservation');
        });
    }
};
