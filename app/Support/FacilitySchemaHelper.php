<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class FacilitySchemaHelper
{
    /** @return array{mode:string,values:array<int,string>} */
    protected static function inspect(string $table, string $column): array
    {
        // Ambil definisi kolom dari INFORMATION_SCHEMA (aman untuk binding)
        $row = DB::table('information_schema.columns')
            ->selectRaw('LOWER(COLUMN_TYPE) as col_type')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->first();

        if (!$row || empty($row->col_type)) {
            return ['mode' => 'fallback', 'values' => []];
        }

        $type = $row->col_type;

        // enum('A','B',...)
        if (str_starts_with($type, 'enum(')) {
            preg_match_all("/'([^']+)'/", $type, $m);
            $values = $m[1] ?? [];
            return ['mode' => 'enum', 'values' => $values];
        }

        // char(1)
        if (preg_match('/char\(\s*1\s*\)/', $type)) {
            return ['mode' => 'char1', 'values' => []];
        }

        // varchar(...) / lainnya
        return ['mode' => 'varchar', 'values' => []];
    }

    public static function optionsForType(): array
    {
        $info = self::inspect('facilities', 'type');

        $labels = [
            'R' => 'Room / Hall',
            'V' => 'Vehicle',
            'E' => 'Equipment',
            'S' => 'Service',
            'O' => 'Other',
            'venue'     => 'Room / Hall', // sesuai konstanta di model kamu
            'vehicle'   => 'Vehicle',
            'equipment' => 'Equipment',
            'service'   => 'Service',
            'other'     => 'Other',
            'room'      => 'Room / Hall', // fallback aman
        ];

        if ($info['mode'] === 'enum') {
            return collect($info['values'])
                ->mapWithKeys(fn($v) => [$v => $labels[$v] ?? ucfirst(str_replace('_', ' ', $v))])
                ->all();
        }

        if ($info['mode'] === 'char1') {
            return [
                'R' => $labels['R'],
                'V' => $labels['V'],
                'E' => $labels['E'],
                'S' => $labels['S'],
                'O' => $labels['O'],
            ];
        }

        // varchar / fallback panjang
        return [
            // prioritaskan konstanta di model kamu
            'venue'     => $labels['venue'],
            'vehicle'   => $labels['vehicle'],
            'equipment' => $labels['equipment'],
            'service'   => $labels['service'],
            'other'     => $labels['other'],
        ];
    }

    public static function optionsForPricingMode(): array
    {
        $info = self::inspect('facilities', 'base_pricing_mode');

        $labels = [
            'H' => 'Per Hour',
            'D' => 'Per Day',
            'F' => 'Fixed',
            'per_hour' => 'Per Hour',
            'per_day'  => 'Per Day',
            'fixed'    => 'Fixed',
        ];

        if ($info['mode'] === 'enum') {
            return collect($info['values'])
                ->mapWithKeys(fn($v) => [$v => $labels[$v] ?? ucfirst(str_replace('_', ' ', $v))])
                ->all();
        }

        if ($info['mode'] === 'char1') {
            return [
                'H' => $labels['H'],
                'D' => $labels['D'],
                'F' => $labels['F'],
            ];
        }

        // varchar / fallback panjang
        return [
            'per_hour' => $labels['per_hour'],
            'per_day'  => $labels['per_day'],
            'fixed'    => $labels['fixed'],
        ];
    }
}
