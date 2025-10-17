<?php

declare(strict_types=1);

namespace App\Filament\Resources\FacilityBookings\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;

// Schemas (v4)
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

// Forms
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;

// Tables
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;

// Actions (global v4)
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;

final class CateringItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'cateringItems';
    protected static ?string $title = 'Catering';

    public function form(Schema $form): Schema
    {
        return $form->schema([
            Section::make('Catering Item')
                ->columns(2)
                ->components([
                    Select::make('package_id')
                        ->label('Package')
                        ->relationship('package', 'name') // pastikan item model punya belongsTo package()
                        ->searchable()
                        ->preload()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function ($value, Set $set): void {
                            if (! $value) return;
                            /** @var \App\Models\CateringPackage|null $pkg */
                            $pkg = \App\Models\CateringPackage::find($value);
                            if ($pkg) {
                                // set harga default dari paket
                                $set('unit_price', $pkg->price ?? 0);
                                // re-hitungan subtotal jika pax sudah terisi
                                // (subtotal dihitung di afterStateUpdated unit_price/pax di bawah)
                            }
                        }),

                    TextInput::make('pax')
                        ->label('Pax')
                        ->numeric()
                        ->minValue(1)
                        ->default(1)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Get $get, Set $set) {
                            $price = (float) ($get('unit_price') ?? 0);
                            $pax   = (int)   ($get('pax') ?? 0);
                            $set('subtotal_amount', $price * $pax);
                        })
                        ->required(),

                    TextInput::make('unit_price')
                        ->label('Unit Price')
                        ->prefix('Rp')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Get $get, Set $set) {
                            $price = (float) ($get('unit_price') ?? 0);
                            $pax   = (int)   ($get('pax') ?? 0);
                            $set('subtotal_amount', $price * $pax);
                        })
                        ->required(),

                    TextInput::make('subtotal_amount')
                        ->label('Subtotal')
                        ->prefix('Rp')
                        ->numeric()
                        ->readOnly()
                        ->dehydrated(true),

                    Textarea::make('notes')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('package.name')->label('Package')->searchable(),
                TextColumn::make('pax')->label('Pax')->sortable(),
                TextColumn::make('unit_price')->label('Unit')->money('IDR', 0)->sortable(),
                TextColumn::make('subtotal_amount')->label('Subtotal')->money('IDR', 0)->sortable(),
                TextColumn::make('notes')->limit(40)->toggleable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->after(function ($record): void {
                        /** @var \App\Models\FacilityBooking $booking */
                        $booking = $this->getOwnerRecord();
                        // refresh total catering & grand total
                        $booking->recalcCateringTotals();
                        $booking->recalcTotals();
                        $booking->save();
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->after(function ($record): void {
                        $booking = $this->getOwnerRecord();
                        $booking->recalcCateringTotals();
                        $booking->recalcTotals();
                        $booking->save();
                    }),

                DeleteAction::make()
                    ->after(function ($record): void {
                        $booking = $this->getOwnerRecord();
                        $booking->recalcCateringTotals();
                        $booking->recalcTotals();
                        $booking->save();
                    }),
            ])
            ->emptyStateHeading('Belum ada item catering')
            ->paginated([10, 25, 50]);
    }
}
