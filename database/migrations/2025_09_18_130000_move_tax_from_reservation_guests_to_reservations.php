<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) Tambahkan kolom pajak di RESERVATIONS (global)
        Schema::table('reservations', function (Blueprint $table) {
            if (! Schema::hasColumn('reservations', 'id_tax')) {
                $table->foreignId('id_tax')
                    ->nullable()
                    ->constrained('tax_settings')
                    ->nullOnDelete()
                    ->after('deposit');
                $table->index(['hotel_id', 'id_tax']);
            }
        });

        // 2) Migrasikan data id_tax dari reservation_guests -> reservations (ambil yang non-null)
        if (Schema::hasTable('reservation_guests') && Schema::hasColumn('reservation_guests', 'id_tax')) {
            $pairs = DB::table('reservation_guests')
                ->select('reservation_id', DB::raw('MAX(id_tax) as id_tax'))
                ->whereNotNull('id_tax')
                ->groupBy('reservation_id')
                ->get();

            foreach ($pairs as $p) {
                DB::table('reservations')
                    ->where('id', $p->reservation_id)
                    ->update(['id_tax' => $p->id_tax]);
            }
        }

        // 3) Hapus kolom pajak di RESERVATION_GUESTS (beserta FK)
        Schema::table('reservation_guests', function (Blueprint $table) {
            if (Schema::hasColumn('reservation_guests', 'id_tax')) {
                try {
                    $table->dropForeign(['id_tax']);
                } catch (\Throwable $e) { /* ignore */
                }
                $table->dropColumn('id_tax');
            }
        });
    }

    public function down(): void
    {
        // 1) Tambahkan kembali kolom pajak di RESERVATION_GUESTS
        Schema::table('reservation_guests', function (Blueprint $table) {
            if (! Schema::hasColumn('reservation_guests', 'id_tax')) {
                $table->foreignId('id_tax')
                    ->nullable()
                    ->constrained('tax_settings')
                    ->nullOnDelete()
                    ->after('service');
                $table->index(['hotel_id', 'id_tax']);
            }
        });

        // 2) Salin balik dari reservations ke reservation_guests (set untuk semua guest di reservation tsb)
        if (Schema::hasTable('reservations') && Schema::hasColumn('reservations', 'id_tax')) {
            $pairs = DB::table('reservations')
                ->select('id as reservation_id', 'id_tax')
                ->whereNotNull('id_tax')
                ->get();

            foreach ($pairs as $p) {
                DB::table('reservation_guests')
                    ->where('reservation_id', $p->reservation_id)
                    ->update(['id_tax' => $p->id_tax]);
            }
        }

        // 3) Hapus kolom pajak dari RESERVATIONS
        Schema::table('reservations', function (Blueprint $table) {
            if (Schema::hasColumn('reservations', 'id_tax')) {
                try {
                    $table->dropForeign(['id_tax']);
                } catch (\Throwable $e) { /* ignore */
                }
                $table->dropColumn('id_tax');
            }
        });
    }
};
