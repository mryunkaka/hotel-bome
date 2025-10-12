<?php

namespace App\Providers\Filament;

use Filament\Panel;
use App\Models\Hotel;
use Filament\PanelProvider;
use Filament\Pages\Dashboard;
use Filament\Facades\Filament;
use App\Filament\Pages\Auth\Login;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Events\ServingFilament;
use Illuminate\Support\Facades\Storage;
use Filament\Notifications\Notification;
use Filament\Widgets\FilamentInfoWidget;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Filament\Http\Middleware\AuthenticateSession;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Andreia\FilamentNordTheme\FilamentNordThemePlugin;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;

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
    public function boot(): void
    {
        Filament::serving(function () {
            // Pastikan hanya untuk panel 'admin'
            $panel = Filament::getCurrentPanel();
            if ($panel && $panel->getId() !== 'admin') {
                return;
            }

            if (session()->pull('forbidden_to_filament_dashboard', false)) {
                Notification::make()
                    ->title('Anda tidak dapat mengakses halaman ini')
                    ->body('Anda telah dialihkan ke Dashboard.')
                    ->danger()
                    ->send();
            }
        });
    }
}
