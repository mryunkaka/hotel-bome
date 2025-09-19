<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Track pemakaian & refund deposit di payments
        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'deposit_used')) {
                $table->integer('deposit_used')->default(0)->after('amount');
            }
            if (! Schema::hasColumn('payments', 'is_deposit_refund')) {
                $table->boolean('is_deposit_refund')->default(false)->after('deposit_used');
            }
            if (! Schema::hasColumn('payments', 'deposit_refund_note')) {
                $table->string('deposit_refund_note')->nullable()->after('is_deposit_refund');
            }
        });

        // Catat kapan deposit reservation di-clear (jadi 0)
        Schema::table('reservations', function (Blueprint $table) {
            if (! Schema::hasColumn('reservations', 'deposit_cleared_at')) {
                $table->timestamp('deposit_cleared_at')->nullable()->after('deposit');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'deposit_used')) {
                $table->dropColumn('deposit_used');
            }
            if (Schema::hasColumn('payments', 'is_deposit_refund')) {
                $table->dropColumn('is_deposit_refund');
            }
            if (Schema::hasColumn('payments', 'deposit_refund_note')) {
                $table->dropColumn('deposit_refund_note');
            }
        });

        Schema::table('reservations', function (Blueprint $table) {
            if (Schema::hasColumn('reservations', 'deposit_cleared_at')) {
                $table->dropColumn('deposit_cleared_at');
            }
        });
    }
};
