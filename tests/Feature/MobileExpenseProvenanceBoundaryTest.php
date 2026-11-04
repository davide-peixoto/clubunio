<?php
namespace Tests\Feature;
use App\ClubUnio\Mobile\MobileExpenseOcrProvenance;
use App\ClubUnio\Models\{Account, ExpenseLine, Tenant};
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MobileExpenseProvenanceBoundaryTest extends TestCase
{
    public function test_closed_provenance_does_not_touch_unapplied_schema_or_relabel_rule_suggestions(): void
    {
        config(['clubunio-expense-provenance.enabled' => false]);
        DB::shouldReceive('connection')->never();
        $service = new MobileExpenseOcrProvenance;
        $line = new ExpenseLine; $line->forceFill(['amount' => '12.5000', 'suggested_amount' => '12.5000', 'suggestion_accepted' => true]);
        self::assertSame(['AiBetragUebernommen' => null], $service->fields(new Tenant, $line));
        self::assertNull($service->claim(new Account, new Tenant, []));
        $service->record(new Account, new Tenant, $line, null, false);
        self::assertSame('12.5000', $line->suggested_amount); self::assertTrue($line->suggestion_accepted);
    }
    public function test_claim_is_closed_before_any_database_access(): void
    {
        config(['clubunio-expense-provenance.enabled' => false]); DB::shouldReceive('connection')->never();
        try {
            (new MobileExpenseOcrProvenance)->claim(new Account, new Tenant, ['ocr_work_id' => '00000000-0000-4000-8000-000000000001']);
            self::fail('Closed provenance accepted');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) { self::assertSame(503, $e->getStatusCode()); }
    }
    public function test_fixed_precision_comparison_does_not_use_rule_flags_or_floating_tolerance(): void
    {
        self::assertSame(MobileExpenseOcrProvenance::units('12.5'), MobileExpenseOcrProvenance::units('12.5000'));
        self::assertNotSame(MobileExpenseOcrProvenance::units('12.5001'), MobileExpenseOcrProvenance::units('12.5000'));
        self::assertSame(9999999999999, MobileExpenseOcrProvenance::units('999999999.9999'));
        foreach (['NaN', '-1', '1e2', '1.00001', '1000000000', '1,50'] as $invalid) {
            try { MobileExpenseOcrProvenance::units($invalid); self::fail('Invalid amount accepted'); }
            catch (\InvalidArgumentException) { self::addToAssertionCount(1); }
        }
    }
}
