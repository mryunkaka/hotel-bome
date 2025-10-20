<?php

namespace App\Filament\Resources\FacilityBlocks;

use App\Filament\Resources\FacilityBlocks\Pages\CreateFacilityBlock;
use App\Filament\Resources\FacilityBlocks\Pages\EditFacilityBlock;
use App\Filament\Resources\FacilityBlocks\Pages\ListFacilityBlocks;
use App\Filament\Resources\FacilityBlocks\Schemas\FacilityBlockForm;
use App\Filament\Resources\FacilityBlocks\Tables\FacilityBlocksTable;
use App\Models\FacilityBlock;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class FacilityBlockResource extends Resource
{
    protected static ?string $model = FacilityBlock::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;
    protected static ?string $recordTitleAttribute = 'Facility Block';
    protected static ?string $navigationLabel = 'Facility Status';

    public static function form(Schema $schema): Schema
    {
        return FacilityBlockForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FacilityBlocksTable::configure($table);
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
            'index'  => ListFacilityBlocks::route('/'),
            'create' => CreateFacilityBlock::route('/create'),
            'edit'   => EditFacilityBlock::route('/{record}/edit'),
        ];
    }

    // ⬇️ arahkan menu langsung ke /create (board), seperti RoomBoards
    public static function getNavigationUrl(): string
    {
        return static::getUrl('create');
    }
}
