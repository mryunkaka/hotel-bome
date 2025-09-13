<?php

namespace App\Imports;

use App\Models\Booking;
use App\Models\Room;
use App\Models\Guest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class BookingsImport implements ToModel, WithHeadingRow
{
    public function model(array $row)
    {
        $hid = (int) (session('active_hotel_id') ?? 0);

        // --- ROOM: ID atau label
        $roomId = null;
        if (!empty($row['room'])) {
            $raw = trim((string) $row['room']);

            // kolom yang umum dipakai untuk label kamar
            $labelColsAll = ['room_no', 'number', 'name', 'no', 'code'];

            // hanya kolom yang BENAR-BENAR ada di tabel rooms
            $labelCols = array_values(array_filter($labelColsAll, fn($c) => Schema::hasColumn('rooms', $c)));

            // 1) cari berdasarkan label (pakai OR)
            $q = Room::query()
                ->when(method_exists(Room::class, 'bootSoftDeletes'), fn($qq) => $qq->withTrashed())
                ->where('hotel_id', $hid)
                ->where(function ($qq) use ($raw, $labelCols) {
                    foreach ($labelCols as $col) {
                        $qq->orWhere($col, $raw);
                    }
                });

            $roomId = $q->value('id');

            // 2) fallback: kalau belum ketemu & input numeric → coba sebagai ID
            if (!$roomId && is_numeric($raw)) {
                $roomId = Room::query()
                    ->when(method_exists(Room::class, 'bootSoftDeletes'), fn($qq) => $qq->withTrashed())
                    ->where('hotel_id', $hid)
                    ->where('id', (int) $raw)
                    ->value('id');
            }
        }

        // ✅ FIX: jika tidak ketemu, lempar error dengan konkatenasi aman
        if (!$roomId) {
            // (A) STOP DENGAN PESAN RAPI
            $what = isset($row['room']) ? (string) $row['room'] : '';
            throw ValidationException::withMessages([
                'room' => [
                    'Room "' . $what . '" tidak ditemukan untuk hotel aktif. ' .
                        'Isi dengan room_no/number/name/no/code yang valid, atau ID kamar yang benar.',
                ],
            ]);

            // (B) ALTERNATIF: SKIP BARIS (kalau lebih suka lanjutkan import)
            // return null;
        }

        // --- GUEST: name/email/phone (opsional; sesuaikan jika wajib)
        $guestId = null;
        if (!empty($row['guest'])) {
            $v = trim((string) $row['guest']);
            $guestId = Guest::query()
                ->when(method_exists(Guest::class, 'bootSoftDeletes'), fn($qq) => $qq->withTrashed())
                ->where('hotel_id', $hid)
                ->where(function ($qq) use ($v) {
                    foreach (['name', 'email', 'phone'] as $col) {
                        if (Schema::hasColumn('guests', $col)) {
                            $qq->orWhere($col, $v);
                        }
                    }
                })
                ->value('id');
        }

        return new Booking([
            'hotel_id'     => $hid,
            'room_id'      => $roomId,
            'guest_id'     => $guestId,
            'check_in_at'  => self::parseExcelDateTime($row['check_in_at'] ?? null)?->toDateTimeString(),
            'check_out_at' => self::parseExcelDateTime($row['check_out_at'] ?? null)?->toDateTimeString(),
            'status'       => $row['status'] ?? null,
            'notes'        => $row['notes'] ?? null,
        ]);
    }

    private static function parseExcelDateTime(mixed $v): ?Carbon
    {
        if ($v === null || $v === '') return null;
        if ($v instanceof \DateTimeInterface) return Carbon::instance($v);

        if (is_numeric($v)) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $v));
            } catch (\Throwable $e) {
            }
        }

        if (is_string($v)) {
            $s = ltrim(trim($v), "'");
            $s = str_replace(['\\', '.'], ['/', '/'], $s);
            $formats = [
                'Y-m-d H:i:s',
                'Y-m-d H:i',
                'Y-m-d',
                'd/m/Y H:i:s',
                'd/m/Y H:i',
                'd/m/Y',
                'm/d/Y H:i:s',
                'm/d/Y H:i',
                'm/d/Y',
                'd-m-Y H:i:s',
                'd-m-Y',
                'm-d-Y H:i:s',
                'm-d-Y',
                'Y/m/d H:i:s',
                'Y/m/d',
            ];
            foreach ($formats as $fmt) {
                try {
                    return Carbon::createFromFormat($fmt, $s);
                } catch (\Throwable $e) {
                }
            }
            try {
                return Carbon::parse($s);
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }
}
