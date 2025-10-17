<?php

declare(strict_types=1);

namespace App\Filament\Resources\FacilityBookings\RelationManagers;

use App\Models\Payment;
use App\Support\Accounting\LedgerPoster;

use Filament\Resources\RelationManagers\RelationManager;

// Schemas (v4)
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

// Forms
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\DateTimePicker;

// Tables
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;

// Actions (global v4)
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;

final class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';
    protected static ?string $title = 'Payments';

    public function form(Schema $form): Schema
    {
        return $form->schema([
            Section::make('Payment')->columns(2)->components([
                Select::make('method')
                    ->label('Method')
                    ->options([
                        'cash'     => 'Cash',
                        'bank'     => 'Bank',
                        'transfer' => 'Transfer',
                        'card'     => 'Card',
                    ])
                    ->required(),

                DateTimePicker::make('paid_at')
                    ->label('Paid At')
                    ->seconds(false)
                    ->default(now())
                    ->required(),

                TextInput::make('amount')
                    ->numeric()
                    ->prefix('Rp')
                    ->required()
                    ->minValue(0.01),

                Select::make('bank_id')
                    ->label('Bank')
                    ->relationship('bank', 'name') // Payment::bank() wajib ada
                    ->visible(fn(Get $get) => in_array($get('method'), ['bank', 'transfer', 'card'], true))
                    ->preload()
                    ->searchable(),

                TextInput::make('account_id')
                    ->label('Account (optional)')
                    ->numeric(),

                TextInput::make('cashier_id')
                    ->numeric()
                    ->label('Cashier (optional)'),

                Textarea::make('notes')->rows(2)->columnSpanFull(),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('paid_at')->dateTime()->sortable(),
                TextColumn::make('method')->badge()->sortable(),
                TextColumn::make('amount')->money('IDR', 0)->sortable(),
                TextColumn::make('bank.name')->label('Bank')->toggleable(),
                IconColumn::make('is_posted')->boolean()->label('Posted'),
                TextColumn::make('notes')->limit(40)->toggleable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->after(function (Payment $record): void {
                        /** @var \App\Models\FacilityBooking $booking */
                        $booking = $this->getOwnerRecord();

                        LedgerPoster::postFacilityBookingPayment($record, $booking);

                        // optional: kunci slot & set paid
                        $booking->update([
                            'status'     => \App\Models\FacilityBooking::STATUS_PAID,
                            'is_blocked' => true,
                        ]);
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->after(function (Payment $record): void {
                        /** @var \App\Models\FacilityBooking $booking */
                        $booking = $this->getOwnerRecord();

                        if (method_exists(LedgerPoster::class, 'reverseFacilityBookingPayment')) {
                            LedgerPoster::reverseFacilityBookingPayment($record, $booking);
                        }
                        LedgerPoster::postFacilityBookingPayment($record, $booking);
                    }),

                DeleteAction::make()
                    ->before(function (Payment $record): void {
                        /** @var \App\Models\FacilityBooking $booking */
                        $booking = $this->getOwnerRecord();

                        if (method_exists(LedgerPoster::class, 'reverseFacilityBookingPayment')) {
                            LedgerPoster::reverseFacilityBookingPayment($record, $booking);
                        }

                        // optional: jika semua payment dihapus → buka blok & turunkan status
                        if ($booking->payments()->count() <= 1) {
                            $booking->update([
                                'is_blocked' => false,
                                'status'     => \App\Models\FacilityBooking::STATUS_CONFIRM,
                            ]);
                        }
                    }),
            ])
            ->emptyStateHeading('Belum ada payment')
            ->paginated([10, 25, 50]);
    }
}
