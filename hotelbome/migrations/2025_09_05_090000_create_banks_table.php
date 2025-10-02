<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('banks', function (Blueprint $table) {
            $table->id();

            // Scope per hotel
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();

            // Fields
            $table->string('name', 100);
            $table->string('branch', 100)->nullable();
            $table->string('account_no', 50);
            $table->string('address', 255)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email', 150)->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('hotel_id');
            $table->index('email');

            // Satu account_no unik per hotel (boleh sama di hotel lain)
            $table->unique(['hotel_id', 'account_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banks');
    }
};
