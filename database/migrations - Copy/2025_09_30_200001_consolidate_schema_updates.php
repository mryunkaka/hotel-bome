<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // ========= Add reservation_guest_id ke payments & invoices (tanpa AFTER) =========
        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table) {
                if (! Schema::hasColumn('payments', 'reservation_guest_id')) {
                    $table->foreignId('reservation_guest_id')
                        ->nullable()
                        ->constrained('reservation_guests');
                }
                if (! Schema::hasColumn('payments', 'is_deposit')) {
                    $table->boolean('is_deposit')->default(false);
                }
            });
        }

        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table) {
                if (! Schema::hasColumn('invoices', 'reservation_guest_id')) {
                    $table->foreignId('reservation_guest_id')
                        ->nullable()
                        ->constrained('reservation_guests');
                }
            });
        }

        // ========= Deposit fields di reservations (tanpa AFTER) =========
        if (Schema::hasTable('reservations')) {
            Schema::table('reservations', function (Blueprint $table) {
                if (! Schema::hasColumn('reservations', 'deposit_type')) {
                    $table->enum('deposit_type', ['NONE', 'DP', 'FULL'])->default('NONE');
                }
                if (! Schema::hasColumn('reservations', 'deposit')) {
                    $table->decimal('deposit', 15, 2)->default(0);
                }
                if (! Schema::hasColumn('reservations', 'deposit_room')) {
                    $table->decimal('deposit_room', 15, 2)->default(0);
                }
                if (! Schema::hasColumn('reservations', 'deposit_card')) {
                    $table->decimal('deposit_card', 15, 2)->default(0);
                }
            });
        }

        // ========= Pindah pajak ke reservations (tanpa AFTER) =========
        if (Schema::hasTable('reservations')) {
            Schema::table('reservations', function (Blueprint $table) {
                if (! Schema::hasColumn('reservations', 'tax_rate')) {
                    $table->decimal('tax_rate', 5, 2)->default(0);
                }
                if (! Schema::hasColumn('reservations', 'tax_amount')) {
                    $table->decimal('tax_amount', 15, 2)->default(0);
                }
            });
        }

        if (Schema::hasTable('reservation_guests')) {
            Schema::table('reservation_guests', function (Blueprint $table) {
                if (Schema::hasColumn('reservation_guests', 'tax_rate')) {
                    $table->dropColumn('tax_rate');
                }
                if (Schema::hasColumn('reservation_guests', 'tax_amount')) {
                    $table->dropColumn('tax_amount');
                }
            });
        }

        // ========= Status di rooms (tanpa AFTER) =========
        if (Schema::hasTable('rooms')) {
            Schema::table('rooms', function (Blueprint $table) {
                if (! Schema::hasColumn('rooms', 'status')) {
                    $table->enum('status', ['vacant', 'occupied', 'dirty', 'out_of_order'])->default('vacant');
                }
            });
        }

        // ========= Normalisasi data deposit_type (opsional aman) =========
        if (
            Schema::hasTable('reservations')
            && Schema::hasColumn('reservations', 'deposit_type')
            && Schema::hasColumn('reservations', 'deposit')
            && Schema::hasColumn('reservations', 'deposit_room')
            && Schema::hasColumn('reservations', 'deposit_card')
        ) {
            DB::statement("
                UPDATE reservations
                SET deposit_type = CASE
                    WHEN (COALESCE(deposit_room,0) + COALESCE(deposit_card,0)) = 0 THEN 'NONE'
                    WHEN (COALESCE(deposit_room,0) + COALESCE(deposit_card,0)) >= COALESCE(deposit,0) THEN 'FULL'
                    ELSE 'DP'
                END
            ");
        }

        // ========= Safety net: pastikan kolom-kolom di minibar_receipt_items lengkap =========
        if (Schema::hasTable('minibar_receipt_items')) {
            Schema::table('minibar_receipt_items', function (Blueprint $table) {
                if (! Schema::hasColumn('minibar_receipt_items', 'unit_price')) {
                    $table->decimal('unit_price', 15, 2)->default(0);
                }
                if (! Schema::hasColumn('minibar_receipt_items', 'unit_cost')) {
                    $table->decimal('unit_cost', 15, 2)->default(0);
                }
                if (! Schema::hasColumn('minibar_receipt_items', 'line_total')) {
                    $table->decimal('line_total', 15, 2)->default(0);
                }
                if (! Schema::hasColumn('minibar_receipt_items', 'line_cogs')) {
                    $table->decimal('line_cogs', 15, 2)->default(0);
                }
            });
        }

        // ========= Sinkron kolom Minibar Daily Closings (sesuai model) =========
        if (Schema::hasTable('minibar_daily_closings')) {
            Schema::table('minibar_daily_closings', function (Blueprint $table) {
                if (! Schema::hasColumn('minibar_daily_closings', 'total_profit')) {
                    $table->decimal('total_profit', 15, 2)->default(0);
                }
                if (! Schema::hasColumn('minibar_daily_closings', 'total_restock_cost')) {
                    $table->decimal('total_restock_cost', 15, 2)->default(0);
                }
                if (! Schema::hasColumn('minibar_daily_closings', 'checklist')) {
                    $table->json('checklist')->nullable();
                }
            });
        }

        // ========= Sinkron kolom Minibar Daily Closing Items (sesuai model) =========
        if (Schema::hasTable('minibar_daily_closing_items')) {
            Schema::table('minibar_daily_closing_items', function (Blueprint $table) {
                if (! Schema::hasColumn('minibar_daily_closing_items', 'system_qty')) {
                    $table->integer('system_qty')->default(0);
                }
                if (! Schema::hasColumn('minibar_daily_closing_items', 'variance_qty')) {
                    $table->integer('variance_qty')->default(0);
                }
                if (! Schema::hasColumn('minibar_daily_closing_items', 'revenue')) {
                    $table->decimal('revenue', 15, 2)->default(0);
                }
                if (! Schema::hasColumn('minibar_daily_closing_items', 'cogs')) {
                    $table->decimal('cogs', 15, 2)->default(0);
                }
                if (! Schema::hasColumn('minibar_daily_closing_items', 'profit')) {
                    $table->decimal('profit', 15, 2)->default(0);
                }
                if (! Schema::hasColumn('minibar_daily_closing_items', 'notes')) {
                    $table->text('notes')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        // rollback payments
        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table) {
                if (Schema::hasColumn('payments', 'reservation_guest_id')) {
                    // drop FK lalu kolom
                    try {
                        $table->dropForeign(['reservation_guest_id']);
                    } catch (\Throwable $e) {
                    }
                    $table->dropColumn('reservation_guest_id');
                }
                if (Schema::hasColumn('payments', 'is_deposit')) {
                    $table->dropColumn('is_deposit');
                }
            });
        }

        // rollback invoices
        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table) {
                if (Schema::hasColumn('invoices', 'reservation_guest_id')) {
                    try {
                        $table->dropForeign(['reservation_guest_id']);
                    } catch (\Throwable $e) {
                    }
                    $table->dropColumn('reservation_guest_id');
                }
            });
        }

        // rollback reservations (hapus kolom tambahan)
        if (Schema::hasTable('reservations')) {
            Schema::table('reservations', function (Blueprint $table) {
                foreach (['tax_amount', 'tax_rate', 'deposit_card', 'deposit_room', 'deposit', 'deposit_type'] as $col) {
                    if (Schema::hasColumn('reservations', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        // kembalikan pajak ke reservation_guests (optional)
        if (Schema::hasTable('reservation_guests')) {
            Schema::table('reservation_guests', function (Blueprint $table) {
                if (! Schema::hasColumn('reservation_guests', 'tax_rate')) {
                    $table->decimal('tax_rate', 5, 2)->default(0);
                }
                if (! Schema::hasColumn('reservation_guests', 'tax_amount')) {
                    $table->decimal('tax_amount', 15, 2)->default(0);
                }
            });
        }

        // rollback rooms.status
        if (Schema::hasTable('rooms')) {
            Schema::table('rooms', function (Blueprint $table) {
                if (Schema::hasColumn('rooms', 'status')) {
                    $table->dropColumn('status');
                }
            });
        }

        // rollback safety net minibar_receipt_items
        if (Schema::hasTable('minibar_receipt_items')) {
            Schema::table('minibar_receipt_items', function (Blueprint $table) {
                foreach (['line_cogs', 'line_total', 'unit_cost', 'unit_price'] as $col) {
                    if (Schema::hasColumn('minibar_receipt_items', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        // rollback sinkronisasi daily closings
        if (Schema::hasTable('minibar_daily_closings')) {
            Schema::table('minibar_daily_closings', function (Blueprint $table) {
                foreach (['checklist', 'total_restock_cost', 'total_profit'] as $col) {
                    if (Schema::hasColumn('minibar_daily_closings', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        // rollback sinkronisasi daily closing items
        if (Schema::hasTable('minibar_daily_closing_items')) {
            Schema::table('minibar_daily_closing_items', function (Blueprint $table) {
                foreach (['notes', 'profit', 'cogs', 'revenue', 'variance_qty', 'system_qty'] as $col) {
                    if (Schema::hasColumn('minibar_daily_closing_items', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
