<?php

namespace App\Filament\Resources\Invoices\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('date')->dateTime()->sortable()->searchable(),
                TextColumn::make('booking.room.room_no')->label('Room')->sortable()->searchable(),
                TextColumn::make('booking.guest.name')->label('Guest')->sortable()->searchable(),
                TextColumn::make('subtotal')->money('idr', true)->sortable(),
                TextColumn::make('tax_total')->money('idr', true)->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('total')->money('idr', true)->sortable(),
                TextColumn::make('payment_method')->badge(),
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
