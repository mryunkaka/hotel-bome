<?php

namespace App\Filament\Traits;

use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use App\Filament\Pages\Dashboard;

trait ForbidReceptionistPage
{
    protected function forbidReceptionistAndRedirect(): void
    {
        /** @var \App\Models\User|\Spatie\Permission\Traits\HasRoles|null $u */
        $u = Auth::user();
        if ($u && method_exists($u, 'hasRole') && $u->hasRole('resepsionis')) {
            Notification::make()
                ->title('Anda tidak dapat mengakses halaman ini')
                ->body('Anda akan dialihkan ke Dashboard.')
                ->danger()
                ->send();

            // Pastikan selalu balik ke dashboard panel yang sama
            $this->redirect(Dashboard::getUrl());
        }
    }
}
