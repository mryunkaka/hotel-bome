<?php

namespace App\Filament\Resources\Rooms\Tables;

use Filament\Tables\Table;
use App\Exports\RoomsExport;
use App\Imports\RoomsImport;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Maatwebsite\Excel\Facades\Excel;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Facades\Storage;
use Filament\Notifications\Notification;
use Filament\Forms\Components\FileUpload;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Actions\ForceDeleteBulkAction;

class RoomsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('hotel.name')
                    ->searchable(),
                TextColumn::make('type')
                    ->searchable(),
                TextColumn::make('room_no')
                    ->searchable(),
                TextColumn::make('floor')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('price')
                    ->money()
                    ->sortable(),
                IconColumn::make('geyser')
                    ->boolean(),
                IconColumn::make('ac')
                    ->boolean(),
                IconColumn::make('balcony')
                    ->boolean(),
                IconColumn::make('bathtub')
                    ->boolean(),
                IconColumn::make('hicomode')
                    ->boolean(),
                IconColumn::make('locker')
                    ->boolean(),
                IconColumn::make('freeze')
                    ->boolean(),
                IconColumn::make('internet')
                    ->boolean(),
                IconColumn::make('intercom')
                    ->boolean(),
                IconColumn::make('tv')
                    ->boolean(),
                IconColumn::make('wardrobe')
                    ->boolean(),
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
                // Export Excel
                Action::make('exportExcel')
                    ->label('Export Excel')
                    ->icon('heroicon-m-arrow-down-tray')
                    ->action(function () {
                        return Excel::download(new RoomsExport(), 'rooms.xlsx');
                    }),

                // Preview PDF (buka tab baru)
                Action::make('previewPdf')
                    ->label('Preview PDF')
                    ->icon('heroicon-m-document-text')
                    ->url(fn() => route('rooms.preview-pdf', ['o' => 'landscape']))
                    ->openUrlInNewTab(),

                // Import Excel
                Action::make('importExcel')
                    ->label('Import Excel')
                    ->icon('heroicon-m-arrow-up-tray')
                    ->form([
                        FileUpload::make('file')
                            ->label('Excel (.xlsx)')
                            ->disk('local')
                            ->directory('imports')
                            ->visibility('private')
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/vnd.ms-excel',
                            ])
                            ->required(),
                    ])
                    ->action(function (array $data) {
                        $relative = $data['file']; // contoh: imports/abc.xlsx
                        $path     = Storage::disk('local')->path($relative);

                        $import = new RoomsImport();
                        Excel::import($import, $path);

                        // hapus file sementara
                        Storage::disk('local')->delete($relative);

                        Notification::make()
                            ->title('Import selesai')
                            ->body("Created: {$import->created}, Updated: {$import->updated}, Skipped: {$import->skipped}")
                            ->success()
                            ->send();
                    }),
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
