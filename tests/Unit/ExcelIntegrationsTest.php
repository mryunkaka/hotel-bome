<?php

namespace Tests\Unit;

use Tests\TestCase;

class ExcelIntegrationsTest extends TestCase
{
    public function test_all_import_and_export_classes_match_the_installed_excel_contract(): void
    {
        $classes = [
            \App\Imports\AccountLedgersImport::class,
            \App\Imports\BankLedgersImport::class,
            \App\Imports\BanksImport::class,
            \App\Imports\BookingsImport::class,
            \App\Imports\GuestsImport::class,
            \App\Imports\HotelsImport::class,
            \App\Imports\IncomeItemsImport::class,
            \App\Imports\RoomsImport::class,
            \App\Imports\UsersImport::class,
            \App\Exports\AccountLedgersExport::class,
            \App\Exports\BankLedgersExport::class,
            \App\Exports\BanksExport::class,
            \App\Exports\BookingsExport::class,
            \App\Exports\GuestsExport::class,
            \App\Exports\HotelsExport::class,
            \App\Exports\IncomeItemsExport::class,
            \App\Exports\RoomsExport::class,
            \App\Exports\UsersExport::class,
        ];

        foreach ($classes as $class) {
            $this->assertTrue(class_exists($class), "{$class} must load with the installed Laravel Excel version.");
        }
    }
}
