<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TaxSettingSeeder extends Seeder
{
    public function run(): void
    {
        $cols = Schema::getColumnListing('tax_settings');
        $hasPercentage = in_array('percentage', $cols, true);
        $hasRate       = in_array('rate', $cols, true);
        $hasValue      = in_array('value', $cols, true);

        $hotelIds = DB::table('hotels')->pluck('id');

        foreach ($hotelIds as $hotelId) {
            // Default Tax (aktif)
            $default = [
                'hotel_id'   => $hotelId,
                'name'       => 'Default Tax',
                'is_active'  => true,
                'percent'    => 11,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if ($hasPercentage) $default['percentage'] = 10.0;
            elseif ($hasRate)   $default['rate']       = 10.0;
            elseif ($hasValue)  $default['value']      = 10.0;

            DB::table('tax_settings')->updateOrInsert(
                ['hotel_id' => $hotelId, 'name' => 'Default Tax'],
                $default
            );

            // No Tax (non-aktif)
            $noTax = [
                'hotel_id'   => $hotelId,
                'name'       => 'No Tax',
                'is_active'  => false,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if ($hasPercentage) $noTax['percentage'] = 0.0;
            elseif ($hasRate)   $noTax['rate']       = 0.0;
            elseif ($hasValue)  $noTax['value']      = 0.0;

            DB::table('tax_settings')->updateOrInsert(
                ['hotel_id' => $hotelId, 'name' => 'No Tax'],
                $noTax
            );
        }
    }
}
