<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('card_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_guest_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_to')->nullable();
            $table->string('door_mask')->nullable(); // atau akses rules
            $table->string('created_by')->nullable();
            $table->timestamps();
            $table->index(['hotel_id', 'valid_to']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('card_assignments');
    }
};
