<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('card_scans', function (Blueprint $t) {
            $t->id();
            $t->string('uid_raw', 64)->nullable();
            $t->string('uid_norm', 64)->index();
            $t->string('source', 64)->nullable();
            $t->json('meta')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('card_scans');
    }
};
