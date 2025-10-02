<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('reservation_groups', function (Blueprint $table) {
            $table->id();

            // Scope hotel
            $table->foreignId('hotel_id')
                ->constrained()
                ->cascadeOnDelete();

            // Informasi group
            $table->string('name');                        // Group Name
            $table->string('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('handphone', 50)->nullable();
            $table->string('fax', 50)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('remark_ci')->nullable();       // Remark CI
            $table->text('long_remark')->nullable();       // Long Remark

            $table->timestamps();
            $table->softDeletes();

            // Index & Unik
            $table->unique(['hotel_id', 'name']);
            $table->index(['hotel_id', 'city']);
            $table->index(['hotel_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_groups');
    }
};
