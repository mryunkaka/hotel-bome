<?php

namespace App\Filament\Resources\Hotels;

use BackedEnum;
use App\Models\Hotel;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use App\Filament\Resources\Hotels\Pages\EditHotel;
use App\Filament\Resources\Hotels\Pages\ListHotels;
use App\Filament\Traits\ForbidReceptionistResource;
use App\Filament\Resources\Hotels\Pages\CreateHotel;
use App\Filament\Resources\Hotels\Schemas\HotelForm;
use App\Filament\Resources\Hotels\Tables\HotelsTable;

class HotelResource extends Resource
{
    use ForbidReceptionistResource;

    protected static ?string $model = Hotel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): string
    {
        return 'Master Setting';
    }

    /** Hanya Super Admin yang melihat menu */
    public static function shouldRegisterNavigation(): bool
    {
        return self::isSuperAdmin();
    }

    /** Guard semua aksi hanya untuk Super Admin */
    public static function canViewAny(): bool
    {
        return self::isSuperAdmin();
    }

    public static function canCreate(): bool
    {
        return self::isSuperAdmin();
    }

    public static function canView($record): bool
    {
        return self::isSuperAdmin();
    }

    public static function canEdit($record): bool
    {
        return self::isSuperAdmin();
    }

    public static function canDelete($record): bool
    {
        return self::isSuperAdmin();
    }

    public static function canDeleteAny(): bool
    {
        return self::isSuperAdmin();
    }

    /** Form & Table (Schemas API) */
    public static function form(Schema $schema): Schema
    {
        return HotelForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return HotelsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListHotels::route('/'),
            'create' => CreateHotel::route('/create'),
            'edit'   => EditHotel::route('/{record}/edit'),
        ];
    }

    /** Helper: cek super admin dengan facade Auth (ramah Intelephense) */
    private static function isSuperAdmin(): bool
    {
        if (! Auth::check()) {
            return false;
        }

        $user = Auth::user();
        /** @var \App\Models\User $user */ // bantu Intelephense mengenali tipe
        return method_exists($user, 'hasRole') && $user->hasRole('super admin');
    }
}
