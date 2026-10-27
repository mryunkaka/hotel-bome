<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use Filament\Pages\Dashboard as BaseDashboard;
use Tests\TestCase;

class AdminPanelNavigationTest extends TestCase
{
    public function test_admin_panel_registers_only_the_custom_dashboard_page(): void
    {
        $dashboardPages = array_values(array_filter(
            app('filament')->getPanel('admin')->getPages(),
            static fn (string $page): bool => is_a($page, BaseDashboard::class, true),
        ));

        $this->assertSame([Dashboard::class], $dashboardPages);
    }
}
