<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hotels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('tipe')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('no_reg')->nullable();
            $table->string('logo')->nullable();
            $table->string('city')->nullable();       // TAMBAHKAN INI
            $table->string('postcode', 10)->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotels');
    }
};
