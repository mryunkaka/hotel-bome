<?php

namespace App\Filament\Resources\Hotels\Tables;

use Filament\Tables\Table;
use Filament\Actions\Action;
use App\Exports\HotelsExport;
use App\Imports\HotelsImport;
use Filament\Actions\EditAction;
use Maatwebsite\Excel\Facades\Excel;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Facades\Storage;
use Filament\Notifications\Notification;
use Filament\Forms\Components\FileUpload;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class HotelsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('address')
                    ->searchable(),
                TextColumn::make('phone')
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
                // Export Excel
                Action::make('export_excel')
                    ->label('Export Excel')
                    ->icon('heroicon-m-arrow-down-tray')
                    ->action(function () {
                        return Excel::download(new HotelsExport(), 'hotels.xlsx');
                    }),

                // Preview PDF
                Action::make('preview_pdf')
                    ->label('Preview PDF')
                    ->icon('heroicon-m-document-text')
                    ->url(fn() => route('hotels.preview-pdf', ['o' => 'landscape']))
                    ->openUrlInNewTab(),

                // Import Excel (upload → import → hapus temp)
                Action::make('import_excel')
                    ->label('Import Excel')
                    ->icon('heroicon-m-arrow-up-tray')
                    ->form([
                        FileUpload::make('file')
                            ->label('File .xlsx')
                            ->directory('imports')   // simpan di storage/app/imports
                            ->disk('local')          // disk 'local' = storage/app
                            ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                            ->required()
                        // ->moveFiles(),           // <<< WAJIB agar pindah dari temp Livewire
                    ])
                    ->action(function (array $data) {

                        try {
                            // Pastikan folder ada
                            Storage::disk('local')->makeDirectory('imports');

                            // Normalisasi nilai dari FileUpload
                            $value = $data['file'] ?? null;
                            $storedPath = null; // relatif terhadap disk 'local', mis. "imports/abc.xlsx"

                            if ($value instanceof TemporaryUploadedFile) {
                                // Belum dipindah: simpan sekarang ke disk 'local'
                                $storedPath = $value->store('imports', 'local');
                            } elseif (is_string($value) && $value !== '') {
                                // Sudah string path ("imports/xxx.xlsx") dari ->moveFiles()
                                $storedPath = ltrim($value, '/\\'); // buang slash awal jika ada
                            } elseif (is_array($value)) {
                                // Kadang balik array; ambil elemen string pertamanya
                                $first = array_values($value)[0] ?? null;
                                if ($first instanceof TemporaryUploadedFile) {
                                    $storedPath = $first->store('imports', 'local');
                                } elseif (is_string($first) && $first !== '') {
                                    $storedPath = ltrim($first, '/\\');
                                }
                            }

                            if (! $storedPath) {
                                throw new \RuntimeException('Tidak ada file terdeteksi dari komponen upload.');
                            }

                            // Verifikasi dengan Storage API di disk 'local'
                            if (! Storage::disk('local')->exists($storedPath)) {
                                // Coba fallback: mungkin hanya nama file tanpa folder
                                if (Storage::disk('local')->exists('imports/' . basename($storedPath))) {
                                    $storedPath = 'imports/' . basename($storedPath);
                                } else {
                                    throw new \RuntimeException("File belum tersedia di disk 'local': {$storedPath}");
                                }
                            }

                            // Absolute path utk Excel
                            $absolutePath = Storage::disk('local')->path($storedPath);

                            $importer = new HotelsImport();
                            Excel::import($importer, $absolutePath);

                            // Hapus file setelah import (opsional)
                            Storage::disk('local')->delete($storedPath);

                            $sum = $importer->resultSummary();
                            Notification::make()
                                ->title('Import selesai')
                                ->body("Created: {$sum['created']} | Updated: {$sum['updated']} | Skipped: {$sum['skipped']}")
                                ->success()
                                ->send();

                            if (!empty($sum['errors'])) {
                                Notification::make()
                                    ->title('Ada baris di-skip')
                                    ->body('Cek heading & format kolom: name / email / no_reg.')
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
