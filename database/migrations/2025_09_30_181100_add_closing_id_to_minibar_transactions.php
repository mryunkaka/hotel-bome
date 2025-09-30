<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // receipts
        Schema::table('minibar_receipts', function (Blueprint $table) {
            if (! Schema::hasColumn('minibar_receipts', 'closing_id')) {
                $table->foreignId('closing_id')
                    ->nullable()
                    ->after('status')
                    ->constrained('minibar_daily_closings')
                    ->nullOnDelete()
                    ->cascadeOnUpdate();

                // index bantu
                if (Schema::hasColumn('minibar_receipts', 'hotel_id')) {
                    $table->index(['hotel_id', 'closing_id']);
                }
            }
        });

        // stock movements (opsional)
        Schema::table('minibar_stock_movements', function (Blueprint $table) {
            if (! Schema::hasColumn('minibar_stock_movements', 'closing_id')) {
                $table->foreignId('closing_id')
                    ->nullable()
                    ->after('performed_by')
                    ->constrained('minibar_daily_closings')
                    ->nullOnDelete()
                    ->cascadeOnUpdate();

                if (Schema::hasColumn('minibar_stock_movements', 'hotel_id')) {
                    $table->index(['hotel_id', 'closing_id']);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('minibar_receipts', function (Blueprint $table) {
            if (Schema::hasColumn('minibar_receipts', 'closing_id')) {
                $table->dropConstrainedForeignId('closing_id');
                // index gabungan bisa diabaikan jika belum ada
            }
        });

        Schema::table('minibar_stock_movements', function (Blueprint $table) {
            if (Schema::hasColumn('minibar_stock_movements', 'closing_id')) {
                $table->dropConstrainedForeignId('closing_id');
            }
        });
    }
};
