<?php

namespace App\Filament\Resources\Banks\Tables;

use Filament\Tables\Table;
use App\Exports\BanksExport;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Maatwebsite\Excel\Facades\Excel;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Notifications\Notification;
use Filament\Forms\Components\FileUpload;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Actions\ForceDeleteBulkAction;

class BanksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('hotel.name')
                    ->searchable(),
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('branch')
                    ->searchable(),
                TextColumn::make('account_no')
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
                        return Excel::download(new BanksExport, 'banks.xlsx');
                    }),

                Action::make('previewPdf')
                    ->label('Preview PDF')
                    ->icon('heroicon-m-document-text')
                    ->url(route('banks.preview-pdf'))
                    ->openUrlInNewTab(),

                Action::make('importExcel')
                    ->label('Import Excel')
                    ->icon('heroicon-m-arrow-up-tray')
                    ->form(fn() => [
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
                        \Maatwebsite\Excel\Facades\Excel::import(new \App\Imports\BanksImport, $data['file']);
                        Notification::make()->title('Import selesai')->success()->send();
                    }),
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
