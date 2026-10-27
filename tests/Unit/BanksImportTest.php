<?php

namespace Tests\Unit;

use App\Imports\BanksImport;
use Tests\TestCase;

class BanksImportTest extends TestCase
{
    public function test_numeric_account_number_keeps_significant_trailing_zeroes(): void
    {
        $bank = (new BanksImport())->model([
            'name' => 'Test Bank',
            'account_no' => 123450.0,
        ]);

        $this->assertSame('123450', $bank->account_no);
    }

    public function test_zero_account_number_is_not_treated_as_missing(): void
    {
        $bank = (new BanksImport())->model([
            'name' => 'Test Bank',
            'account_no' => 0,
        ]);

        $this->assertSame('0', $bank->account_no);
    }
}
