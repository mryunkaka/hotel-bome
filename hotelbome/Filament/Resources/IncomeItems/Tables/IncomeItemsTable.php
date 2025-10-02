<?php

namespace App\Filament\Resources\IncomeItems\Tables;

use Filament\Tables\Table;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use App\Exports\IncomeItemsExport;
use App\Imports\IncomeItemsImport;
use Maatwebsite\Excel\Facades\Excel;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Facades\Storage;
use Filament\Notifications\Notification;
use Filament\Forms\Components\FileUpload;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Actions\ForceDeleteBulkAction;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class IncomeItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('hotel.name')
                    ->searchable(),
                TextColumn::make('incomeCategory.name')
                    ->searchable(),
                TextColumn::make('amount')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('description')
                    ->searchable(),
                TextColumn::make('date')
                    ->dateTime()
                    ->sortable(),
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
                Action::make('export_excel')
                    ->label('Export Excel')
                    ->icon('heroicon-m-arrow-down-tray')
                    ->action(fn() => Excel::download(new IncomeItemsExport(), 'income-items.xlsx')),

                Action::make('preview_pdf')
                    ->label('Preview PDF')
                    ->icon('heroicon-m-document-text')
                    ->url(fn() => route('income-items.preview-pdf', ['o' => 'portrait']))
                    ->openUrlInNewTab(),

                Action::make('import_excel')
                    ->label('Import Excel')
                    ->icon('heroicon-m-arrow-up-tray')
                    ->form([
                        FileUpload::make('file')
                            ->label('File .xlsx')
                            ->directory('imports')
                            ->disk('local')
                            ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                            ->required()
                            ->moveFiles(),
                    ])
                    ->action(function (array $data) {
                        try {
                            $relative  = ltrim((string) $data['file'], '/\\');
                            if (! Storage::disk('local')->exists($relative) && $data['file'] instanceof TemporaryUploadedFile) {
                                $relative = $data['file']->store('imports', 'local');
                            }
                            $absolute  = Storage::disk('local')->path($relative);

                            $importer = new IncomeItemsImport();
                            Excel::import($importer, $absolute);

                            Storage::disk('local')->delete($relative);

                            $sum = $importer->resultSummary();
                            Notification::make()
                                ->title('Import selesai')
                                ->body("Created: {$sum['created']} | Updated: {$sum['updated']} | Skipped: {$sum['skipped']}")
                                ->success()
                                ->send();

                            if (!empty($sum['errors'])) {
                                Notification::make()
                                    ->title('Ada baris di-skip')
                                    ->body('Cek heading: category, amount, description, date.')
                                    ->warning()
                                    ->send();
                            }
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Import gagal')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
