<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();

            $table->string('name', 150);
            $table->string('salutation', 10)->nullable()->index();
            $table->string('guest_type', 20)->nullable();         // dinaikkan ke 20
            $table->string('id_type', 20)->nullable();

            $table->string('birth_place', 50)->nullable();
            $table->string('issued_place', 100)->nullable();      // ✅ perbaikan (bukan date)
            $table->date('birth_date')->nullable();
            $table->date('issued_date')->nullable();

            $table->string('email', 150)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('city', 50)->nullable();
            $table->string('nationality', 50)->nullable();
            $table->string('profession', 50)->nullable();
            $table->string('address', 255)->nullable();

            $table->string('id_card', 100)->nullable();
            $table->string('id_card_file', 255)->nullable();

            $table->string('father', 150)->nullable();
            $table->string('mother', 150)->nullable();
            $table->string('spouse', 150)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['hotel_id', 'name']);
            $table->index('email');

            // ✅ Unik yang ramah SoftDeletes
            $table->unique(['hotel_id', 'id_card', 'deleted_at'], 'guests_hotel_idcard_unique');

            // (opsional) constraint unik per hotel untuk email/phone
            // $table->unique(['hotel_id', 'email', 'deleted_at'], 'guests_hotel_email_unique');
            // $table->unique(['hotel_id', 'phone', 'deleted_at'], 'guests_hotel_phone_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guests');
    }
};
