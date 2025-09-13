<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use App\Filament\Pages\Auth\Login;
use Andreia\FilamentNordTheme\FilamentNordThemePlugin;
use App\Models\Hotel;
use Illuminate\Support\Facades\Storage;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->authGuard('web')
            // === BRAND DINAMIS BERDASARKAN HOTEL AKTIF ===
            ->brandLogo(function () {
                $hotelId = session('active_hotel_id');      // diset saat login custom
                if ($hotelId) {
                    $hotel = Hotel::find($hotelId);
                    if ($hotel && $hotel->logo) {
                        // logo disimpan di disk 'public' → kembalikan URL penuh
                        /** @var FilesystemAdapter $disk */
                        $disk = Storage::disk('public');
                        return $disk->url($hotel->logo);
                    }
                }
                // fallback jika belum ada hotel/logo
                return asset('Storage/Images/logo.png');
            })
            ->brandName(function () {
                $hotelId = session('active_hotel_id');
                return $hotelId ? (Hotel::find($hotelId)->name ?? 'Hotel App') : 'Hotel App';
            })
            ->brandLogoHeight('4rem')   // sesuaikan tinggi logo
            ->login(Login::class)
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->plugin(FilamentNordThemePlugin::make())
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
