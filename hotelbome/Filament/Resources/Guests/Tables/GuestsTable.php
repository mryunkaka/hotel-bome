<?php

namespace App\Filament\Resources\Guests\Tables;

use Filament\Tables\Table;
use Filament\Actions\Action;
use App\Exports\GuestsExport;
use App\Imports\GuestsImport;
use Filament\Actions\EditAction;
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

class GuestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('hotel.name')
                    ->label('Hotel')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('salutation')
                    ->label('Title')
                    ->formatStateUsing(fn($state) => $state instanceof \BackedEnum ? $state->value : ($state instanceof \UnitEnum ? $state->name : $state))
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('guest_type')
                    ->label('Guest Type')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('phone')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('address')
                    ->limit(30)
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('city')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('nationality')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('profession')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('id_type')
                    ->label('ID Type')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('id_card')
                    ->label('ID / Passport No')
                    ->searchable()
                    ->copyable()
                    ->sortable(),

                TextColumn::make('id_card_file')
                    ->label('Attachment')
                    ->url(fn($record) => $record->id_card_file ? Storage::url($record->id_card_file) : null, true)
                    ->openUrlInNewTab()
                    ->toggleable(),

                TextColumn::make('birth_place')
                    ->label('Birth Place')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('birth_date')
                    ->date()
                    ->label('Birth Date')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('issued_place')
                    ->label('Issued At')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('issued_date')
                    ->date()
                    ->label('Issued Date')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

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
                    ->action(function () {
                        return Excel::download(new GuestsExport, 'guests.xlsx');
                    }),

                // Preview PDF (buka tab baru ke route preview)
                Action::make('previewPdf')
                    ->label('Preview PDF')
                    ->icon('heroicon-m-document-text')
                    ->url(route('guests.preview-pdf'))
                    ->openUrlInNewTab(),

                // Import Excel
                Action::make('importExcel')
                    ->label('Import Excel')
                    ->icon('heroicon-m-arrow-up-tray')
                    ->form([
                        FileUpload::make('file')
                            ->label('File (.xlsx / .csv)')
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'text/csv',
                                'application/vnd.ms-excel',
                            ])
                            ->required()
                            ->storeFiles(false),
                    ])
                    ->action(function (array $data) {
                        try {
                            Excel::import(new GuestsImport, $data['file']);
                            Notification::make()
                                ->title('Import berhasil')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            report($e);
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
