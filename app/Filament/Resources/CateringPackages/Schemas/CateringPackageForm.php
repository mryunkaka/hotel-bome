<?php

namespace App\Filament\Resources\CateringPackages\Schemas;

use Filament\Schemas\Schema;
use App\Support\FacilityCodeHelper;
use Illuminate\Support\Facades\Auth;
use App\Support\CateringSchemaHelper;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Textarea;
use Illuminate\Support\Facades\Session;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Illuminate\Validation\Rules\Unique as UniqueRule;

class CateringPackageForm
{
    public static function configure(Schema $schema): Schema
    {
        $activeHotelId = (int) (Session::get('active_hotel_id') ?? (Auth::user()->hotel_id ?? 0));

        // Deteksi kolom yang ada (aman ke migrasi/model kamu)
        $hasHotelId   = CateringSchemaHelper::hasColumn('catering_packages', 'hotel_id');
        $hasCode      = CateringSchemaHelper::hasColumn('catering_packages', 'code');
        $hasName      = CateringSchemaHelper::hasColumn('catering_packages', 'name');
        $hasDesc      = CateringSchemaHelper::hasColumn('catering_packages', 'description');
        $hasMinPax    = CateringSchemaHelper::hasColumn('catering_packages', 'min_pax');
        $hasPricePax  = CateringSchemaHelper::hasColumn('catering_packages', 'price_per_pax');
        $hasIsActive  = CateringSchemaHelper::hasColumn('catering_packages', 'is_active');

        return $schema->components(array_values(array_filter([
            $hasHotelId
                ? Hidden::make('hotel_id')->default($activeHotelId)->dehydrated(true)
                : null,

            Section::make('Catering Package')->schema([
                Grid::make(12)->schema(array_values(array_filter([
                    $hasName
                        ? TextInput::make('name')
                        ->label('Name')
                        ->required()
                        ->live(onBlur: true)
                        ->columnSpan(6)
                        ->afterStateUpdated(function ($state, callable $set, callable $get) use ($activeHotelId, $hasCode) {
                            if ($hasCode && blank($get('code'))) {
                                $set('code', FacilityCodeHelper::generate($activeHotelId, $state));
                            }
                        })
                        : null,

                    $hasCode
                        ? TextInput::make('code')
                        ->label('Code')
                        ->disabled()
                        ->dehydrated(true)
                        ->unique(
                            modifyRuleUsing: function (UniqueRule $rule) use ($activeHotelId) {
                                return $rule->where(fn($q) => $q->where('hotel_id', $activeHotelId));
                            },
                            ignoreRecord: true,
                        )
                        ->helperText('Auto-generated on create; unique per hotel.')
                        ->columnSpan(3)
                        : null,

                    $hasMinPax
                        ? TextInput::make('min_pax')
                        ->label('Minimum Pax')
                        ->numeric()
                        ->minValue(1)
                        ->default(1)
                        ->columnSpan(3)
                        : null,

                    $hasPricePax
                        ? TextInput::make('price_per_pax')
                        ->label('Price per Pax')
                        ->prefix('Rp')
                        ->numeric()
                        ->required()
                        ->columnSpan(3)
                        : null,

                    $hasIsActive
                        ? Toggle::make('is_active')
                        ->label('Active')
                        ->default(true)
                        ->columnSpan(2)
                        : null,

                    $hasDesc
                        ? Textarea::make('description')
                        ->label('Description')
                        ->rows(3)
                        ->columnSpan(7)
                        : null,
                ]))),
            ])->columnSpanFull(),
        ])));
    }
}
