<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 1) Payments → tambahkan reservation_guest_id (opsional)
        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'reservation_guest_id')) {
                $table->foreignId('reservation_guest_id')
                    ->nullable()
                    ->after('reservation_id')
                    ->constrained('reservation_guests')
                    ->nullOnDelete();
                $table->index(['hotel_id', 'reservation_guest_id']);
            }
        });

        // 2) Invoice items → kaitkan ke reservation_guest_id (opsional)
        Schema::table('invoice_items', function (Blueprint $table) {
            if (! Schema::hasColumn('invoice_items', 'reservation_guest_id')) {
                $table->foreignId('reservation_guest_id')
                    ->nullable()
                    ->after('invoice_id')
                    ->constrained('reservation_guests')
                    ->nullOnDelete();
                $table->index('reservation_guest_id');
            }
        });

        // 3) (Opsional tapi berguna) Simpan nomor bill & waktu close di baris guest
        Schema::table('reservation_guests', function (Blueprint $table) {
            if (! Schema::hasColumn('reservation_guests', 'bill_no')) {
                $table->string('bill_no', 50)->nullable()->after('actual_checkout');
            }
            if (! Schema::hasColumn('reservation_guests', 'bill_closed_at')) {
                $table->dateTime('bill_closed_at')->nullable()->after('bill_no');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'reservation_guest_id')) {
                $table->dropConstrainedForeignId('reservation_guest_id');
                $table->dropIndex(['hotel_id', 'reservation_guest_id']);
            }
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            if (Schema::hasColumn('invoice_items', 'reservation_guest_id')) {
                $table->dropConstrainedForeignId('reservation_guest_id');
                $table->dropIndex(['invoice_items_reservation_guest_id_index']);
            }
        });

        Schema::table('reservation_guests', function (Blueprint $table) {
            if (Schema::hasColumn('reservation_guests', 'bill_no')) {
                $table->dropColumn('bill_no');
            }
            if (Schema::hasColumn('reservation_guests', 'bill_closed_at')) {
                $table->dropColumn('bill_closed_at');
            }
        });
    }
};
