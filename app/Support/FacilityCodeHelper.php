<?php

namespace App\Support;

use Illuminate\Support\Str;
use App\Models\Hotel;

class FacilityCodeHelper
{
    /**
     * Generate facility code per hotel.
     *
     * Contoh hasil:
     *   HTL-BOM-A42  → Hotel Bome
     *   HTL-SUN-K17  → Sunbay Guest House
     *   HTL-GAL-X08  → Grand Galaxy Resort
     */
    public static function generate(int $hotelId): string
    {
        $hotel = Hotel::find($hotelId);
        $prefix = 'HTL';

        if ($hotel && $hotel->name) {
            // Pecah nama jadi array kata
            $words = preg_split('/\\s+/', trim($hotel->name));

            // Daftar kata umum yang akan diabaikan
            $ignore = [
                'hotel',
                'guest',
                'house',
                'guesthouse',
                'guest-house',
                'villa',
                'inn',
                'resort',
                'lodge',
                'homestay',
                'motel',
                'cottage',
                'pondok',
                'kost',
                'penginapan',
                'the'
            ];

            // Filter kata non-umum
            $filtered = collect($words)
                ->filter(fn($w) => !in_array(strtolower($w), $ignore))
                ->values()
                ->all();

            // Ambil kata pertama setelah filter
            $targetWord = $filtered[0] ?? 'GEN';

            // Ambil 3 huruf pertama kata tersebut
            $slug = strtoupper(Str::substr(Str::slug($targetWord), 0, 3));
        } else {
            $slug = 'GEN';
        }

        // Huruf acak A–Z
        $randLetter = chr(rand(65, 90));

        // Dua digit angka acak
        $randNumber = str_pad((string) rand(1, 99), 2, '0', STR_PAD_LEFT);

        return "{$prefix}-{$slug}-{$randLetter}{$randNumber}";
    }
}
