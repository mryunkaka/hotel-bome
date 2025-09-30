<?php

namespace App\Filament\Resources\MinibarReceipts\Tables;

use App\Models\Room;
use Filament\Tables\Table;
use Filament\Actions\Action;
use App\Models\MinibarReceipt;
use Filament\Tables\Filters\Filter;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Facades\Session;
use App\Filament\Pages\Minibar\SellItems;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Filament\Actions\ForceDeleteBulkAction;

class MinibarReceiptsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->query(
                MinibarReceipt::query()
                    ->when(Session::get('active_hotel_id'), fn($q, $hid) => $q->where('hotel_id', $hid))
                    ->with([
                        'reservationGuest.guest:id,name',
                        'reservationGuest.room:id,room_no',
                        'user:id,name',
                    ])
            )
            ->defaultSort('issued_at', 'desc')
            ->columns([
                TextColumn::make('receipt_no')
                    ->label('Receipt No')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                TextColumn::make('issued_at')
                    ->label('Issued At')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('reservationGuest.guest.name')
                    ->label('Guest')
                    ->default('-')
                    ->toggleable()
                    ->searchable(),

                TextColumn::make('reservationGuest.room.room_no')
                    ->label('Room')
                    ->default('-')
                    ->toggleable()
                    ->sortable(),

                TextColumn::make('user.name')
                    ->label('Cashier')
                    ->default('-')
                    ->toggleable()
                    ->sortable(),

                TextColumn::make('total_amount')
                    ->label('Total (Rp)')
                    ->formatStateUsing(fn($v) => number_format((float) $v, 0, ',', '.'))
                    ->alignRight()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->colors([
                        'success' => 'paid',
                        'warning' => 'unpaid',
                        'danger'  => 'void',
                    ])
                    ->sortable(),
            ])
            ->filters([
                // range tanggal issued_at
                Filter::make('issued_at_range')
                    ->label('Issued Date')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->label('From'),
                        \Filament\Forms\Components\DatePicker::make('until')->label('Until'),
                    ])
                    ->query(function (Builder $q, array $data) {
                        return $q
                            ->when(filled($data['from'] ?? null),  fn($qq) => $qq->whereDate('issued_at', '>=', $data['from']))
                            ->when(filled($data['until'] ?? null), fn($qq) => $qq->whereDate('issued_at', '<=', $data['until']));
                    }),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'paid'   => 'Paid',
                        'unpaid' => 'Unpaid',
                        'void'   => 'Void',
                    ])
                    ->native(false),

                // filter Room tanpa join ambigu: options dari rooms + whereHas
                SelectFilter::make('room_id')
                    ->label('Room')
                    ->options(function () {
                        $hid = Session::get('active_hotel_id');

                        return Room::query()
                            ->when($hid, fn($q) => $q->where('hotel_id', $hid))
                            ->orderBy('room_no')
                            ->pluck('room_no', 'id')
                            ->toArray();
                    })
                    ->searchable()
                    ->native(false)
                    ->query(function (Builder $q, array $data) {
                        $roomId = $data['value'] ?? null;
                        if (! $roomId) return $q;

                        return $q->whereHas('reservationGuest', fn($qq) => $qq->where('room_id', $roomId));
                    }),
            ])

            // === OPSIONAL: klik baris langsung ke halaman Edit resource ===
            ->recordUrl(
                fn(MinibarReceipt $record) =>
                route('filament.admin.resources.minibar-receipts.edit', ['record' => $record->getKey()])
            )

            ->recordActions([
                // Edit → halaman Edit bawaan Resource
                Action::make('edit')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil-square')
                    ->color('primary')
                    ->url(
                        fn(MinibarReceipt $record) =>
                        route('filament.admin.resources.minibar-receipts.edit', ['record' => $record->getKey()])
                    ),
                Action::make('print')
                    ->label('Print')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->url(
                        fn(MinibarReceipt $record) =>
                        route('minibar-receipts.print', ['receipt' => $record->getKey()])
                    )
                    ->openUrlInNewTab()
                    ->tooltip('Print receipt bill'),
            ])

            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
