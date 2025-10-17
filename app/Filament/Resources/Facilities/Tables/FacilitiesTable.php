<?php
// ===== FILE: app/Filament/Resources/Facilities/Tables/FacilitiesTable.php

namespace App\Filament\Resources\Facilities\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class FacilitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('hotel.name')->label('Hotel')->sortable()->searchable(),
                TextColumn::make('code')->label('Code')->toggleable()->sortable()->searchable(),
                TextColumn::make('name')->label('Name')->sortable()->searchable(),
                TextColumn::make('type')->badge()->sortable(),
                TextColumn::make('base_pricing_mode')->label('Pricing')
                    ->badge()
                    ->sortable(),
                TextColumn::make('base_price')->label('Base Price')->money('idr', true)->sortable(),
                TextColumn::make('capacity')->numeric()->alignRight()->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')->boolean()->label('Active')->sortable(),
                TextColumn::make('updated_at')->since()->label('Updated'),
            ])
            ->filters([
                SelectFilter::make('type')->options([
                    'room'      => 'Room / Hall',
                    'vehicle'   => 'Vehicle',
                    'equipment' => 'Equipment',
                    'service'   => 'Service',
                    'other'     => 'Other',
                ]),
                SelectFilter::make('base_pricing_mode')->label('Pricing')
                    ->options([
                        'per_hour' => 'Per Hour',
                        'per_day'  => 'Per Day',
                        'fixed'    => 'Fixed',
                    ]),
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
