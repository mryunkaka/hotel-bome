<?php

namespace App\Filament\Resources\MinibarDailyClosings\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MinibarDailyClosingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('closed_at', 'desc')
            ->columns([
                TextColumn::make('hotel_id')
                    ->label('Hotel id')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('closing_date')
                    ->label('Closing date')
                    ->date()
                    ->sortable(),

                // Periode (jika kolom ada di DB/model, akan tampil)
                TextColumn::make('closing_start_at')
                    ->label('Start')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('closing_end_at')
                    ->label('End')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_balanced')
                    ->label('Is balanced')
                    ->boolean()
                    ->sortable(),

                IconColumn::make('is_locked')
                    ->label('Locked?')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('total_sales')
                    ->label('Total sales')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('total_cogs')
                    ->label('Total cogs')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('total_profit')
                    ->label('Total profit')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('total_restock_cost')
                    ->label('Total restock cost')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('cash_actual')
                    ->label('Cash (actual)')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('variance_amount')
                    ->label('Variance')
                    ->numeric()
                    ->sortable(),

                // tampilkan nama user jika relasi 'closedBy' ada; fallback ke id
                TextColumn::make('closedBy.name')
                    ->label('Closed by')
                    ->sortable()
                    ->placeholder(fn($record) => (string) $record->closed_by),

                TextColumn::make('closed_at')
                    ->label('Closed at')
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
            ])
            ->filters([
                // tambahkan filter bila perlu
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
