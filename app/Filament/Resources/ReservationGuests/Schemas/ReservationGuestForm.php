<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReservationGuests\Schemas;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\ViewField;

final class ReservationGuestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Registration View')
                ->collapsible()
                ->schema([
                    Grid::make()
                        ->schema([
                            ViewField::make('registration_preview')
                                ->view('filament.forms.components.registration-preview')
                                ->columnSpanFull(),
                        ]),
                ])->columnSpanFull(),
        ]);
    }
}
