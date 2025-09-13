<?php

namespace App\Imports;

use App\Models\Room;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Row;

class RoomsImport implements OnEachRow, WithHeadingRow
{
    public int $created = 0;
    public int $updated = 0;
    public int $skipped = 0;

    public function onRow(Row $row): void
    {
        $r   = $row->toArray(); // key sudah lowercase & snakecase
        $hid = (int) (session('active_hotel_id') ?? 0);

        $roomNo = trim((string)($r['room_no'] ?? ''));
        if ($roomNo === '') {
            $this->skipped++;
            return;
        }

        $payload = [
            'type'      => self::toStr($r['type'] ?? null),
            'floor'     => self::toInt($r['floor'] ?? null),
            'price'     => self::toFloat($r['price'] ?? null),

            'geyser'    => self::toBool($r['geyser'] ?? null),
            'ac'        => self::toBool($r['ac'] ?? null),
            'balcony'   => self::toBool($r['balcony'] ?? null),
            'bathtub'   => self::toBool($r['bathtub'] ?? null),
            'hicomode'  => self::toBool($r['hicomode'] ?? null),
            'locker'    => self::toBool($r['locker'] ?? null),
            'freeze'    => self::toBool($r['freeze'] ?? null),
            'internet'  => self::toBool($r['internet'] ?? null),
            'intercom'  => self::toBool($r['intercom'] ?? null),
            'tv'        => self::toBool($r['tv'] ?? null),
            'wardrobe'  => self::toBool($r['wardrobe'] ?? null),
        ];

        $model = Room::query()
            ->where('hotel_id', $hid)
            ->where('room_no', $roomNo)
            ->first();

        if ($model) {
            $model->fill($payload)->save();
            $this->updated++;
        } else {
            Room::create([
                'hotel_id' => $hid,
                'room_no'  => $roomNo,
            ] + $payload);
            $this->created++;
        }
    }

    private static function toStr($v): ?string
    {
        $v = is_string($v) ? trim($v) : $v;
        return ($v === '' || $v === null) ? null : (string) $v;
    }

    private static function toInt($v): ?int
    {
        if ($v === null || $v === '') return null;
        if (is_numeric($v)) return (int)$v;
        $n = preg_replace('/[^\d\-]/', '', (string)$v);
        return $n === '' ? null : (int)$n;
    }

    private static function toFloat($v): ?float
    {
        if ($v === null || $v === '') return null;
        if (is_numeric($v)) return (float)$v;
        $s = str_replace([',', ' '], ['', ''], (string)$v);
        $s = str_replace(['Rp', 'IDR', '$'], '', $s);
        return is_numeric($s) ? (float)$s : null;
    }

    private static function toBool($v): bool
    {
        if (is_bool($v)) return $v;
        if (is_numeric($v)) return ((int)$v) === 1;

        $s = strtolower(trim((string)$v));
        $truthy = ['1', 'true', 'yes', 'y', 'ya', 'on', '✓', 'ok', 'iya'];
        $falsy  = ['0', 'false', 'no', 'n', 'tidak', 'off', '✗', 'x', ''];

        if (in_array($s, $truthy, true)) return true;
        if (in_array($s, $falsy, true))  return false;

        // fallback
        return (bool)$v;
    }
}
