<div class="space-y-2">
    <table class="min-w-full text-sm">
        <thead>
            <tr class="border-b">
                <th class="text-left py-2">Item</th>
                <th class="text-right py-2">Qty</th>
                <th class="text-right py-2">Unit Price</th>
                <th class="text-right py-2">Line Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $row)
                <tr class="border-b last:border-0">
                    <td class="py-2">{{ $row->item?->name ?? '-' }}</td>
                    <td class="py-2 text-right">{{ (int) $row->quantity }}</td>
                    <td class="py-2 text-right">{{ number_format((float) $row->unit_price, 0, ',', '.') }}</td>
                    <td class="py-2 text-right">{{ number_format((float) $row->line_total, 0, ',', '.') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="py-4 text-center text-gray-500">No items.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
