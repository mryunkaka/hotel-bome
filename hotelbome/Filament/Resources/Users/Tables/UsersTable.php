<?php

namespace App\Filament\Resources\Users\Tables;

use Filament\Tables\Table;
use App\Exports\UsersExport;
use App\Imports\UsersImport;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Maatwebsite\Excel\Facades\Excel;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Facades\Storage;
use Filament\Notifications\Notification;
use Filament\Forms\Components\FileUpload;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('hotel.name')
                    ->searchable(),
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                Action::make('export_excel')
                    ->label('Export Excel')
                    ->icon('heroicon-m-arrow-down-tray')
                    ->action(function () {
                        return Excel::download(new UsersExport(), 'users.xlsx');
                    }),

                Action::make('preview_pdf')
                    ->label('Preview PDF')
                    ->icon('heroicon-m-document-text')
                    ->url(fn() => route('users.preview-pdf'))
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
                            $relative = ltrim((string) $data['file'], '/\\');
                            if (! Storage::disk('local')->exists($relative)) {
                                // fallback bila bentuknya bukan string
                                if ($data['file'] instanceof TemporaryUploadedFile) {
                                    $relative = $data['file']->store('imports', 'local');
                                }
                            }

                            $absolute = Storage::disk('local')->path($relative);
                            $importer = new UsersImport();
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
                                    ->body('Cek heading: name, email, password (opsional).')
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
                ]),
            ]);
    }
}
