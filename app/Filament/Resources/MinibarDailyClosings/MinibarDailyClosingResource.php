<?php

namespace App\Filament\Resources\MinibarDailyClosings;

use App\Filament\Resources\MinibarDailyClosings\Pages\CreateMinibarDailyClosing;
use App\Filament\Resources\MinibarDailyClosings\Pages\EditMinibarDailyClosing;
use App\Filament\Resources\MinibarDailyClosings\Pages\ListMinibarDailyClosings;
use App\Filament\Resources\MinibarDailyClosings\Schemas\MinibarDailyClosingForm;
use App\Filament\Resources\MinibarDailyClosings\Tables\MinibarDailyClosingsTable;
use App\Models\MinibarDailyClosing;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class MinibarDailyClosingResource extends Resource
{
    protected static ?string $model = MinibarDailyClosing::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $recordTitleAttribute = 'minibardailyclosing';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): string
    {
        return 'Minibar';
    }

    public static function form(Schema $schema): Schema
    {
        return MinibarDailyClosingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MinibarDailyClosingsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMinibarDailyClosings::route('/'),
            'create' => CreateMinibarDailyClosing::route('/create'),
            'edit' => EditMinibarDailyClosing::route('/{record}/edit'),
        ];
    }
}
