<?php

namespace App\Filament\Resources\Walkins\Tables;

use Filament\Tables\Table;
use Filament\Actions\EditAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\Reservations\Tables\ReservationsTable;

class WalkinsTable
{
    public static function configure(Table $table): Table
    {
        // Pakai konfigurasi tabel “ReservationsTable” supaya tampilannya sama.
        // Query akan difilter jadi WALKIN pada Page ListWalkins (getTableQuery()).
        return ReservationsTable::configure($table)
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
