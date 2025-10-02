<?php

namespace App\Filament\Resources\MinibarReceipts\Pages;

use App\Filament\Resources\MinibarReceipts\MinibarReceiptResource;

use App\Models\MinibarItem;
use App\Support\Minibar as MinibarHelper;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;

class EditMinibarReceipt extends EditRecord
{
    protected static string $resource = MinibarReceiptResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Status dari method (jika method di form masih dipakai)
        $data['status'] = MinibarHelper::paymentStatusFromMethod($data['method'] ?? 'cash');

        // Hitung ulang totals dari relationship items (data['items'] bisa kosong jika Filament hanya sync relasi)
        $items = $data['items'] ?? $this->record->items()->get(['item_id', 'quantity', 'unit_price'])->toArray();
        $totals = MinibarHelper::computeTotals($items);
        $data   = array_merge($data, $totals);

        // Bersihkan field non-kolom
        unset($data['notes'], $data['method']);

        return $data;
    }

    protected function handleRecordUpdate(\Illuminate\Database\Eloquent\Model $record, array $data): \Illuminate\Database\Eloquent\Model
    {
        return DB::transaction(function () use ($record, $data) {
            // Update header
            $record->update($data);

            // Re-sync items kalau form mengirim items array (Filament Repeater relationship akan urus CRUD).
            // Jika kamu butuh kontrol stok saat edit (tambah/kurang), tambahkan logika penyesuaian stok di sini:
            // - Hitung delta setiap item lama vs baru, lalu sesuaikan MinibarItem::current_stock.

            // Recalculate again from persisted items (single source of truth)
            $items  = $record->items()->get(['item_id', 'quantity', 'unit_price'])->toArray();
            $totals = MinibarHelper::computeTotals($items);
            $record->fill($totals)->save();

            return $record;
        });
    }
}
