<?php

namespace App\Filament\Resources\RoomDailyClosings\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class RoomDailyClosingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('hotel.name')
                    ->searchable(),
                TextColumn::make('closing_date')
                    ->date()
                    ->sortable(),
                IconColumn::make('is_balanced')
                    ->boolean(),
                TextColumn::make('total_room_revenue')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('total_tax')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('total_discount')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('total_deposit')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('total_refund')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('total_payment')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('variance_amount')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('closing_start_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('closing_end_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('cash_actual')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('is_locked')
                    ->boolean(),
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
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
