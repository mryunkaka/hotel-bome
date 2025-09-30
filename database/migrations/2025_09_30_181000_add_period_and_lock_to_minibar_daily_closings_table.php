<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('minibar_daily_closings', function (Blueprint $table) {
            if (! Schema::hasColumn('minibar_daily_closings', 'closing_start_at')) {
                $table->timestamp('closing_start_at')->nullable()->after('closing_date');
            }
            if (! Schema::hasColumn('minibar_daily_closings', 'closing_end_at')) {
                $table->timestamp('closing_end_at')->nullable()->after('closing_start_at');
            }
            if (! Schema::hasColumn('minibar_daily_closings', 'cash_actual')) {
                $table->unsignedBigInteger('cash_actual')->nullable()->after('total_profit');
            }
            if (! Schema::hasColumn('minibar_daily_closings', 'is_locked')) {
                $table->boolean('is_locked')->default(false)->after('is_balanced')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('minibar_daily_closings', function (Blueprint $table) {
            if (Schema::hasColumn('minibar_daily_closings', 'is_locked')) {
                $table->dropColumn('is_locked');
            }
            if (Schema::hasColumn('minibar_daily_closings', 'cash_actual')) {
                $table->dropColumn('cash_actual');
            }
            if (Schema::hasColumn('minibar_daily_closings', 'closing_end_at')) {
                $table->dropColumn('closing_end_at');
            }
            if (Schema::hasColumn('minibar_daily_closings', 'closing_start_at')) {
                $table->dropColumn('closing_start_at');
            }
        });
    }
};
