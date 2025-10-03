<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // ===== PAYMENTS =====
        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table) {
                if (!Schema::hasColumn('payments', 'is_posted')) {
                    $table->boolean('is_posted')->default(false)->after('notes');
                }
                if (!Schema::hasColumn('payments', 'posted_at')) {
                    $table->timestamp('posted_at')->nullable()->after('is_posted');
                }
            });
        }

        // ===== RESERVATIONS =====
        if (Schema::hasTable('reservations')) {
            Schema::table('reservations', function (Blueprint $table) {
                if (!Schema::hasColumn('reservations', 'is_posted')) {
                    $table->boolean('is_posted')->default(false)->after('status');
                }
                if (!Schema::hasColumn('reservations', 'posted_at')) {
                    $table->timestamp('posted_at')->nullable()->after('is_posted');
                }
            });
        }

        // ===== MINIBAR RECEIPTS =====
        if (Schema::hasTable('minibar_receipts')) {
            Schema::table('minibar_receipts', function (Blueprint $table) {
                if (!Schema::hasColumn('minibar_receipts', 'is_posted')) {
                    $table->boolean('is_posted')->default(false)->after('status');
                }
                if (!Schema::hasColumn('minibar_receipts', 'posted_at')) {
                    $table->timestamp('posted_at')->nullable()->after('is_posted');
                }
            });
        }

        // ===== ROOM DAILY CLOSINGS =====
        if (Schema::hasTable('room_daily_closings')) {
            Schema::table('room_daily_closings', function (Blueprint $table) {
                if (!Schema::hasColumn('room_daily_closings', 'is_posted')) {
                    $table->boolean('is_posted')->default(false)->after('is_locked');
                }
                if (!Schema::hasColumn('room_daily_closings', 'posted_at')) {
                    $table->timestamp('posted_at')->nullable()->after('is_posted');
                }
            });
        }

        // ===== MINIBAR DAILY CLOSINGS =====
        if (Schema::hasTable('minibar_daily_closings')) {
            Schema::table('minibar_daily_closings', function (Blueprint $table) {
                if (!Schema::hasColumn('minibar_daily_closings', 'is_posted')) {
                    $table->boolean('is_posted')->default(false)->after('is_locked');
                }
                if (!Schema::hasColumn('minibar_daily_closings', 'posted_at')) {
                    $table->timestamp('posted_at')->nullable()->after('is_posted');
                }
            });
        }
    }

    public function down(): void
    {
        // Rollback aman, hapus kolom jika ada
        foreach (['payments', 'reservations', 'minibar_receipts', 'room_daily_closings', 'minibar_daily_closings'] as $table) {
            if (Schema::hasTable($table)) {
                Schema::table($table, function (Blueprint $table) {
                    if (Schema::hasColumn($table->getTable(), 'is_posted')) {
                        $table->dropColumn('is_posted');
                    }
                    if (Schema::hasColumn($table->getTable(), 'posted_at')) {
                        $table->dropColumn('posted_at');
                    }
                });
            }
        }
    }
};
