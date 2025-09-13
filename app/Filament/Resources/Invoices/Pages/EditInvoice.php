<?php

namespace App\Filament\Resources\Invoices\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Resources\Pages\EditRecord;
use App\Filament\Resources\Invoices\InvoiceResource;

class EditInvoice extends EditRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')
                ->label('Cetak Invoice')
                ->icon('heroicon-m-printer')
                ->url(fn() => route('invoices.preview-pdf', [
                    'invoice' => $this->record->getKey(),
                    'o'       => 'portrait',
                ]))
                ->openUrlInNewTab(),
            ...parent::getHeaderActions(),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        $this->record->recalculateTotals(); // rate diambil dari tax_setting_id
    }
}
