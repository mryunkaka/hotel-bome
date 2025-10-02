<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // USERS ---------------------------------------------------------------
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            // Scope user to a hotel (nullable to allow global super admin)
            $table->foreignId('hotel_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('email'); // NOT unique alone — uniqueness is composite with hotel_id
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();

            // If you implement single-session, uncomment this line:
            // $table->string('session_id', 100)->nullable()->index();

            // Composite uniqueness: the same email can exist in different hotels, but not twice within the same hotel.
            $table->unique(['email', 'hotel_id'], 'users_email_hotel_unique');
        });

        // PASSWORD RESET TOKENS ----------------------------------------------
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        // SESSIONS ------------------------------------------------------------
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
