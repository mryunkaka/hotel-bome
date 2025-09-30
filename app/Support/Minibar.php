<?php

namespace App\Support;

use App\Models\MinibarItem;

class Minibar
{
    public static function paymentStatusFromMethod(?string $method): string
    {
        return $method === 'charge_to_room' ? 'unpaid' : 'paid';
    }

    /** @param array<array{item_id:int,quantity:float,unit_price:float}> $items */
    public static function computeTotals(array $items): array
    {
        $subtotal  = 0.0;
        $totalCogs = 0.0;

        $itemCosts = MinibarItem::query()
            ->whereIn('id', collect($items)->pluck('item_id')->filter()->all())
            ->pluck('default_cost_price', 'id');

        foreach ($items as $row) {
            $qty   = (float)($row['quantity'] ?? 0);
            $price = (float)($row['unit_price'] ?? 0);
            $cost  = (float)($itemCosts[$row['item_id']] ?? 0);

            $subtotal  += $qty * $price;
            $totalCogs += $qty * $cost;
        }

        return [
            'subtotal_amount' => $subtotal,
            'discount_amount' => 0.0,
            'tax_amount'      => 0.0,
            'total_amount'    => $subtotal, // + tax - discount bila ada
            'total_cogs'      => $totalCogs,
        ];
    }
}
