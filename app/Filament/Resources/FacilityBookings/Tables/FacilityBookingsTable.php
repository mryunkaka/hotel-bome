<?php

declare(strict_types=1);

namespace App\Filament\Resources\FacilityBookings\Tables;

use Filament\Tables\Table;
use App\Models\FacilityBooking;

use Filament\Actions\EditAction;
use Filament\Actions\CreateAction;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Filament\Forms\Components\DateTimePicker;

final class FacilityBookingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('facility.name')->label('Facility')->searchable()->sortable(),
                TextColumn::make('start_at')->dateTime()->sortable(),
                TextColumn::make('end_at')->dateTime()->sortable(),
                TextColumn::make('pricing_mode')->badge(),
                TextColumn::make('unit_price')->money('IDR', 0),
                TextColumn::make('quantity'),
                TextColumn::make('total_amount')->money('IDR', 0)->sortable(),
                IconColumn::make('include_catering')->boolean()->label('Catering'),
                // ⬇️ BadgeColumn deprecated → gunakan TextColumn + badge() + color()
                TextColumn::make('status')
                    ->badge()
                    ->color(fn(string $state) => match ($state) {
                        FacilityBooking::STATUS_DRAFT     => 'warning',
                        FacilityBooking::STATUS_CONFIRM   => 'info',
                        FacilityBooking::STATUS_PAID      => 'success',
                        FacilityBooking::STATUS_COMPLETED => 'gray',
                        FacilityBooking::STATUS_CANCELLED => 'danger',
                        default                           => null,
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    FacilityBooking::STATUS_DRAFT     => 'DRAFT',
                    FacilityBooking::STATUS_CONFIRM   => 'CONFIRM',
                    FacilityBooking::STATUS_PAID      => 'PAID',
                    FacilityBooking::STATUS_COMPLETED => 'COMPLETED',
                    FacilityBooking::STATUS_CANCELLED => 'CANCELLED',
                ]),
                Filter::make('date_range')
                    // ⬇️ form() deprecated → gunakan schema()
                    ->schema([
                        DateTimePicker::make('from'),
                        DateTimePicker::make('to'),
                    ])
                    ->query(function (Builder $q, array $data) {
                        if ($data['from'] ?? null) {
                            $q->where('end_at', '>', $data['from']);
                        }
                        if ($data['to'] ?? null) {
                            $q->where('start_at', '<', $data['to']);
                        }
                    }),
            ])
            // ⬇️ actions() deprecated → gunakan recordActions()
            ->recordActions([
                EditAction::make(),
            ])
            ->headerActions([
                // CreateAction::make(),
            ]);
    }
}
