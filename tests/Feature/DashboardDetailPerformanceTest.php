<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\DashboardService;
use App\ClubUnio\Services\InvoiceDetailTotals;
use App\ClubUnio\Widgets\MembersByStatus;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DashboardDetailPerformanceTest extends TestCase
{
    private $db;

    private Tenant $tenant;

    private Tenant $foreign;

    private Account $actor;

    private int $member;

    private Invoice $invoice;

    private array $statuses = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Transactional database opt-in required');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->tenant = Tenant::create(['slug' => 'detail-'.Str::random(12), 'name' => 'Synthetic']);
        $this->foreign = Tenant::create(['slug' => 'foreign-'.Str::random(12), 'name' => 'Foreign']);
        $this->actor = Account::forceCreate(['username' => Str::random(20), 'display_name' => 'Synthetic', 'password' => bcrypt('fixture'), 'active' => true]);
        $this->insert('tenant_accounts', ['account_id' => $this->actor->id, 'role' => 'office']);
        $this->member = $this->insert('members', ['first_name' => 'Synthetic']);
        $this->invoice = Invoice::findOrFail($this->insert('invoices', ['member_id' => $this->member]));
        foreach (['draft', 'released', 'confirmed', 'cancelled'] as $code) {
            $this->statuses[$code] = $this->insert('financial_adjustment_statuses', ['code' => $code, 'name' => $code]);
        }
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $this->actingAs($this->actor, 'clubunio');
        Filament::setTenant($this->tenant);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->transactionLevel()) {
            $this->db->disableQueryLog();
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    private function insert(string $table, array $data): int
    {
        return $this->db->table('clubora.'.$table)->insertGetId($data + ['tenant_id' => $this->tenant->id]);
    }

    private function adjustment(string $table, string $state, int $cents, array $data = []): int
    {
        return $this->insert($table, $data + ['invoice_id' => $this->invoice->id,
            'status_id' => $this->statuses[$state], 'amount_cents' => $cents, 'reason' => 'Synthetic',
            'request_id' => (string) Str::uuid(), 'request_hash' => 'synthetic', 'created_by' => $this->actor->id]
            + ($table === 'credit_notes' ? ['member_id' => $this->member, 'included_tax' => 0, 'source_snapshot' => '{}'] : ['entitlement_snapshot' => '{}']));
    }

    public function test_overview_counts_are_batched_scoped_and_keep_membership_rules(): void
    {
        $ended = $this->insert('member_statuses', ['name' => 'Departed', 'code' => 'fixture', 'ends_membership' => true]);
        $this->insert('members', ['first_name' => 'Departed', 'status_id' => $ended]);
        $this->insert('members', ['first_name' => 'Exited', 'exit_date' => now()->subDay()]);
        $this->insert('members', ['first_name' => 'Archived', 'archived_at' => now()]);
        $this->insert('members', ['first_name' => 'Foreign', 'tenant_id' => $this->foreign->id]);
        $review = $this->insert('registration_statuses', ['name' => 'Review', 'code' => 'review', 'workflow_key' => 'review']);
        $this->insert('registrations', ['legacy_id' => 1, 'status_id' => $review, 'active' => true]);
        $this->insert('registrations', ['legacy_id' => 2, 'status_id' => $review, 'active' => false]);
        foreach (['F', 'Z'] as $code) {
            $status = $this->insert('expense_statuses', ['name' => $code, 'code' => $code]);
            $this->insert('expenses', ['member_id' => $this->member, 'status_id' => $status, 'active' => true]);
            $this->insert('expenses', ['member_id' => $this->member, 'status_id' => $status, 'active' => false]);
        }
        $this->adjustment('credit_notes', 'draft', 100);
        $this->adjustment('credit_notes', 'released', 100);
        $this->adjustment('credit_notes', 'cancelled', 100);
        $this->adjustment('refunds', 'draft', 100);
        $this->adjustment('refunds', 'confirmed', 100);
        $this->db->enableQueryLog();
        $this->db->flushQueryLog();
        $expectedOpen = app(DashboardService::class)->openSummary($this->actor, $this->tenant);
        $this->db->flushQueryLog();
        $this->assertSame(['members' => 1, 'changes' => 0, 'registrations' => 1,
            'expenses_review' => 1, 'expenses_payment' => 1, 'training_slots' => 0, 'credits' => 2, 'refunds' => 1,
            'open_count' => $expectedOpen['count'], 'open_amount' => (string) $expectedOpen['amount'], 'refundable_cents' => 0],
            app(DashboardService::class)->overview($this->actor, $this->tenant));
        $this->assertCount(3, $this->db->getQueryLog());
        $this->db->disableQueryLog();
        $this->insert('members', ['first_name' => 'New']);
        $this->assertSame(2, app(DashboardService::class)->overview($this->actor, $this->tenant)['members']);
    }

    public function test_dashboard_counts_reject_foreign_tenant_and_revoked_role(): void
    {
        try {
            app(DashboardService::class)->overview($this->actor, $this->foreign);
            $this->fail('Expected tenant denial');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->db->table('clubora.tenant_accounts')->where('account_id', $this->actor->id)->update(['role' => 'trainer']);
        $this->expectException(HttpException::class);
        app(DashboardService::class)->overview($this->actor, $this->tenant);
    }

    public function test_batched_overview_keeps_decimal_balances_and_refund_reservations(): void
    {
        $status = $this->insert('invoice_line_statuses', ['name' => 'Open', 'code' => 'O']);
        $this->insert('invoice_lines', ['invoice_id' => $this->invoice->id, 'status_id' => $status, 'net_amount' => '12.75', 'gross_amount' => '12.75']);
        $cashInvoice = $this->insert('invoices', ['member_id' => $this->member]);
        $this->insert('bexio_balances', ['object_type' => 'invoice', 'object_id' => $cashInvoice, 'remote_id' => 'synthetic', 'remote_status' => '9',
            'total_cents' => 1000, 'paid_cents' => 2000, 'credit_cents' => 0, 'remaining_cents' => 0, 'overpaid_cents' => 1000,
            'cash_excess_cents' => 1000, 'cash_received_cents' => 2000, 'observed_at' => now()]);
        $this->adjustment('refunds', 'draft', 250, ['invoice_id' => $cashInvoice]);
        $this->adjustment('refunds', 'cancelled', 500, ['invoice_id' => $cashInvoice]);
        $service = app(DashboardService::class);
        $overview = $service->overview($this->actor, $this->tenant);
        $this->assertSame(1, $overview['open_count']);
        $this->assertEquals(12.75, $overview['open_amount']);
        $this->assertSame(750, $overview['refundable_cents']);
        $this->assertSame($service->refundableCash($this->actor, $this->tenant), $overview['refundable_cents']);
        $this->assertSame((string) $service->openSummary($this->actor, $this->tenant)['amount'], $overview['open_amount']);
    }

    public function test_invoice_display_totals_use_one_query_and_refresh_after_changes(): void
    {
        $this->adjustment('credit_notes', 'draft', 100);
        $this->adjustment('credit_notes', 'released', 200);
        $this->adjustment('credit_notes', 'confirmed', 800);
        $this->adjustment('credit_notes', 'draft', 900, ['tenant_id' => $this->foreign->id]);
        $this->adjustment('refunds', 'draft', 100);
        $refund = $this->adjustment('refunds', 'confirmed', 400);
        $this->adjustment('refunds', 'cancelled', 900);
        $this->adjustment('refunds', 'draft', 900, ['tenant_id' => $this->foreign->id]);
        $service = app(InvoiceDetailTotals::class);
        $this->db->enableQueryLog();
        $this->db->flushQueryLog();
        for ($i = 0; $i < 4; $i++) {
            $this->assertSame(['pending_credits' => 300, 'pending_refunds' => 100, 'confirmed_refunds' => 400, 'reserved_refunds' => 500], $service->for($this->invoice));
        }
        $this->assertCount(1, $this->db->getQueryLog());
        $this->db->disableQueryLog();
        $this->db->table('clubora.refunds')->where('id', $refund)->update(['amount_cents' => 450]);
        $this->assertSame(450, $service->for($this->invoice->refresh())['confirmed_refunds']);
        $empty = Invoice::findOrFail($this->insert('invoices', ['member_id' => $this->member]));
        $this->assertSame([0, 0, 0, 0], array_values($service->for($empty)));
    }

    public function test_detail_pages_keep_foreign_record_and_revoked_role_guards(): void
    {
        $this->get('/invoices/'.$this->invoice->id)->assertOk();
        $foreignMember = $this->insert('members', ['first_name' => 'Foreign', 'tenant_id' => $this->foreign->id]);
        $foreignInvoice = $this->insert('invoices', ['tenant_id' => $this->foreign->id, 'member_id' => $foreignMember]);
        $this->get('/invoices/'.$foreignInvoice)->assertNotFound();
        $this->db->table('clubora.tenant_accounts')->where('account_id', $this->actor->id)->update(['role' => 'trainer']);
        $this->get('/invoices/'.$this->invoice->id)->assertForbidden();
        $this->get('/members/'.$this->member)->assertForbidden();
    }

    public function test_status_charts_join_labels_without_losing_missing_or_inactive_statuses(): void
    {
        $status = $this->insert('member_statuses', ['name' => 'Fixture Member', 'code' => 'fixture', 'active' => false]);
        $this->insert('members', ['first_name' => 'With status', 'status_id' => $status]);
        $this->insert('members', ['first_name' => 'Archived', 'status_id' => $status, 'archived_at' => now()]);
        $foreignStatus = $this->insert('member_statuses', ['tenant_id' => $this->foreign->id, 'name' => 'Foreign', 'code' => 'foreign']);
        $this->insert('members', ['tenant_id' => $this->foreign->id, 'first_name' => 'Foreign', 'status_id' => $foreignStatus]);
        $widget = new MembersByStatus;
        $this->db->enableQueryLog();
        $this->db->flushQueryLog();
        $data = (new \ReflectionMethod($widget, 'getData'))->invoke($widget);
        $chartQueries = array_filter($this->db->getQueryLog(), fn ($query) => str_contains($query['query'], 'dashboard_status'));
        $this->assertCount(1, $chartQueries);
        $counts = array_combine($data['labels'], $data['datasets'][0]['data']);
        $this->assertEquals(['Fixture Member' => 1, 'Ohne Status' => 1], $counts);
        $this->assertNotContains('Foreign', $data['labels']);
    }
}
