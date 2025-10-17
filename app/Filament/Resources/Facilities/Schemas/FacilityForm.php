<?php

namespace App\Filament\Resources\Facilities\Schemas;

use App\Models\Facility;
use Filament\Schemas\Schema;
use App\Support\FacilitySchemaHelper;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Textarea;
use Illuminate\Support\Facades\Session;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Illuminate\Validation\Rules\Unique as UniqueRule;

class FacilityForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Facility Info')
                ->schema([
                    Hidden::make('hotel_id')
                        ->default(fn() => Session::get('active_hotel_id'))
                        ->required(),

                    // Row 1
                    Grid::make(12)->schema([
                        TextInput::make('code')
                            ->label('Facility Code')
                            ->maxLength(50)
                            ->unique(ignoreRecord: true, modifyRuleUsing: function (UniqueRule $rule) {
                                $hotelId = (int) (Session::get('active_hotel_id') ?? 0);
                                return $rule->where('hotel_id', $hotelId);
                            })
                            ->afterStateHydrated(function (callable $set, $state, $record) {
                                $hotelId = (int) (Session::get('active_hotel_id') ?? 0);
                                if (!$record && (empty($state) || trim((string) $state) === '') && $hotelId) {
                                    $set('code', \App\Support\FacilityCodeHelper::generate($hotelId));
                                }
                            })
                            ->disabled()
                            ->dehydrated(true)
                            ->columnSpan(4),

                        TextInput::make('name')
                            ->required()
                            ->maxLength(150)
                            ->columnSpan(8),
                    ]),

                    // Row 2
                    Grid::make(12)->schema([
                        Select::make('type')
                            ->options([
                                Facility::TYPE_VENUE     => 'Venue (Room / Hall)',
                                Facility::TYPE_VEHICLE   => 'Vehicle',
                                Facility::TYPE_EQUIPMENT => 'Equipment',
                                Facility::TYPE_SERVICE   => 'Service',
                                Facility::TYPE_OTHER     => 'Other',
                            ])
                            ->default(Facility::TYPE_VENUE)
                            ->required()
                            ->native(false)
                            ->columnSpan(4),

                        Select::make('base_pricing_mode')
                            ->label('Base Pricing Mode')
                            ->options([
                                Facility::PRICING_PER_HOUR => 'Per Hour',
                                Facility::PRICING_PER_DAY  => 'Per Day',
                                Facility::PRICING_FIXED    => 'Fixed',
                            ])
                            ->default(Facility::PRICING_PER_HOUR)
                            ->required()
                            ->native(false)
                            ->columnSpan(4),

                        TextInput::make('base_price')
                            ->numeric()
                            ->required()
                            ->default(0)
                            ->prefix('Rp')
                            ->rule('integer')
                            ->minValue(0)
                            ->columnSpan(4),
                    ]),

                    // Row 3
                    Grid::make(12)->schema([
                        TextInput::make('capacity')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->columnSpan(3),

                        Toggle::make('is_active')
                            ->default(true)
                            ->inline(false)
                            ->columnSpan(3),

                        Textarea::make('description')
                            ->rows(3)
                            ->columnSpan(6),
                    ]),
                ])
                ->columnSpanFull(), // section satu kolom, tiap Row diatur oleh Grid di atas
        ]);
    }
}
