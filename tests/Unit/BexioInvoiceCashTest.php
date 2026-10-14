<?php

namespace Tests\Unit;

use App\ClubUnio\Services\BexioInvoiceCashService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BexioInvoiceCashTest extends TestCase
{
    public function test_bexio_capped_paid_amount_does_not_hide_additional_cash(): void
    {
        $cash = app(BexioInvoiceCashService::class)->fromPayments([
            ['id' => 12, 'value' => '10.000000', 'kb_invoice_id' => 7, 'kb_credit_voucher_id' => null, 'is_client_account_redemption' => false, 'is_cash_discount' => false],
            ['id' => 13, 'value' => '5.000000', 'kb_invoice_id' => 7, 'kb_credit_voucher_id' => null, 'is_client_account_redemption' => false, 'is_cash_discount' => false],
        ], '7', ['paid_cents' => 1000, 'credit_cents' => 0, 'total_cents' => 1000]);
        $this->assertSame(['cash_received_cents' => 1500, 'cash_excess_cents' => 500], $cash);
    }

    public function test_credit_without_cash_does_not_become_refundable(): void
    {
        $cash = app(BexioInvoiceCashService::class)->fromPayments([
            ['id' => 12, 'value' => '10.00', 'kb_invoice_id' => 7, 'kb_credit_voucher_id' => 11, 'is_client_account_redemption' => false, 'is_cash_discount' => false],
            ['id' => 13, 'value' => '10.00', 'kb_invoice_id' => 7, 'kb_credit_voucher_id' => null, 'is_client_account_redemption' => true, 'is_cash_discount' => false],
        ], '7', ['paid_cents' => 1000, 'credit_cents' => 1000, 'total_cents' => 1000]);
        $this->assertSame(['cash_received_cents' => 0, 'cash_excess_cents' => 0], $cash);
    }

    public function test_ambiguous_or_foreign_payments_are_rejected(): void
    {
        $this->expectException(HttpException::class);
        app(BexioInvoiceCashService::class)->fromPayments([['id' => 12, 'value' => '10.00', 'kb_invoice_id' => 8]], '7', []);
    }
}
