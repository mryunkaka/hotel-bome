<?php

namespace App\Filament\Resources\AccountLedgers\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Filament\Actions\Action;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\AccountLedgersExport;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Imports\AccountLedgersImport;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;

class AccountLedgersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                // Tampilkan nama hotel (aman karena sudah terfilter by session)
                TextColumn::make('hotel.name')
                    ->label('Hotel')
                    ->toggleable()
                    ->searchable(),

                TextColumn::make('debit')
                    ->numeric(2)
                    ->sortable(),

                TextColumn::make('credit')
                    ->numeric(2)
                    ->sortable(),

                TextColumn::make('date')
                    ->date()
                    ->sortable(),

                TextColumn::make('method')
                    ->searchable(),

                TextColumn::make('description')
                    ->searchable(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                Action::make('exportExcel')
                    ->label('Export Excel')
                    ->icon('heroicon-m-arrow-down-tray')
                    ->action(function () { // <— type-hint dihapus
                        return \Maatwebsite\Excel\Facades\Excel::download(
                            new \App\Exports\AccountLedgersExport,
                            'account-ledgers.xlsx'
                        );
                    }),

                Action::make('previewPdf')
                    ->label('Cetak PDF')
                    ->icon('heroicon-m-document-text')
                    ->url(route('account-ledgers.preview-pdf'))
                    ->openUrlInNewTab(),

                Action::make('importExcel')
                    ->label('Import Excel')
                    ->icon('heroicon-m-arrow-up-tray')
                    ->form(fn() => [
                        FileUpload::make('file')
                            ->label('File Excel')
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'text/csv',
                                'application/vnd.ms-excel',
                            ])
                            ->required()
                            ->storeFiles(false),
                    ])
                    ->action(function (array $data) {
                        \Maatwebsite\Excel\Facades\Excel::import(new \App\Imports\AccountLedgersImport, $data['file']);
                        \Filament\Notifications\Notification::make()->title('Import selesai')->success()->send();
                    }),
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
