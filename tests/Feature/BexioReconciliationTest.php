<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Expense;
use App\ClubUnio\Models\InvoiceLineStatus;
use App\ClubUnio\Models\InvoiceStatus;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Pages\BexioReconciliation;
use App\ClubUnio\Resources\InvoiceStatusResource\Pages\ManageRecords;
use App\ClubUnio\Resources\OpenInvoiceResource\Pages\ListOpenInvoices;
use App\ClubUnio\Resources\PaymentReportResource\Pages\ListPayments;
use App\ClubUnio\Services\BexioConnectionService;
use App\ClubUnio\Services\BexioDocumentClient;
use App\ClubUnio\Services\BexioReconciliationService;
use App\ClubUnio\Services\CatalogService;
use App\ClubUnio\Services\DashboardService;
use App\ClubUnio\Services\FinanceExportService;
use App\ClubUnio\Services\MessageTemplateService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BexioReconciliationTest extends TestCase
{
    private $db;

    private Tenant $tenant;

    private Account $actor;

    private Account $member;

    private int $invoice;

    private int $expense;

    private int $paid;

    private string $bill;

    private BexioReconciliationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Opt-in isolated transaction');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->db->statement('SET LOCAL search_path TO clubora');
        $this->tenant = Tenant::create(['slug' => 'bexio-check-'.Str::random(10), 'name' => 'Test Club']);
        $this->db->table('tenant_settings')->insert(['tenant_id' => $this->tenant->id, 'area' => 'bexio', 'key' => 'BEXIO_ACTIVE', 'label' => 'Bexio aktiv', 'value_type' => 'boolean', 'encrypted_value' => Crypt::encryptString('1')]);
        $this->actor = $this->account('admin');
        $this->member = $this->account('member');
        $member = $this->db->table('members')->insertGetId(['tenant_id' => $this->tenant->id, 'first_name' => 'Test', 'last_name' => 'Member']);
        $this->db->table('account_member_links')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $this->member->id, 'member_id' => $member, 'kind' => 'self', 'state' => 'approved', 'source' => 'test']);
        $open = $this->lookup('invoice_statuses', '7', 'Offen', ['position_code' => 'O']);
        $this->paid = $this->lookup('invoice_statuses', '9', 'Bezahlt', ['position_code' => 'B']);
        $lineOpen = $this->lookup('invoice_line_statuses', 'O', 'Offen');
        $this->lookup('invoice_line_statuses', 'B', 'Bezahlt');
        $released = $this->lookup('expense_statuses', 'Z', 'Zur Zahlung freigegeben');
        $this->lookup('expense_statuses', 'B', 'Bezahlt');
        $this->bill = (string) Str::uuid();
        $this->invoice = $this->db->table('invoices')->insertGetId(['tenant_id' => $this->tenant->id, 'member_id' => $member, 'status_id' => $open, 'bexio_invoice_id' => '123']);
        $this->db->table('invoice_lines')->insert(['tenant_id' => $this->tenant->id, 'invoice_id' => $this->invoice, 'status_id' => $lineOpen, 'net_amount' => '50.00']);
        $this->expense = $this->db->table('expenses')->insertGetId(['tenant_id' => $this->tenant->id, 'member_id' => $member, 'status_id' => $released, 'bexio_bill_id' => $this->bill]);
        $this->db->table('expense_lines')->insert(['tenant_id' => $this->tenant->id, 'expense_id' => $this->expense, 'amount' => '50.00']);
        app(MessageTemplateService::class)->seed($this->tenant);
        $this->service = app(BexioReconciliationService::class);
        $this->actingAs($this->actor, 'clubunio');
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        Filament::setTenant($this->tenant);
        Http::preventStrayRequests();
    }

    private function account(string $role): Account
    {
        $a = new Account;
        $a->forceFill(['username' => Str::random(15).'@example.invalid', 'display_name' => 'Fixture', 'password' => bcrypt('test'), 'active' => true])->save();
        $this->db->table('tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $a->id, 'role' => $role, 'portal_enabled' => true]);

        return $a;
    }

    private function lookup(string $table, string $code, string $name, array $extra = []): int
    {
        return $this->db->table($table)->insertGetId(['tenant_id' => $this->tenant->id, 'code' => $code, 'name' => $name] + $extra);
    }

    private function provider(array $response): void
    {
        $response += isset($response['kb_item_status_id'])
            ? ['total' => '50.000000', 'total_received_payments' => ($response['kb_item_status_id'] ?? 0) === 9 ? '50.00' : '0.00', 'total_credit_vouchers' => '0.00', 'total_remaining_payments' => ($response['kb_item_status_id'] ?? 0) === 9 ? '0.00' : '50.00']
            : ['amount_calc' => 50, 'manual_amount' => false, 'currency_code' => 'CHF'];
        $this->mock(BexioDocumentClient::class)->shouldReceive('read')->andReturn($response);
    }

    public function test_invoice_preview_confirmation_positions_and_deduplicated_notification(): void
    {
        $this->provider(['id' => 123, 'kb_item_status_id' => 9]);
        $id = $this->service->preview($this->actor, $this->tenant, 'invoice', $this->invoice);
        $this->assertNotEquals($this->paid, $this->db->table('invoices')->where('id', $this->invoice)->value('status_id'));
        $this->assertSame(0, $this->db->table('notifications')->where('tenant_id', $this->tenant->id)->count());
        $this->service->apply($this->actor, $this->tenant, $id);
        $this->service->apply($this->actor, $this->tenant, $id);
        $this->assertSame($this->paid, $this->db->table('invoices')->where('id', $this->invoice)->value('status_id'));
        $this->assertSame('applied', $this->db->table('bexio_checks')->where('id', $id)->value('state'));
        $this->assertSame(1, $this->db->table('notifications')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame($this->member->id, $this->db->table('notifications')->where('tenant_id', $this->tenant->id)->value('account_id'));
        $again = $this->service->preview($this->actor, $this->tenant, 'invoice', $this->invoice);
        $this->assertSame('unchanged', $this->db->table('bexio_checks')->where('id', $again)->value('state'));
    }

    public function test_expense_paid_records_existing_message_workflow_once(): void
    {
        $this->provider(['id' => $this->bill, 'status' => 'PAID', 'pending_amount' => '0.00']);
        $one = $this->service->preview($this->actor, $this->tenant, 'expense', $this->expense);
        $two = $this->service->preview($this->actor, $this->tenant, 'expense', $this->expense);
        $this->service->apply($this->actor, $this->tenant, $one);
        $this->assertSame(1, $this->db->table('expense_events')->where('expense_id', $this->expense)->count());
        $this->assertSame('expense.paid', $this->db->table('expense_events')->where('expense_id', $this->expense)->value('event_key'));
        $this->assertSame(1, $this->db->table('notifications')->where('tenant_id', $this->tenant->id)->count());
        $this->expectException(ValidationException::class);
        $this->service->apply($this->actor, $this->tenant, $two);
    }

    public function test_unknown_status_and_contradictory_balance_do_not_update(): void
    {
        $this->provider(['id' => 123, 'kb_item_status_id' => 999]);
        $id = $this->service->preview($this->actor, $this->tenant, 'invoice', $this->invoice);
        $this->assertSame('failed', $this->db->table('bexio_checks')->where('id', $id)->value('state'));
        $this->provider(['id' => $this->bill, 'status' => 'PAID', 'pending_amount' => '5.00']);
        $id = $this->service->preview($this->actor, $this->tenant, 'expense', $this->expense);
        $this->assertSame('failed', $this->db->table('bexio_checks')->where('id', $id)->value('state'));
        $this->assertNull($this->db->table('expenses')->where('id', $this->expense)->value('paid_on'));
    }

    public function test_financial_balance_and_credit_are_checked_and_provider_changes_block_apply(): void
    {
        $this->provider(['id' => 123, 'kb_item_status_id' => 9, 'total_received_payments' => '40.00', 'total_credit_vouchers' => '10.00', 'total_remaining_payments' => '0.00']);
        $check = $this->service->preview($this->actor, $this->tenant, 'invoice', $this->invoice);
        $this->service->apply($this->actor, $this->tenant, $check);
        $this->assertSame(1000, (int) $this->db->table('bexio_balances')->where('object_id', $this->invoice)->value('credit_cents'));
        $this->provider(['id' => 123, 'kb_item_status_id' => 9, 'total_received_payments' => '45.00', 'total_credit_vouchers' => '5.00', 'total_remaining_payments' => '0.00']);
        $check = $this->service->preview($this->actor, $this->tenant, 'invoice', $this->invoice);
        $this->provider(['id' => 123, 'kb_item_status_id' => 9, 'total_received_payments' => '50.00', 'total_credit_vouchers' => '0.00', 'total_remaining_payments' => '0.00']);
        $this->expectException(ValidationException::class);
        $this->service->apply($this->actor, $this->tenant, $check);
    }

    public function test_expired_preview_and_stale_lines_are_rejected(): void
    {
        $this->provider(['id' => 123, 'kb_item_status_id' => 9]);
        $id = $this->service->preview($this->actor, $this->tenant, 'invoice', $this->invoice);
        $this->db->table('bexio_checks')->where('id', $id)->update(['created_at' => now()->subMinutes(11)->toIso8601String()]);
        try {
            $this->service->apply($this->actor, $this->tenant, $id);
            $this->fail('Expired');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('gültig', $e->getMessage());
        }
        $id = $this->service->preview($this->actor, $this->tenant, 'invoice', $this->invoice);
        $this->db->table('invoice_lines')->where('invoice_id', $this->invoice)->update(['net_amount' => 75]);
        $this->expectException(ValidationException::class);
        $this->service->apply($this->actor, $this->tenant, $id);
    }

    public function test_transport_only_get_refresh_once_and_identity_validation(): void
    {
        $this->mock(BexioConnectionService::class)->shouldReceive('accessToken')->with($this->tenant)->once()->andReturn('old')
            ->getMock()->shouldReceive('accessToken')->with($this->tenant, 'old')->once()->andReturn('new');
        Http::fake(['https://api.bexio.com/2.0/kb_invoice/123' => Http::sequence()->push([], 401)->push(['id' => 123, 'kb_item_status_id' => 9])]);
        $data = app(BexioDocumentClient::class)->read($this->tenant, 'invoice', '123');
        $this->assertSame(123, $data['id']);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_web_access_and_cross_tenant_checks(): void
    {
        $this->get('/clubunio/'.$this->tenant->slug.'/bexio-reconciliation')->assertOk();
        Livewire::test(BexioReconciliation::class)->assertSee('Bexio-Abgleich')->assertSee('Status prüfen');
        $this->actingAs($this->member, 'clubunio');
        Livewire::test(BexioReconciliation::class)->assertForbidden();
    }

    public function test_changed_mapping_wrong_document_and_other_actor_are_rejected(): void
    {
        $this->provider(['id' => 456, 'kb_item_status_id' => 9]);
        $id = $this->service->preview($this->actor, $this->tenant, 'invoice', $this->invoice);
        $this->assertSame('failed', $this->db->table('bexio_checks')->where('id', $id)->value('state'));
        $this->provider(['id' => 123, 'kb_item_status_id' => 9]);
        $id = $this->service->preview($this->actor, $this->tenant, 'invoice', $this->invoice);
        $other = $this->account('office');
        try {
            $this->service->apply($other, $this->tenant, $id);
            $this->fail('Other actor');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $foreign = Tenant::create(['slug' => 'foreign-'.Str::random(8), 'name' => 'Other']);
        try {
            $this->service->apply($this->actor, $foreign, $id);
            $this->fail('Other tenant');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->db->table('invoice_statuses')->where('id', $this->paid)->update(['position_code' => 'O']);
        $this->expectException(ValidationException::class);
        $this->service->apply($this->actor, $this->tenant, $id);
    }

    public function test_pending_invoice_does_not_notify(): void
    {
        $pending = $this->lookup('invoice_statuses', '1', 'Pendent', ['position_code' => 'P']);
        $this->lookup('invoice_line_statuses', 'P', 'Pendent');
        $this->provider(['id' => 123, 'kb_item_status_id' => 1]);
        $id = $this->service->preview($this->actor, $this->tenant, 'invoice', $this->invoice);
        $this->service->apply($this->actor, $this->tenant, $id);
        $this->assertSame($pending, $this->db->table('invoices')->where('id', $this->invoice)->value('status_id'));
        $this->assertSame(0, $this->db->table('notifications')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_disabled_sync_never_calls_provider(): void
    {
        $this->db->table('tenant_settings')->where('tenant_id', $this->tenant->id)->where('key', 'BEXIO_ACTIVE')->update(['encrypted_value' => Crypt::encryptString('0')]);
        $this->mock(BexioDocumentClient::class)->shouldNotReceive('read');
        $id = $this->service->preview($this->actor, $this->tenant, 'invoice', $this->invoice);
        $this->assertSame('disabled', $this->db->table('bexio_checks')->where('id', $id)->value('state'));
        Http::assertNothingSent();
    }

    protected function tearDown(): void
    {
        if ($this->db && $this->db->transactionLevel()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    public function test_finance_exports_preserve_selection_escape_formulas_and_scope_access(): void
    {
        $this->db->table('members')->where('tenant_id', $this->tenant->id)->update(['first_name' => '=HYPERLINK("test")', 'last_name' => 'Fixture']);
        $service = app(FinanceExportService::class);
        $query = app(DashboardService::class)->openInvoices($this->actor, $this->tenant);
        $response = $service->download($this->actor, $this->tenant, 'invoices', $query);
        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString('50.00', $csv);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $empty = $service->download($this->actor, $this->tenant, 'invoices', $query->where('bexio_invoice_id', 'missing'));
        ob_start();
        $empty->sendContent();
        $content = ob_get_clean();
        $this->assertStringNotContainsString('Fixture', $content);
        $payments = $service->download($this->actor, $this->tenant, 'payments', Expense::query());
        ob_start();
        $payments->sendContent();
        $content = ob_get_clean();
        $this->assertStringContainsString('IBAN (maskiert)', $content);
        $this->assertStringContainsString('50.00', $content);
        Livewire::test(ListOpenInvoices::class)
            ->callTableAction('export')->assertHasNoTableActionErrors()
            ->assertFileDownloaded('offene-rechnungen-'.now('Europe/Zurich')->format('Y-m-d').'.csv');
        Livewire::test(ListPayments::class)
            ->callTableAction('export')->assertHasNoTableActionErrors()
            ->assertFileDownloaded('spesen-zur-bezahlung-'.now('Europe/Zurich')->format('Y-m-d').'.csv');
        $this->db->table('expenses')->where('id', $this->expense)->update(['status_id' => $this->db->table('expense_statuses')->where('tenant_id', $this->tenant->id)->where('code', 'B')->value('id')]);
        $payments = $service->download($this->actor, $this->tenant, 'payments', Expense::query());
        ob_start();
        $payments->sendContent();
        $content = ob_get_clean();
        $this->assertStringNotContainsString('Fixture', $content);
        $this->expectException(HttpException::class);
        $service->download($this->member, $this->tenant, 'invoices', $query);
    }

    public function test_mapping_editor_validates_scoped_lookups_and_audits_changes(): void
    {
        $catalog = app(CatalogService::class);
        $model = InvoiceStatus::class;
        $record = $catalog->save($this->actor, $this->tenant, $model, null, ['name' => 'Test mapping', 'code' => '100', 'position_code' => 'O', 'active' => true]);
        $catalog->save($this->actor, $this->tenant, $model, $record->id, ['name' => 'Changed mapping', 'position_code' => 'B', 'active' => true]);
        $this->assertSame('B', $record->fresh()->position_code);
        $this->assertSame(2, $this->db->table('object_changes')->where('tenant_id', $this->tenant->id)->where('object_type', 'invoice_statuses')->count());
        foreach (['missing', 'foreign'] as $code) {
            if ($code === 'foreign') {
                $other = Tenant::create(['slug' => 'mapping-'.Str::random(10), 'name' => 'Other']);
                $this->db->table('invoice_line_statuses')->insert(['tenant_id' => $other->id, 'code' => $code, 'name' => 'Other']);
            }
            try {
                $catalog->save($this->actor, $this->tenant, $model, $record->id, ['name' => 'Invalid', 'position_code' => $code, 'active' => true]);
                $this->fail('Invalid lookup accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('position_code', $e->errors());
            }
        }
        $line = InvoiceLineStatus::where('tenant_id', $this->tenant->id)->where('code', 'B')->firstOrFail();
        try {
            $catalog->save($this->actor, $this->tenant, get_class($line), $line->id, ['name' => 'Disabled', 'active' => false]);
            $this->fail('Used target disabled');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('active', $e->errors());
        }
        $this->get('/clubunio/'.$this->tenant->slug.'/invoice-statuses')->assertOk()->assertSee('Changed mapping');
        $this->get('/clubunio/'.$this->tenant->slug.'/invoice-line-statuses')->assertOk()->assertSee('Bezahlt');
        Livewire::test(ManageRecords::class)
            ->callTableAction('edit', $record, data: ['name' => 'Web mapping', 'position_code' => 'O', 'active' => true])->assertHasNoTableActionErrors();
        $this->assertSame('Web mapping', $record->fresh()->name);
        Livewire::test(\App\ClubUnio\Resources\InvoiceLineStatusResource\Pages\ManageRecords::class)
            ->callTableAction('create', data: ['name' => 'Additional', 'code' => 'X', 'active' => true])->assertHasNoTableActionErrors();
        $this->actingAs($this->member, 'clubunio')->get('/clubunio/'.$this->tenant->slug.'/invoice-statuses')->assertForbidden();
        $this->expectException(HttpException::class);
        $catalog->save($this->member, $this->tenant, $model, $record->id, ['name' => 'Denied', 'position_code' => 'B', 'active' => true]);
    }
}
