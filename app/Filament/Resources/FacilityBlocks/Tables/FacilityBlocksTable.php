<?php

namespace App\Filament\Resources\FacilityBlocks\Tables;

use App\Models\Facility;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker as FormDateTimePicker;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class FacilityBlocksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('facility.name')
                    ->label('Facility')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('start_at')
                    ->label('Start')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('end_at')
                    ->label('End')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Reason')
                    ->limit(60)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Created By')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->modifyQueryUsing(function (Builder $query) {
                $hid = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;
                if ($hid) {
                    $query->where('hotel_id', $hid);
                }
            })
            ->filters([
                SelectFilter::make('facility_id')
                    ->label('Facility')
                    ->options(function () {
                        $hid = Session::get('active_hotel_id') ?? Auth::user()?->hotel_id;
                        return Facility::query()
                            ->when($hid, fn($q) => $q->where('hotel_id', $hid))
                            ->orderBy('name')->pluck('name', 'id');
                    }),

                // ✅ gunakan queries(true: ..., false: ..., blank: ...) — bukan array
                TernaryFilter::make('active')
                    ->label('Active / Upcoming')
                    ->placeholder('— Any —')
                    ->trueLabel('Only active/upcoming')
                    ->falseLabel('Past only')
                    ->queries(
                        true: function (Builder $q): Builder {
                            $now = Carbon::now(config('app.timezone'))->format('Y-m-d H:i:s');
                            return $q->where('end_at', '>=', $now);
                        },
                        false: function (Builder $q): Builder {
                            $now = Carbon::now(config('app.timezone'))->format('Y-m-d H:i:s');
                            return $q->where('end_at', '<', $now);
                        },
                        blank: fn(Builder $q): Builder => $q,
                    ),

                // ✅ ganti form() → schema() untuk hilangkan deprecation
                Filter::make('date_range')
                    ->schema([
                        FormDateTimePicker::make('from')->label('From')->seconds(false),
                        FormDateTimePicker::make('to')->label('To')->seconds(false),
                    ])
                    ->query(function (Builder $q, array $data) {
                        $tz = config('app.timezone');
                        if (!empty($data['from'])) {
                            $from = Carbon::parse($data['from'], $tz)->format('Y-m-d H:i:s');
                            $q->where('end_at', '>=', $from);
                        }
                        if (!empty($data['to'])) {
                            $to = Carbon::parse($data['to'], $tz)->format('Y-m-d H:i:s');
                            $q->where('start_at', '<=', $to);
                        }
                    })
                    ->indicateUsing(function (array $data): array {
                        $badges = [];
                        if (!empty($data['from'])) {
                            $badges[] = 'From: ' . Carbon::parse($data['from'])->format('d/m/Y H:i');
                        }
                        if (!empty($data['to'])) {
                            $badges[] = 'To: ' . Carbon::parse($data['to'])->format('d/m/Y H:i');
                        }
                        return $badges;
                    }),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
