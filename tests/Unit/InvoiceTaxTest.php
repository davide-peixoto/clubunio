<?php

namespace Tests\Unit;

use App\ClubUnio\Services\InvoiceTaxService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InvoiceTaxTest extends TestCase
{
    public function test_mixed_inclusive_taxes_and_rounding_use_exact_decimals(): void
    {
        $result = (new InvoiceTaxService)->calculate([
            ['cents' => 10810, 'tax' => ['rate_basis_points' => 810, 'type' => 'sales_tax']],
            ['cents' => 10260, 'tax' => ['rate_basis_points' => 260, 'type' => 'sales_tax']],
            ['cents' => 1001, 'tax' => ['rate_basis_points' => 0, 'type' => 'not_taxable_turnover']],
        ], true);
        $this->assertSame(22071, $result['unrounded_cents']);
        $this->assertSame(22070, $result['total_cents']);
        $this->assertSame(-1, $result['rounding_cents']);
        $this->assertSame('10.700000', $result['theoretical_tax']);
        $this->assertSame('10.699515', $result['tax']);
        $this->assertSame('210.010485', $result['exclusive']);
        $this->assertTrue($result['complete']);
    }

    public function test_rounding_and_exempt_legacy_amounts(): void
    {
        foreach ([999 => 1, 1000 => 0, 1001 => -1, 1002 => -2, 1003 => 2, 1004 => 1] as $cents => $difference) {
            $line = [['cents' => $cents, 'tax' => ['rate_basis_points' => 0, 'type' => 'not_taxable_turnover']]];
            $this->assertSame($difference, (new InvoiceTaxService)->calculate($line, true)['rounding_cents']);
            $this->assertSame($cents, (new InvoiceTaxService)->calculate($line, false)['total_cents']);
            $this->assertSame($cents + $difference, (new InvoiceTaxService)->calculate($line, false, true)['total_cents']);
        }
    }

    public function test_tax_assignment_must_be_explicit(): void
    {
        $this->assertFalse((new InvoiceTaxService)->calculate([['cents' => 999]], true)['complete']);
        $this->assertTrue((new InvoiceTaxService)->calculate([['cents' => 999]], false)['complete']);
        $this->expectException(ValidationException::class);
        (new InvoiceTaxService)->calculate([['cents' => 999, 'tax' => ['rate_basis_points' => -1, 'type' => 'sales_tax']]], true);
    }
}
