<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('minibar_receipt_items', function (Blueprint $table) {
            if (!Schema::hasColumn('minibar_receipt_items', 'unit_price')) {
                $table->decimal('unit_price', 15, 2)->default(0)->after('quantity');
            }
            if (!Schema::hasColumn('minibar_receipt_items', 'unit_cost')) {
                $table->decimal('unit_cost', 15, 2)->default(0)->after('unit_price');
            }
            if (!Schema::hasColumn('minibar_receipt_items', 'line_total')) {
                $table->decimal('line_total', 15, 2)->default(0)->after('unit_cost');
            }
            if (!Schema::hasColumn('minibar_receipt_items', 'line_cogs')) {
                $table->decimal('line_cogs', 15, 2)->default(0)->after('line_total');
            }
        });
    }

    public function down(): void
    {
        Schema::table('minibar_receipt_items', function (Blueprint $table) {
            if (Schema::hasColumn('minibar_receipt_items', 'line_cogs'))  $table->dropColumn('line_cogs');
            if (Schema::hasColumn('minibar_receipt_items', 'line_total')) $table->dropColumn('line_total');
            if (Schema::hasColumn('minibar_receipt_items', 'unit_cost'))  $table->dropColumn('unit_cost');
            if (Schema::hasColumn('minibar_receipt_items', 'unit_price')) $table->dropColumn('unit_price');
        });
    }
};
