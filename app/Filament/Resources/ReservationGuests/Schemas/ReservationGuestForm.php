<?php

namespace App\Filament\Resources\ReservationGuests\Schemas;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class ReservationGuestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            // ======================= Section: Reservation & Assignment =======================
            Section::make('Reservation & Assignment')
                ->components([
                    Hidden::make('hotel_id')
                        ->default(fn() => Session::get('active_hotel_id') ?? Auth::user()?->hotel_id)
                        ->required(),

                    Grid::make()->columns(12)->components([
                        Select::make('reservation_id')
                            ->label('Reservation')
                            ->relationship('reservation', 'reservation_no')
                            ->searchable()
                            ->disabled()
                            ->preload()
                            ->required()
                            ->columnSpan(4),

                        Select::make('guest_id')
                            ->label('Guest')
                            ->relationship('guest', 'name')
                            ->searchable()
                            ->disabled()
                            ->preload()
                            ->required()
                            ->columnSpan(5),

                        Select::make('room_id')
                            ->label('Room')
                            ->relationship(
                                name: 'room',
                                titleAttribute: 'room_no',
                                modifyQueryUsing: function ($query, Get $get, $record) {
                                    $reservationId = $record?->reservation_id ?? $get('reservation_id');
                                    if ($reservationId) {
                                        $query->whereDoesntHave('reservationGuests', fn($q)
                                        => $q->where('reservation_id', $reservationId));
                                    }
                                    return $query;
                                }
                            )
                            ->searchable()
                            ->preload()
                            ->required()
                            ->columnSpan(3),
                    ]),
                ])
                ->collapsible(),

            // ======================= Section: Pax =======================
            Section::make('Pax')
                ->components([
                    Grid::make()->columns(12)->components([
                        TextInput::make('jumlah_orang')
                            ->label('Pax')
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->required()
                            ->columnSpan(3),

                        TextInput::make('male')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->columnSpan(3)
                            ->reactive()
                            ->afterStateUpdated(function ($state, callable $set, Get $get) {
                                $total = (int)$state + (int)$get('female') + (int)$get('children');
                                $set('jumlah_orang', max(1, $total));
                            }),

                        TextInput::make('female')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->columnSpan(3)
                            ->reactive()
                            ->afterStateUpdated(function ($state, callable $set, Get $get) {
                                $total = (int)$get('male') + (int)$state + (int)$get('children');
                                $set('jumlah_orang', max(1, $total));
                            }),

                        TextInput::make('children')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->columnSpan(3)
                            ->reactive()
                            ->afterStateUpdated(function ($state, callable $set, Get $get) {
                                $total = (int)$get('male') + (int)$get('female') + (int)$state;
                                $set('jumlah_orang', max(1, $total));
                            }),
                    ]),
                ])
                ->collapsible(),

            // ======================= Section: Billing =======================
            Section::make('Billing')
                ->components([
                    Grid::make()->columns(12)->components([
                        Select::make('charge_to')
                            ->label('Charge To')
                            ->options([
                                'GUEST'       => 'Guest',
                                'COMPANY'     => 'Company',
                                'RESERVATION' => 'Reservation',
                            ])
                            ->required()
                            ->columnSpan(6),

                        TextInput::make('room_rate')
                            ->label('Room Rate (++)')
                            ->numeric()
                            ->prefix('Rp')
                            ->minValue(0)
                            ->default(0)
                            ->required()
                            ->columnSpan(6),
                    ]),
                ])
                ->collapsible(),

            // ======================= Section: Schedule =======================
            Section::make('Schedule')
                ->components([
                    Grid::make()->columns(12)->components([
                        DateTimePicker::make('expected_checkin')
                            ->label('Expected Check-In')
                            ->seconds(false)
                            ->native(false)
                            ->columnSpan(6),

                        DateTimePicker::make('expected_checkout')
                            ->label('Expected Check-Out')
                            ->seconds(false)
                            ->native(false)
                            ->columnSpan(6),

                        DateTimePicker::make('actual_checkin')
                            ->label('Actual Check-In')
                            ->seconds(false)
                            ->native(false)
                            ->columnSpan(6),

                        DateTimePicker::make('actual_checkout')
                            ->label('Actual Check-Out')
                            ->seconds(false)
                            ->native(false)
                            ->columnSpan(6),
                    ]),
                ])
                ->collapsible(),

            // ======================= Section: Notes =======================
            Section::make('Notes')
                ->components([
                    Textarea::make('note')->columnSpanFull(),
                ])
                ->collapsible(),
        ]);
    }
}
