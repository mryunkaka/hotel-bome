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
            ->columns([
                TextColumn::make('hotel_id')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('closing_date')
                    ->date()
                    ->sortable(),
                IconColumn::make('is_balanced')
                    ->boolean(),
                TextColumn::make('total_sales')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('total_cogs')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('total_profit')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('total_restock_cost')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('variance_amount')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('closed_by')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('closed_at')
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
                //
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
