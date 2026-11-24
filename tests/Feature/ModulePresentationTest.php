<?php

namespace Tests\Feature;

use App\ClubUnio\Models\{Invoice, Expense, Member, BexioBalance, MemberStatus};
use App\ClubUnio\Services\ModulePresentation;
use Tests\TestCase;

class ModulePresentationTest extends TestCase
{
    public function test_page_totals_use_loaded_active_lines_and_invoice_rounding(): void
    {
        $one = (new Invoice)->forceFill(['active_lines_sum_net_amount' => '10.01', 'rounding_cents' => -1, 'status_id' => 4]);
        $two = (new Invoice)->forceFill(['active_lines_sum_net_amount' => '20.02', 'rounding_cents' => 3, 'status_id' => 4]);
        $facts = ModulePresentation::financePage(collect([$one, $two]), true);
        $this->assertSame([2, 'CHF 30.05', 1], array_column($facts, 'value'));
        $expense = (new Expense)->forceFill(['active_lines_sum_amount' => '15.25', 'rounding_cents' => 100, 'status_id' => 8]);
        $this->assertSame([1, 'CHF 15.25', 1], array_column(ModulePresentation::financePage(collect([$expense]), false), 'value'));
        $this->assertSame([0, 'CHF 0.00', 0], array_column(ModulePresentation::financePage(collect(), true), 'value'));
    }

    public function test_foreign_tenant_lookup_and_balance_are_never_shown(): void
    {
        $member = (new Member)->forceFill(['tenant_id' => 4, 'member_number' => 'M42']);
        $member->setRelation('status', (new MemberStatus)->forceFill(['tenant_id' => 9, 'name' => 'SECRET']));
        $member->setRelation('type', null);
        $this->assertSame(['M42', '–', '–'], array_column(ModulePresentation::recordFacts($member), 'value'));
        $invoice = (new Invoice)->forceFill(['tenant_id' => 4, 'active_lines_sum_net_amount' => '42.00']);
        $invoice->setRelation('status', null)->setRelation('workflowStatus', null);
        $invoice->setRelation('bexioBalance', (new BexioBalance)->forceFill(['tenant_id' => 9, 'remaining_cents' => 99900]));
        $this->assertSame(trans('module_ui.no_balance'), ModulePresentation::recordFacts($invoice)[3]['value']);
        $invoice->bexioBalance->tenant_id = 4;
        $this->assertSame('CHF 999.00', ModulePresentation::recordFacts($invoice)[3]['value']);
    }

    public function test_release_dates_and_both_languages_are_available(): void
    {
        $expense = (new Expense)->forceFill(['tenant_id' => 4, 'released_on' => '2026-11-24', 'paid_on' => null]);
        $expense->setRelation('status', null);
        $this->assertSame('24.11.2026', ModulePresentation::recordFacts($expense)[2]['value']);
        $de = require lang_path('de/module_ui.php');
        $en = require lang_path('en/module_ui.php');
        $this->assertIsString(json_encode([$de, $en], JSON_THROW_ON_ERROR));
        $this->assertSame(array_keys($de), array_keys($en));
        foreach ($de as $key => $value) {
            preg_match_all('/:\w+/', $value, $left); preg_match_all('/:\w+/', $en[$key], $right);
            $this->assertSame($left[0], $right[0], $key);
        }
    }
}
