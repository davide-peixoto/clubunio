<?php

namespace Tests\Feature;

use App\ClubUnio\Services\SponsorRunMoney;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SponsorRunMoneyTest extends TestCase
{
    public function test_exact_chf_conversion_and_display_without_rounding(): void
    {
        foreach (['0.01' => 1, '1' => 100, '1.5' => 150, '1,50' => 150, '12.35' => 1235, '100000.00' => 10000000] as $chf => $cents) {
            $data = SponsorRunMoney::fromWeb(['amount_chf' => (string) $chf, 'cap_chf' => '50.01', 'amount_cents' => 999, 'cap_cents' => 999]);
            $this->assertSame($cents, $data['amount_cents']);
            $this->assertSame(5001, $data['cap_cents']);
            $this->assertArrayNotHasKey('amount_chf', $data);
        }
        $this->assertSame('CHF 1.25', SponsorRunMoney::format(125));
        $this->assertSame("CHF 100'000.00", SponsorRunMoney::format('10000000'));
        $this->assertSame('CHF 0.00', SponsorRunMoney::format(0));
        $this->assertSame('-', SponsorRunMoney::format(null));
        $this->assertNull(SponsorRunMoney::fromWeb(['amount_chf' => '1.25', 'cap_chf' => ''])['cap_cents']);
    }

    public function test_invalid_precision_bounds_and_cents_only_payloads_are_rejected(): void
    {
        foreach (['', '0', '-1', '1.001', '100000.01', '1e2', "1'000.00", '1,000.00', [], true] as $value) {
            try {
                SponsorRunMoney::fromWeb(['amount_chf' => $value]);
                $this->fail('Invalid CHF accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('amount_chf', $e->errors());
            }
        }
        $this->expectException(ValidationException::class);
        SponsorRunMoney::fromWeb(['amount_cents' => 125]);
    }
}
