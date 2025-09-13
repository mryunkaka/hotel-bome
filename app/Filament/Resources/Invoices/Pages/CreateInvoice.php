<?php

namespace App\Filament\Resources\Invoices\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use App\Filament\Resources\Invoices\InvoiceResource;
use Filament\Actions\Action as NotifAction;

class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function afterCreate(): void
    {
        $this->record->recalculateTotals(); // rate diambil dari tax_setting_id
    }

    protected function getCreatedNotification(): ?Notification
    {
        $record = $this->getRecord(); // invoice yang baru dibuat

        return Notification::make()
            ->title('Invoice created')
            ->success()
            ->actions([
                NotifAction::make('print')
                    ->label('Cetak Invoice')
                    ->button()
                    ->url(route('invoices.preview-pdf', [
                        'invoice' => $record->getKey(),
                        'o'       => 'portrait',
                    ]))
                    ->openUrlInNewTab(),
                NotifAction::make('download')
                    ->label('Download PDF')
                    ->button()
                    ->url(route('invoices.preview-pdf', [
                        'invoice' => $record->getKey(),
                        'o'       => 'portrait',
                        'dl'      => 1,
                    ])),
            ]);
    }
}
