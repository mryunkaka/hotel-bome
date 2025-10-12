<?php

namespace App\Filament\Traits;

use Illuminate\Support\Facades\Auth;

trait ForbidReceptionistResource
{
    protected static function blocked(): bool
    {
        /** @var \App\Models\User|\Spatie\Permission\Traits\HasRoles|null $u */
        $u = Auth::user();
        return $u && $u->hasRole('resepsionis');
    }

    /** Sembunyikan dari sidebar */
    public static function shouldRegisterNavigation(): bool
    {
        return ! static::blocked();
    }

    /** Tolak semua aksi (list/create/edit/delete/restore/forceDelete) */
    public static function canViewAny(): bool
    {
        return ! static::blocked();
    }
    public static function canCreate(): bool
    {
        return ! static::blocked();
    }
    public static function canEdit($record): bool
    {
        return ! static::blocked();
    }
    public static function canDelete($record): bool
    {
        return ! static::blocked();
    }
    public static function canDeleteAny(): bool
    {
        return ! static::blocked();
    }
    public static function canRestore($record): bool
    {
        return ! static::blocked();
    }
    public static function canForceDelete($record): bool
    {
        return ! static::blocked();
    }
}
