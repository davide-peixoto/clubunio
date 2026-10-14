<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\BexioCatalogEntry;
use App\ClubUnio\Models\Expense;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\InvoiceCategory;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Pages\BexioDispatchTrials;
use App\ClubUnio\Pages\BexioSandbox;
use App\ClubUnio\Resources\ExpenseResource;
use App\ClubUnio\Resources\InvoiceResource;
use App\ClubUnio\Resources\InvoiceResource\Pages\ListInvoices;
use App\ClubUnio\Services\AzureDocumentReader;
use App\ClubUnio\Services\BexioCatalogFixtureService;
use App\ClubUnio\Services\BexioCatalogService;
use App\ClubUnio\Services\BexioConnectionService;
use App\ClubUnio\Services\BexioDispatchTrialService;
use App\ClubUnio\Services\BexioFinancialActionService;
use App\ClubUnio\Services\BexioGeneralSandboxFixtureService;
use App\ClubUnio\Services\BexioInvoiceActionService;
use App\ClubUnio\Services\BexioReconciliationService;
use App\ClubUnio\Services\BexioSandboxClient;
use App\ClubUnio\Services\BexioSandboxDispatchService;
use App\ClubUnio\Services\BexioSandboxFixtureService;
use App\ClubUnio\Services\CatalogService;
use App\ClubUnio\Services\DashboardService;
use App\ClubUnio\Services\FinanceExportService;
use App\ClubUnio\Services\InvoiceTaxService;
use App\ClubUnio\Services\InvoiceWorkflowService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BexioSandboxDispatchTest extends TestCase
{
    public function test_taxed_invoice_cannot_release_without_valid_explicit_tax(): void
    {
        $category = app(CatalogService::class)->save($this->actor, $this->tenant, InvoiceCategory::class,
            null, ['name' => 'Dressbezug', 'code' => 'kit', 'active' => true, 'sort_order' => 0]);
        $this->db->table('clubora.invoice_line_statuses')->insert(['tenant_id' => $this->tenant->id, 'code' => 'P', 'name' => 'Pendent']);
        $this->db->table('clubora.invoice_workflow_statuses')->insert(['tenant_id' => $this->tenant->id, 'code' => 'released', 'name' => 'Freigegeben']);
        $memberId = $this->db->table('clubora.bexio_sandbox_fixtures')->where('tenant_id', $this->tenant->id)->value('member_id');
        $workflow = app(InvoiceWorkflowService::class);
        $invoice = $workflow->create($this->actor, $this->tenant, ['member_id' => $memberId, 'invoice_category_id' => $category->id, 'tax_mode' => 'inclusive']);
        $invoice = $workflow->change($this->actor, $this->tenant, $invoice->id, $invoice->revision, 'line',
            ['description' => 'Dress', 'gross_amount' => '99.99', 'discount' => '0']);
        try {
            $workflow->change($this->actor, $this->tenant, $invoice->id, $invoice->revision, 'release');
            $this->fail('Invoice without tax released');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('revision', $error->errors());
        }
        $this->assertSame('draft', $invoice->fresh()->workflowStatus->code);
        $row = new BexioCatalogEntry;
        $row->forceFill(['tenant_id' => $this->tenant->id, 'connection_hash' => app(BexioSandboxFixtureService::class)->connectionHash($this->tenant),
            'kind' => 'tax', 'remote_id' => 16, 'code' => 'US38', 'name' => 'Inaktiv', 'provider_name' => 'Inaktiv',
            'active' => true, 'provider_active' => false, 'rate_basis_points' => 380, 'tax_type' => 'sales_tax', 'provider_data' => [], 'observed_at' => now()])->save();
        $this->expectException(ValidationException::class);
        app(InvoiceTaxService::class)->snapshot($this->tenant, $row->id, now()->toDateString());
    }

    public function test_general_invoice_categories_mixed_taxes_and_paid_balance(): void
    {
        $fixtures = $this->generalFixtures();
        $category = app(CatalogService::class)->save($this->actor, $this->tenant, InvoiceCategory::class,
            null, ['name' => 'Merchandising', 'code' => 'merchandise', 'active' => true, 'sort_order' => 0]);
        foreach (['P', 'E', 'O', 'T', 'B', 'S'] as $code) {
            $this->db->table('clubora.invoice_line_statuses')->insert(['tenant_id' => $this->tenant->id, 'code' => $code, 'name' => $code]);
        }
        foreach ([7 => 'E', 8 => 'O', 16 => 'T', 9 => 'B', 19 => 'S'] as $status => $code) {
            $this->db->table('clubora.invoice_statuses')->insert(['tenant_id' => $this->tenant->id, 'code' => $status, 'name' => $code, 'position_code' => $code]);
        }
        $hash = app(BexioSandboxFixtureService::class)->connectionHash($this->tenant);
        $taxes = [];
        foreach ([14 => ['UN81', 810, 'sales_tax'], 15 => ['UR26', 260, 'sales_tax'], 3 => ['UEX', 0, 'not_taxable_turnover']] as $remote => [$code, $rate, $type]) {
            $row = new BexioCatalogEntry;
            $row->forceFill(['tenant_id' => $this->tenant->id, 'connection_hash' => $hash, 'kind' => 'tax', 'remote_id' => $remote,
                'code' => $code, 'name' => $code, 'provider_name' => $code, 'rate_basis_points' => $rate, 'tax_type' => $type,
                'provider_active' => true, 'active' => true, 'provider_data' => [], 'observed_at' => now()])->save();
            $taxes[$remote] = $row->id;
        }
        $workflow = app(InvoiceWorkflowService::class);
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $this->actingAs($this->actor, 'clubunio');
        Filament::setTenant($this->tenant);
        Livewire::test(ListInvoices::class)
            ->callAction('createInvoice', data: ['member_id' => Invoice::find($fixtures['invoice'])->member_id,
                'invoice_category_id' => $category->id, 'tax_mode' => 'inclusive', 'notes' => 'Synthetic invoice'])->assertHasNoActionErrors();
        $invoice = Invoice::where('tenant_id', $this->tenant->id)->where('notes', 'Synthetic invoice')->firstOrFail();
        foreach ([[14, '12.00', '2.00'], [15, '10.26', '0.00'], [3, '20.01', '0.00']] as [$tax, $gross, $discount]) {
            $invoice = $workflow->change($this->actor, $this->tenant, $invoice->id, $invoice->revision, 'line', [
                'description' => 'Synthetic position', 'gross_amount' => $gross, 'discount' => $discount, 'bexio_catalog_tax_id' => $taxes[$tax]]);
        }
        $this->assertSame(-2, $invoice->rounding_cents);
        $summary = app(InvoiceTaxService::class)->summary($invoice, true);
        $this->assertSame(4025, $summary['total_cents']);
        $this->assertSame('1.008805', $summary['tax']);
        $invoice = $workflow->change($this->actor, $this->tenant, $invoice->id, $invoice->revision, 'release');
        $trial = app(BexioDispatchTrialService::class)->preview($this->actor, $this->tenant, 'invoice', $invoice->id);
        $dispatch = app(BexioSandboxDispatchService::class);
        $id = $dispatch->prepareTrial($this->actor, $this->tenant, $trial);
        $dispatch->send($this->actor, $this->tenant, $id);
        $this->assertSame('accepted', $this->row($id)->state);
        $this->assertSame(0, $this->invoice['mwst_type']);
        $this->assertFalse($this->invoice['mwst_is_net']);
        $this->assertSame([14, 15, 3], array_column($this->invoice['positions'], 'tax_id'));
        app(BexioInvoiceActionService::class)->execute($this->actor, $this->tenant, $invoice->id, 'issue');
        app(BexioFinancialActionService::class)->execute($this->actor, $this->tenant, 'invoice', $invoice->id, 'payment_full');
        $this->assertSame('9', $invoice->fresh()->status->code);
        $balance = $this->db->table('clubora.bexio_balances')->where('tenant_id', $this->tenant->id)->where('object_id', $invoice->id)->first();
        $this->assertSame(4025, $balance->paid_cents);
        $this->assertSame(0, $balance->remaining_cents);
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $this->actingAs($this->actor, 'clubunio');
        Filament::setTenant($this->tenant);
        $this->get('/clubunio/'.$this->tenant->slug.'/invoices')->assertOk()->assertSee('Rechnung erstellen');
        $this->get('/clubunio/'.$this->tenant->slug.'/invoices/'.$invoice->id)->assertOk()->assertSee('Steuerbehandlung');
    }

    public function test_test_article_timeout_reconciles_without_second_post(): void
    {
        $articles = [];
        $fixture = BexioCatalogFixtureService::class;
        $client = BexioSandboxClient::class;
        $this->mock($client, function ($mock) use (&$articles) {
            $mock->shouldReceive('verify')->with('synthetic-token');
            $mock->shouldReceive('createTestArticle')->once()->andReturn(['state' => 'uncertain', 'id' => null, 'http_status' => 504]);
            $mock->shouldReceive('get')->andReturnUsing(function ($token, $path, $query = []) use (&$articles) {
                return match ($path) {
                    '/3.0/taxes' => [['id' => 3, 'code' => 'UEX', 'is_active' => true, 'type' => 'not_taxable_turnover', 'value' => 0],
                        ['id' => 7, 'code' => 'V00', 'is_active' => true, 'type' => 'pre_tax_investment', 'value' => 0]],
                    '/2.0/article' => $articles,
                    '/2.0/article/42' => $articles[0],
                    '/3.0/users/me' => ['id' => 1],
                    '/3.0/currencies' => [['id' => 1, 'name' => 'CHF']],
                    '/2.0/unit' => [['id' => 1, 'name' => 'Stk']],
                    '/2.0/accounts' => [['id' => 1, 'account_no' => '3200']],
                    default => throw new \LogicException('Unexpected fixture path'),
                };
            });
        });
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                app($fixture)->create($this->actor, $this->tenant);
                $this->fail('Unconfirmed fixture accepted');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
        }
        $this->assertSame(1, $this->db->table('clubora.bexio_catalog_fixture_actions')->where('tenant_id', $this->tenant->id)->count());
        $articles = [['id' => 42, 'intern_code' => $fixture::CODE, 'intern_name' => 'ClubUnio Sandbox Testartikel',
            'remarks' => $fixture::MARKER, 'article_type_id' => 2, 'is_stock' => false]];
        $this->assertSame(42, app($fixture)->create($this->actor, $this->tenant));
        $this->assertSame(42, app($fixture)->create($this->actor, $this->tenant));
        $this->assertSame('accepted', $this->db->table('clubora.bexio_catalog_fixture_actions')->where('tenant_id', $this->tenant->id)->value('state'));
        $this->db->table('clubora.invoice_line_statuses')->insert(['tenant_id' => $this->tenant->id, 'code' => 'P', 'name' => 'Pendent']);
        $this->db->table('clubora.invoice_workflow_statuses')->insert(['tenant_id' => $this->tenant->id, 'code' => 'released', 'name' => 'Freigegeben']);
        $invoiceId = app($fixture)->prepareInvoice($this->actor, $this->tenant);
        $this->assertSame($invoiceId, app($fixture)->prepareInvoice($this->actor, $this->tenant));
        $invoice = Invoice::findOrFail($invoiceId);
        $this->assertSame('released', $invoice->workflowStatus->code);
        $this->assertSame(42, (int) $invoice->activeLines()->firstOrFail()->bexio_product_id);
        $this->assertSame('10.0000', $invoice->activeLines()->firstOrFail()->net_amount);
    }

    private function catalogProvider(array &$articles, array &$taxes): void
    {
        $this->mock(BexioSandboxClient::class, function ($mock) use (&$articles, &$taxes) {
            $mock->shouldReceive('verify')->with('synthetic-token');
            $mock->shouldReceive('get')->andReturnUsing(function ($token, $path, $query = []) use (&$articles, &$taxes) {
                return match ($path) {
                    '/3.0/taxes' => $taxes,
                    '/2.0/article' => array_slice($articles, $query['offset'] ?? 0, $query['limit'] ?? 500),
                    '/2.0/article/42' => $articles[0],
                    default => throw new \LogicException('Unexpected catalog call'),
                };
            });
        });
    }

    public function test_catalog_article_is_assigned_through_invoice_workflow_and_foreign_ids_rejected(): void
    {
        $articles = [['id' => 42, 'intern_code' => 'TEST', 'intern_name' => 'Testartikel']];
        $taxes = [];
        $this->catalogProvider($articles, $taxes);
        $service = app(BexioCatalogService::class);
        $service->sync($this->actor, $this->tenant);
        $entry = BexioCatalogEntry::where('tenant_id', $this->tenant->id)->firstOrFail();
        $invoiceId = $this->db->table('clubora.bexio_sandbox_fixtures')->where('tenant_id', $this->tenant->id)->value('invoice_id');
        $invoice = Invoice::findOrFail($invoiceId);
        $invoice->forceFill(['workflow_status_id' => $this->db->table('clubora.invoice_workflow_statuses')->where('tenant_id', $this->tenant->id)->value('id')])->save();
        $this->db->table('clubora.invoice_line_statuses')->insert(['tenant_id' => $this->tenant->id, 'code' => 'P', 'name' => 'Pendent']);
        $data = ['description' => 'Artikelposition', 'gross_amount' => '12.00', 'discount' => '2.00', 'bexio_catalog_article_id' => $entry->id];
        app(InvoiceWorkflowService::class)->change($this->actor, $this->tenant, $invoice->id, $invoice->revision, 'line', $data);
        $line = $invoice->activeLines()->firstOrFail();
        $this->assertSame($entry->id, $line->bexio_catalog_article_id);
        $this->assertSame(42, (int) $line->bexio_product_id);
        $this->assertSame('10.0000', $line->net_amount);
        $articles[0]['intern_name'] = 'Changed remotely';
        try {
            $service->verifyRemoteArticles($this->tenant, 'synthetic-token', [['article_id' => 42]]);
            $this->fail('Changed provider article accepted');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $entry->forceFill(['connection_hash' => 'foreign-connection'])->save();
        $this->expectException(HttpException::class);
        app(InvoiceWorkflowService::class)->change($this->actor, $this->tenant, $invoice->id, $invoice->fresh()->revision, 'line', $data);
    }

    public function test_catalog_sync_preserves_customization_and_rejects_stale_edits(): void
    {
        $articles = [['id' => 42, 'intern_code' => 'TEST', 'intern_name' => 'Testartikel', 'unit_id' => 1]];
        $taxes = [['id' => 14, 'code' => 'UN81', 'type' => 'sales_tax', 'value' => 8.1, 'is_active' => true, 'start_year' => 2023, 'start_month' => 7],
            ['id' => 7, 'code' => 'V00', 'type' => 'pre_tax_investment', 'value' => 0, 'is_active' => true]];
        $this->catalogProvider($articles, $taxes);
        $service = app(BexioCatalogService::class);
        $this->assertSame(2, $service->sync($this->actor, $this->tenant));
        $model = BexioCatalogEntry::where('tenant_id', $this->tenant->id);
        $tax = (clone $model)->where('kind', 'tax')->firstOrFail();
        $this->assertSame(810, $tax->rate_basis_points);
        $this->assertSame('2023-07-01', $tax->valid_from->toDateString());
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $this->actingAs($this->actor, 'clubunio');
        Filament::setTenant($this->tenant);
        $this->actingAs($this->actor, 'clubunio')->get('/clubunio/'.$this->tenant->slug.'/bexio-catalog-entries')
            ->assertOk()->assertSee('Bexio-Artikel und Steuern')->assertSee('Testartikel');
        $entry = (clone $model)->where('kind', 'article')->firstOrFail();
        $this->assertSame([$entry->id => 'Testartikel'], $service->articleOptions($this->tenant));
        $service->verifyRemoteArticles($this->tenant, 'synthetic-token', [['article_id' => 42]]);
        $service->edit($this->actor, $this->tenant, $entry->id, ['name' => 'Eigene Bezeichnung', 'active' => false, 'revision' => 1]);
        $this->assertSame(2, $service->sync($this->actor, $this->tenant));
        $this->assertSame('Eigene Bezeichnung', $entry->fresh()->name);
        $this->assertFalse($entry->fresh()->active);
        $this->assertSame([], $service->articleOptions($this->tenant));
        $this->expectException(ValidationException::class);
        $service->edit($this->actor, $this->tenant, $entry->id, ['name' => 'Veraltet', 'active' => true, 'revision' => 1]);
    }

    public function test_catalog_import_is_atomic_and_missing_articles_are_disabled(): void
    {
        $articles = [['id' => 42, 'intern_code' => 'TEST', 'intern_name' => 'Testartikel']];
        $taxes = [];
        $this->catalogProvider($articles, $taxes);
        $service = app(BexioCatalogService::class);
        $service->sync($this->actor, $this->tenant);
        $articles = [];
        $service->sync($this->actor, $this->tenant);
        $entry = BexioCatalogEntry::where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->assertFalse($entry->provider_active);
        $taxes = [['id' => 14, 'code' => 'UN81', 'type' => 'sales_tax', 'value' => '8.1bad', 'is_active' => true]];
        try {
            $service->sync($this->actor, $this->tenant);
            $this->fail('Malformed tax accepted');
        } catch (HttpException $error) {
            $this->assertSame(502, $error->getStatusCode());
        }
        $this->assertSame(1, BexioCatalogEntry::where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(2, $entry->fresh()->revision);
    }

    public function test_catalog_is_admin_only_and_connection_bound(): void
    {
        $articles = [];
        $taxes = [];
        $this->catalogProvider($articles, $taxes);
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)->update(['role' => 'trainer']);
        try {
            app(BexioCatalogService::class)->sync($this->actor, $this->tenant);
            $this->fail('Trainer imported provider catalog');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)->update(['role' => 'admin']);
        $this->connectedAt = '2026-10-15';
        $this->expectException(HttpException::class);
        app(BexioCatalogService::class)->sync($this->actor, $this->tenant);
    }

    private $db;

    private Tenant $tenant;

    private Account $actor;

    private array $invoice = [];

    private array $bill = [];

    private int $postStatus = 201;

    private int $uploadStatus = 200;

    private string $connectedAt = '2026-10-14';

    private string $company = 'SVS Api Test';

    private string $billStatus = 'DRAFT';

    private int $invoiceStatus = 7;

    private bool $actionTimeout = false;

    private array $invoicePayments = [];

    private array $billPayments = [];

    private bool $financeTimeout = false;

    private string $fileId = 'b4c6ba83-19d9-4674-9084-ae9d52d8e7e0';

    private string $billId = 'a4c6ba83-19d9-4674-9084-ae9d52d8e7e1';

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Opt-in rolled-back DB tests');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->tenant = Tenant::create(['slug' => 'dispatch-'.bin2hex(random_bytes(6)), 'name' => 'Sandbox fixture']);
        $this->actor = new Account;
        $this->actor->forceFill(['username' => bin2hex(random_bytes(8)), 'display_name' => 'Test admin', 'active' => true, 'password' => bcrypt('test')])->save();
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $this->actor->id, 'role' => 'admin']);
        $this->db->table('clubora.invoice_workflow_statuses')->insert(['tenant_id' => $this->tenant->id, 'code' => 'draft', 'name' => 'Entwurf']);
        $this->db->table('clubora.expense_statuses')->insert(['tenant_id' => $this->tenant->id, 'code' => 'O', 'name' => 'Offen']);
        $this->mock(BexioConnectionService::class, function ($mock) {
            $mock->shouldReceive('connection')->andReturnUsing(fn () => ['fingerprint' => 'fixture', 'connected_at' => $this->connectedAt]);
            $mock->shouldReceive('accessToken')->andReturn('synthetic-token');
        });
        $member = new Member;
        $member->forceFill(['tenant_id' => $this->tenant->id, 'first_name' => 'Synthetic', 'bexio_contact_id' => 2])->save();
        $supplier = new Member;
        $supplier->forceFill(['tenant_id' => $this->tenant->id, 'first_name' => 'Supplier', 'bexio_contact_id' => 4])->save();
        $invoice = new Invoice;
        $invoice->forceFill(['tenant_id' => $this->tenant->id, 'member_id' => $member->id])->save();
        $this->db->table('clubora.bexio_sandbox_fixtures')->insert(['tenant_id' => $this->tenant->id,
            'member_id' => $member->id, 'parent_id' => $member->id, 'supplier_id' => $supplier->id,
            'invoice_id' => $invoice->id, 'remote_invoice_id' => 1, 'remote_contact_id' => 2,
            'connection_hash' => app(BexioSandboxFixtureService::class)->connectionHash($this->tenant)]);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'PUT' && str_ends_with($path, '/bookings/BOOKED')) {
                $this->billStatus = 'BOOKED';

                return Http::response(['id' => $this->billId, 'status' => 'BOOKED']);
            }
            if ($request->method() === 'POST') {
                if ($path === '/2.0/kb_invoice/123/payment') {
                    if ($this->financeTimeout) {
                        return Http::response([], 504);
                    }
                    $record = $request->data() + ['id' => count($this->invoicePayments) + 101, 'kb_invoice_id' => 123, 'kb_credit_voucher_id' => null];
                    $this->invoicePayments[] = $record;
                    $this->invoiceStatus = array_sum(array_column($this->invoicePayments, 'value')) === $this->invoiceData()['total'] ? 9 : 16;

                    return Http::response($record, 201);
                }
                if ($path === '/4.0/purchase/outgoing-payments') {
                    $record = $request->data() + ['id' => 'a4c6ba83-19d9-4674-9084-ae9d52d8e7e9', 'status' => 'PAID', 'banking_payment_id' => null];
                    $this->billPayments[] = $record;
                    $this->billStatus = 'PAID';

                    return Http::response($record, 201);
                }
                if (preg_match('~/kb_invoice/123/(issue|cancel)$~', $path, $match)) {
                    if ($this->actionTimeout) {
                        return Http::response([], 504);
                    }
                    $this->invoiceStatus = $match[1] === 'issue' ? 8 : 19;

                    return Http::response(['success' => true]);
                }
                if ($path === '/3.0/files') {
                    return Http::response([['uuid' => $this->fileId]], $this->uploadStatus);
                }
                if ($path === '/2.0/kb_invoice') {
                    $this->invoice = $request->data();

                    return Http::response(['id' => 123], $this->postStatus);
                }
                if ($path === '/4.0/purchase/bills') {
                    $this->bill = $request->data();

                    return Http::response(['id' => $this->billId], $this->postStatus);
                }

                return Http::response([], 500);
            }

            return match ($path) {
                '/2.0/company_profile' => Http::response([['id' => 1, 'name' => $this->company]]),
                '/3.0/users/me' => Http::response(['id' => 1]),
                '/3.0/files' => Http::response([]),
                '/2.0/kb_invoice/1' => Http::response(['api_reference' => BexioSandboxFixtureService::REFERENCE, 'contact_id' => 2]),
                '/2.0/contact/2' => Http::response(['name_1' => 'Clubora Sandbox Member v1', 'remarks' => 'clubora-sandbox-fixture:v1:member']),
                '/2.0/contact/3' => Http::response(['name_1' => 'Clubora Sandbox Parent v1', 'remarks' => 'clubora-sandbox-fixture:v1:parent']),
                '/2.0/contact/4' => Http::response(['name_1' => 'Clubora Sandbox Supplier v1', 'remarks' => 'clubora-sandbox-fixture:v1:supplier']),
                '/2.0/language' => Http::response([['id' => 1, 'name' => 'Deutsch']]),
                '/3.0/currencies' => Http::response([['id' => 1, 'name' => 'CHF']]),
                '/3.0/banking/accounts' => Http::response([['id' => 1, 'name' => 'Example Bank', 'type' => 'bank']]),
                '/2.0/payment_type' => Http::response([['id' => 4, 'name' => 'Rechnung']]),
                '/2.0/unit' => Http::response([['id' => 1, 'name' => 'Stk']]),
                '/2.0/accounts' => Http::response([['id' => 101, 'account_no' => '3200'], ['id' => 99, 'account_no' => '4200']]),
                '/3.0/taxes' => Http::response([['id' => 14, 'code' => 'UN81', 'type' => 'sales_tax', 'value' => 8.1, 'is_active' => true],
                    ['id' => 15, 'code' => 'UR26', 'type' => 'sales_tax', 'value' => 2.6, 'is_active' => true],
                    ['id' => 3, 'code' => 'UEX', 'type' => 'not_taxable_turnover', 'value' => 0, 'is_active' => true]]),
                '/2.0/kb_invoice/123' => Http::response($this->invoiceData()),
                '/2.0/kb_invoice/123/payment' => Http::response($this->invoicePayments),
                '/2.0/kb_invoice' => Http::response($this->invoice ? [$this->invoice + ['id' => 123]] : []),
                '/4.0/purchase/bills/'.$this->billId => Http::response($this->bill + ['id' => $this->billId, 'amount_calc' => 15, 'status' => $this->billStatus, 'pending_amount' => $this->billStatus === 'PAID' ? 0 : ($this->bill['amount_calc'] ?? 15), 'payment' => null]),
                '/4.0/purchase/outgoing-payments' => Http::response(['data' => array_map(fn ($payment) => array_diff_key($payment, ['note' => true]), $this->billPayments), 'paging' => ['page_count' => 1]]),
                '/4.0/purchase/outgoing-payments/a4c6ba83-19d9-4674-9084-ae9d52d8e7e9' => Http::response($this->billPayments[0] ?? [], $this->billPayments ? 200 : 404),
                default => Http::response([], 500),
            };
        });
    }

    private function invoiceData(): array
    {
        $total = array_sum(array_column($this->invoice['positions'] ?? [], 'unit_price'));
        $unrounded = $total;
        $taxes = 0;
        $rounding = 0;
        if (($this->invoice['mwst_type'] ?? 2) === 0) {
            foreach ($this->invoice['positions'] as $position) {
                $rate = match ((int) $position['tax_id']) {
                    14 => 8.1, 15 => 2.6, default => 0
                };
                $taxes += $position['unit_price'] - $position['unit_price'] / (1 + $rate / 100);
            }
            $cents = (int) round($total * 100);
            $rounded = intdiv($cents + 2, 5) * 5;
            $rounding = ($rounded - $cents) / 100;
            $total = $rounded / 100;
            $taxes *= $total / $unrounded;
        }
        $credit = $this->invoiceStatus === 19 ? $total : 0;
        $paid = $this->invoiceStatus === 9 ? $total : array_sum(array_column($this->invoicePayments, 'value'));

        return $this->invoice + ['id' => 123, 'total' => $total, 'total_gross' => $unrounded, 'total_taxes' => number_format($taxes, 6, '.', ''), 'total_rounding_difference' => number_format($rounding, 2, '.', ''), 'kb_item_status_id' => $this->invoiceStatus,
            'total_received_payments' => $paid, 'total_credit_vouchers' => $credit, 'total_remaining_payments' => $total - $paid - $credit];
    }

    protected function tearDown(): void
    {
        if ($this->db) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    private function row(int $id)
    {
        return $this->db->table('clubora.bexio_sandbox_dispatches')->find($id);
    }

    private function actions()
    {
        return $this->db->table('clubora.bexio_invoice_actions')->whereIn('dispatch_id',
            $this->db->table('clubora.bexio_sandbox_dispatches')->where('tenant_id', $this->tenant->id)->select('id'));
    }

    private function generalFixtures(): array
    {
        $service = app(BexioSandboxDispatchService::class);
        foreach (['invoice', 'expense'] as $kind) {
            $service->send($this->actor, $this->tenant, $service->prepare($this->actor, $this->tenant, $kind));
        }
        foreach (['MWST_TYPE', 'INVOICE_DUE_DAYS', 'BILL_DUE_DAYS', 'UNIT_ID', 'TAX_ID', 'BILL_CONTACT_PARTNER_ID', 'USER_ID', 'LANGUAGE_ID', 'BANK_ACCOUNT_ID', 'CURRENCY_ID', 'PAYMENT_TYPE_ID'] as $key) {
            $this->db->table('clubora.tenant_settings')->insert(['tenant_id' => $this->tenant->id, 'area' => 'bexio', 'key' => 'BEXIO_'.$key, 'label' => $key, 'value_type' => 'integer', 'encrypted_value' => Crypt::encryptString('1')]);
        }
        $this->db->table('clubora.countries')->insert(['tenant_id' => $this->tenant->id, 'code' => 'CH', 'name' => 'Schweiz']);
        $this->db->table('clubora.billing_recipients')->insert(['tenant_id' => $this->tenant->id, 'code' => 'self', 'name' => 'Mitglied', 'email_field' => 'email']);
        $this->db->table('clubora.invoice_workflow_statuses')->insert(['tenant_id' => $this->tenant->id, 'code' => 'released', 'name' => 'Freigegeben']);
        foreach (['Z', 'B'] as $code) {
            $this->db->table('clubora.expense_statuses')->insert(['tenant_id' => $this->tenant->id, 'code' => $code, 'name' => $code]);
        }
        $this->db->table('clubora.members')->where('tenant_id', $this->tenant->id)->update(['last_name' => 'Synthetic', 'street' => 'Teststrasse', 'house_number' => '1', 'postal_code' => '4450', 'city' => 'Sissach']);

        return app(BexioGeneralSandboxFixtureService::class)->prepare($this->actor, $this->tenant);
    }

    public function test_general_web_preview_sends_actual_positions_and_paid_status_returns_once(): void
    {
        $fixtures = $this->generalFixtures();
        config(['clubunio-integrations.azure.tenant_slug' => $this->tenant->slug]);
        $this->mock(AzureDocumentReader::class, function ($mock) {
            $mock->shouldReceive('blobPath')->andReturn('sandbox/fixture.pdf');
            $mock->shouldReceive('read')->with('azure.blob://files/', 'sandbox/fixture.pdf')->andReturn("%PDF-1.4\nfixture\n%%EOF");
        });
        Expense::find($fixtures['expense'])->activeLines()->update(['storage_reference' => 'sandbox/fixture.pdf']);
        $service = app(BexioSandboxDispatchService::class);
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $this->actingAs($this->actor, 'clubunio');
        Filament::setTenant($this->tenant);
        foreach ($fixtures as $kind => $id) {
            $trial = app(BexioDispatchTrialService::class)->preview($this->actor, $this->tenant, $kind, $id);
            Livewire::test(BexioDispatchTrials::class)->call('sendSandbox', $trial)->assertHasNoErrors();
            $dispatch = $this->db->table('clubora.bexio_sandbox_dispatches')->where('source_trial_id', $trial)->value('id');
            $this->assertSame('accepted', $this->row($dispatch)->state);
            $this->assertSame(2000, (int) $this->row($dispatch)->expected_cents);
            $before = count(Http::recorded());
            $service->send($this->actor, $this->tenant, $dispatch);
            $this->assertCount($before, Http::recorded());
        }
        $this->assertCount(2, $this->invoice['positions']);
        $this->assertCount(2, $this->bill['line_items']);
        $this->assertSame([$this->fileId], $this->bill['attachment_ids']);
        $this->billStatus = 'PAID';
        $reconcile = app(BexioReconciliationService::class);
        $check = $reconcile->preview($this->actor, $this->tenant, 'expense', $fixtures['expense']);
        $reconcile->apply($this->actor, $this->tenant, $check);
        $reconcile->apply($this->actor, $this->tenant, $check);
        $this->assertSame('B', Expense::find($fixtures['expense'])->status->code);
        $this->assertSame(1, $this->db->table('clubora.expense_events')->where('expense_id', $fixtures['expense'])->count());
    }

    private function parentInvoice(): int
    {
        $this->generalFixtures();
        $this->db->table('clubora.invoice_workflow_statuses')->insert(['tenant_id' => $this->tenant->id, 'code' => 'cancelled', 'name' => 'Storniert']);
        $parent = new Member;
        $parent->forceFill(['tenant_id' => $this->tenant->id, 'first_name' => 'Parent', 'bexio_contact_id' => 3])->save();
        $this->db->table('clubora.bexio_sandbox_fixtures')->where('tenant_id', $this->tenant->id)->update(['parent_id' => $parent->id]);
        $this->db->table('clubora.billing_recipients')->insert(['tenant_id' => $this->tenant->id, 'code' => 'mother', 'name' => 'Mutter', 'email_field' => 'mother_email']);
        foreach (['P', 'E', 'O', 'S', 'T', 'B'] as $code) {
            $this->db->table('clubora.invoice_line_statuses')->insert(['tenant_id' => $this->tenant->id, 'code' => $code, 'name' => $code]);
        }
        foreach ([7 => 'E', 8 => 'O', 19 => 'S', 16 => 'T', 9 => 'B'] as $status => $code) {
            $this->db->table('clubora.invoice_statuses')->insert(['tenant_id' => $this->tenant->id, 'code' => $status, 'name' => $code, 'position_code' => $code]);
        }
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $this->actingAs($this->actor, 'clubunio');
        Filament::setTenant($this->tenant);
        app(BexioGeneralSandboxFixtureService::class)->prepareParent($this->actor, $this->tenant);
        Livewire::test(BexioSandbox::class)->call('prepareParent')->assertHasNoErrors();
        $id = Invoice::where('tenant_id', $this->tenant->id)->where('notes', BexioGeneralSandboxFixtureService::PARENT_NOTES)->value('id');
        $dispatch = $this->db->table('clubora.bexio_sandbox_dispatches')->where('invoice_id', $id)->first();
        $this->assertNotNull($dispatch);
        app(BexioSandboxDispatchService::class)->send($this->actor, $this->tenant, $dispatch->id);
        $this->assertSame('accepted', $this->row($dispatch->id)->state);

        return $id;
    }

    public function test_parent_invoice_discount_release_pdf_guard_and_cancel(): void
    {
        $id = $this->parentInvoice();
        $this->assertSame(3, $this->invoice['contact_id']);
        $this->assertStringContainsString('Rabatt CHF 5.00', $this->invoice['positions'][0]['text']);
        $this->assertSame('25.00', $this->invoice['positions'][0]['unit_price']);
        $this->assertSame('released', Invoice::find($id)->workflowStatus->code);
        $viewer = route('clubunio.invoices.preview', ['tenant' => $this->tenant->slug, 'invoice' => $id, 'source' => 'bexio']);
        $this->actingAs($this->actor, 'clubunio')->get($viewer)->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertSee('pdf-viewer.js')
            ->assertSee('data-pdf-url', false)->assertSee('PDF herunterladen');
        $this->get(str_replace('/bexio', '/invalid', $viewer))->assertNotFound();
        app(BexioSandboxFixtureService::class)->guardPdf($this->tenant, Invoice::find($id), 'synthetic-token');
        Livewire::test(BexioSandbox::class)->call('invoiceAction', $id, 'issue')->assertHasNoErrors();
        $this->assertSame('8', Invoice::find($id)->status->code);
        $service = app(BexioInvoiceActionService::class);
        $service->execute($this->actor, $this->tenant, $id, 'issue');
        Livewire::test(BexioSandbox::class)->call('invoiceAction', $id, 'cancel')->assertHasNoErrors();
        $this->assertSame('19', Invoice::find($id)->status->code);
        $this->assertSame('cancelled', Invoice::find($id)->workflowStatus->code);
        $this->assertSame(2, $this->actions()->where('state', 'accepted')->count());
        $this->assertSame(2, count(Http::recorded(fn ($request) => $request->method() === 'POST' && preg_match('~/123/(issue|cancel)$~', $request->url()))));
    }

    public function test_uncertain_action_cannot_be_repeated_but_can_be_reconciled(): void
    {
        $id = $this->parentInvoice();
        $service = app(BexioInvoiceActionService::class);
        $this->actionTimeout = true;
        $service->execute($this->actor, $this->tenant, $id, 'issue');
        try {
            $service->execute($this->actor, $this->tenant, $id, 'issue');
            $this->fail('Repeated uncertain action');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->invoiceStatus = 8;
        $service->reconcile($this->actor, $this->tenant, $id);
        $this->assertSame('8', Invoice::find($id)->status->code);
        $this->assertSame('accepted', $this->actions()->value('state'));
        $this->assertSame(1, count(Http::recorded(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/issue'))));
    }

    public function test_paid_invoice_cannot_be_cancelled(): void
    {
        $id = $this->parentInvoice();
        $this->invoiceStatus = 9;
        $this->expectException(HttpException::class);
        app(BexioInvoiceActionService::class)->execute($this->actor, $this->tenant, $id, 'cancel');
    }

    public function test_invoice_action_rejects_changed_recipient_and_nonadmin(): void
    {
        $id = $this->parentInvoice();
        $service = app(BexioInvoiceActionService::class);
        $this->invoice['contact_id'] = 999;
        try {
            $service->execute($this->actor, $this->tenant, $id, 'issue');
            $this->fail('Changed recipient accepted');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->invoice['contact_id'] = 3;
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)->update(['role' => 'office']);
        try {
            $service->execute($this->actor, $this->tenant, $id, 'issue');
            $this->fail('Nonadmin accepted');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertSame(0, $this->actions()->count());
    }

    public function test_invoice_partial_full_payments_and_financial_readback_are_idempotent(): void
    {
        $id = $this->parentInvoice();
        app(BexioInvoiceActionService::class)->execute($this->actor, $this->tenant, $id, 'issue');
        $service = app(BexioFinancialActionService::class);
        Livewire::test(BexioSandbox::class)->call('financeAction', 'invoice', $id, 'payment_partial')->assertHasNoErrors();
        $this->assertSame('16', Invoice::find($id)->status->code);
        $balance = Invoice::find($id)->bexioBalance;
        $this->assertSame(1650, (int) $balance->paid_cents);
        $this->assertSame(1650, (int) $balance->remaining_cents);
        $open = app(DashboardService::class)->openInvoices($this->actor, $this->tenant)->findOrFail($id);
        $this->assertEquals(16.50, (float) $open->open_amount);
        $response = app(FinanceExportService::class)->download($this->actor, $this->tenant, 'invoices', Invoice::whereKey($id));
        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();
        $this->assertStringContainsString('16.50', $csv);
        $service->execute($this->actor, $this->tenant, 'invoice', $id, 'payment_full');
        $this->assertSame('9', Invoice::find($id)->status->code);
        $this->assertSame(0, (int) Invoice::find($id)->bexioBalance->remaining_cents);
        $this->assertFalse(app(DashboardService::class)->openInvoices($this->actor, $this->tenant)->whereKey($id)->exists());
        $service->execute($this->actor, $this->tenant, 'invoice', $id, 'payment_full');
        $this->assertCount(2, $this->invoicePayments);
        $this->get(InvoiceResource::getUrl('view', ['record' => $id], tenant: $this->tenant))->assertOk()->assertSee('Bexio Restbetrag');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/banking/payments'));
    }

    public function test_booked_expense_stays_released_until_manual_payment_is_verified(): void
    {
        $fixtures = $this->generalFixtures();
        $id = $fixtures['expense'];
        $dispatch = app(BexioSandboxDispatchService::class);
        $trial = app(BexioDispatchTrialService::class)->preview($this->actor, $this->tenant, 'expense', $id);
        $dispatch->send($this->actor, $this->tenant, $dispatch->prepareTrial($this->actor, $this->tenant, $trial));
        $service = app(BexioFinancialActionService::class);
        $service->execute($this->actor, $this->tenant, 'expense', $id, 'bill_book');
        $this->assertSame('Z', Expense::find($id)->status->code);
        $this->assertNull(Expense::find($id)->paid_on);
        $service->execute($this->actor, $this->tenant, 'expense', $id, 'bill_pay');
        $this->assertSame('B', Expense::find($id)->status->code);
        $this->assertNotNull(Expense::find($id)->paid_on);
        $this->assertSame(0, (int) Expense::find($id)->bexioBalance->remaining_cents);
        $service->execute($this->actor, $this->tenant, 'expense', $id, 'bill_pay');
        $this->assertCount(1, $this->billPayments);
        $this->assertSame('MANUAL', $this->billPayments[0]['payment_type']);
        $this->assertArrayNotHasKey('receiver_iban', $this->billPayments[0]);
        $this->assertSame(1, $this->db->table('clubora.expense_events')->where('expense_id', $id)->count());
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $this->actingAs($this->actor, 'clubunio');
        Filament::setTenant($this->tenant);
        $this->get(ExpenseResource::getUrl('view', ['record' => $id], tenant: $this->tenant))->assertOk()->assertSee('Bexio Restbetrag');
    }

    public function test_uncertain_payment_is_blocked_and_reconciled_without_post(): void
    {
        $id = $this->parentInvoice();
        app(BexioInvoiceActionService::class)->execute($this->actor, $this->tenant, $id, 'issue');
        $service = app(BexioFinancialActionService::class);
        $this->financeTimeout = true;
        $service->execute($this->actor, $this->tenant, 'invoice', $id, 'payment_partial');
        $row = $this->db->table('clubora.bexio_financial_actions')->where('actor_id', $this->actor->id)->first();
        $this->assertSame('uncertain', $row->state);
        try {
            $service->execute($this->actor, $this->tenant, 'invoice', $id, 'payment_partial');
            $this->fail('Repeated uncertain payment');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->invoicePayments[] = ['id' => 101, 'kb_invoice_id' => 123, 'value' => '16.50', 'date' => $row->payment_date, 'bank_account_id' => 1];
        $this->invoiceStatus = 16;
        $service->reconcile($this->actor, $this->tenant, 'invoice', $id);
        $this->assertSame('accepted', $this->db->table('clubora.bexio_financial_actions')->where('id', $row->id)->value('state'));
        $this->assertSame(1, count(Http::recorded(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/123/payment'))));
    }

    public function test_financial_write_requires_local_release_and_unchanged_positions(): void
    {
        $id = $this->parentInvoice();
        app(BexioInvoiceActionService::class)->execute($this->actor, $this->tenant, $id, 'issue');
        $invoice = Invoice::findOrFail($id);
        $released = $invoice->workflow_status_id;
        $invoice->workflow_status_id = app(InvoiceWorkflowService::class)->status($this->tenant, 'draft');
        $invoice->save();
        $service = app(BexioFinancialActionService::class);
        $before = count(Http::recorded());
        try {
            $service->execute($this->actor, $this->tenant, 'invoice', $id, 'payment_partial');
            $this->fail('Unreleased invoice accepted');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
            $this->assertCount($before, Http::recorded());
        }
        $invoice->workflow_status_id = $released;
        $invoice->save();
        $invoice->activeLines()->update(['net_amount' => 100]);
        $this->expectException(HttpException::class);
        $service->execute($this->actor, $this->tenant, 'invoice', $id, 'payment_partial');
    }

    public function test_general_preview_rejects_nonfixture_member_before_post(): void
    {
        $fixtures = $this->generalFixtures();
        $member = new Member;
        $original = Invoice::findOrFail($fixtures['invoice'])->member;
        $member = $original->replicate();
        $member->save();
        Invoice::whereKey($fixtures['invoice'])->update(['member_id' => $member->id]);
        $trial = app(BexioDispatchTrialService::class)->preview($this->actor, $this->tenant, 'invoice', $fixtures['invoice']);
        $before = count(Http::recorded());
        try {
            app(BexioSandboxDispatchService::class)->prepareTrial($this->actor, $this->tenant, $trial);
            $this->fail('Foreign fixture accepted');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
            $this->assertCount($before, Http::recorded());
        }
    }

    public function test_invoice_is_created_once_and_web_action_uses_service(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $this->actingAs($this->actor, 'clubunio');
        Filament::setTenant($this->tenant);
        $page = Livewire::test(BexioSandbox::class)->call('prepare', 'invoice')->assertHasNoErrors();
        $id = $this->db->table('clubora.bexio_sandbox_dispatches')->where('tenant_id', $this->tenant->id)->value('id');
        $page->call('send', $id)->assertHasNoErrors();
        $this->assertSame('accepted', $this->row($id)->state);
        $this->assertSame('123', Invoice::find($this->row($id)->invoice_id)->bexio_invoice_id);
        $page->call('prepare', 'invoice')->call('send', $id)->assertHasNoErrors();
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'POST'));
        $this->assertStringStartsWith('clubora-sandbox:', $this->invoice['api_reference']);
    }

    public function test_expense_upload_is_attached_and_no_payment_is_created(): void
    {
        $service = app(BexioSandboxDispatchService::class);
        $id = $service->prepare($this->actor, $this->tenant, 'expense');
        $service->send($this->actor, $this->tenant, $id);
        $this->assertSame('accepted', $this->row($id)->state);
        $this->assertSame([$this->fileId], $this->bill['attachment_ids']);
        $this->assertArrayNotHasKey('payment', $this->bill);
        $expense = Expense::findOrFail($this->row($id)->expense_id);
        $this->assertSame($this->billId, $expense->bexio_bill_id);
        $this->assertSame('O', $expense->status->code);
        $this->assertNull($expense->paid_on);
        $this->assertCount(2, Http::recorded(fn ($request) => $request->method() === 'POST'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/3.0/files') && str_contains($request->body(), '%PDF-'));
    }

    public function test_timeout_is_not_retried_and_read_reconciliation_binds_result(): void
    {
        $service = app(BexioSandboxDispatchService::class);
        $id = $service->prepare($this->actor, $this->tenant, 'invoice');
        $this->postStatus = 504;
        $service->send($this->actor, $this->tenant, $id);
        $this->assertSame('uncertain', $this->row($id)->state);
        try {
            $service->send($this->actor, $this->tenant, $id);
            $this->fail('Retry permitted');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $service->reconcile($this->actor, $this->tenant, $id);
        $this->assertSame('accepted', $this->row($id)->state);
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'POST'));
    }

    public function test_changed_native_document_is_not_sent(): void
    {
        $service = app(BexioSandboxDispatchService::class);
        $id = $service->prepare($this->actor, $this->tenant, 'invoice');
        $this->db->table('clubora.invoices')->where('id', $this->row($id)->invoice_id)->update(['notes' => 'Changed']);
        try {
            $service->send($this->actor, $this->tenant, $id);
            $this->fail('Changed document sent');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
            Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        }
    }

    public function test_wrong_company_is_blocked_before_any_post(): void
    {
        $service = app(BexioSandboxDispatchService::class);
        $id = $service->prepare($this->actor, $this->tenant, 'invoice');
        $this->company = 'Real club';
        $this->expectException(\RuntimeException::class);
        try {
            $service->send($this->actor, $this->tenant, $id);
        } finally {
            Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        }
    }

    public function test_office_cannot_access_web_page_or_dispatch(): void
    {
        $service = app(BexioSandboxDispatchService::class);
        $id = $service->prepare($this->actor, $this->tenant, 'invoice');
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)->update(['role' => 'office']);
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $this->actingAs($this->actor, 'clubunio');
        Filament::setTenant($this->tenant);
        $this->assertFalse(BexioSandbox::canAccess());
        $this->expectException(HttpException::class);
        $service->send($this->actor, $this->tenant, $id);
    }

    public function test_new_authorization_requires_verified_rebinding(): void
    {
        $service = app(BexioSandboxDispatchService::class);
        $id = $service->prepare($this->actor, $this->tenant, 'invoice');
        $this->connectedAt = '2026-10-15';
        try {
            $service->send($this->actor, $this->tenant, $id);
            $this->fail('Unverified reconnect accepted');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $service->verifyReconnection($this->actor, $this->tenant);
        $service->send($this->actor, $this->tenant, $id);
        $this->assertSame('accepted', $this->row($id)->state);
    }

    public function test_only_explicitly_denied_upload_can_be_resumed(): void
    {
        $service = app(BexioSandboxDispatchService::class);
        $id = $service->prepare($this->actor, $this->tenant, 'expense');
        $this->uploadStatus = 403;
        $service->send($this->actor, $this->tenant, $id);
        $this->assertSame('rejected', $this->row($id)->state);
        $this->assertSame([], $this->bill);
        $service->resumeRejectedUpload($this->actor, $this->tenant, $id);
        $this->uploadStatus = 200;
        $service->send($this->actor, $this->tenant, $id);
        $this->assertSame('accepted', $this->row($id)->state);
        $this->assertCount(1, Http::recorded(fn ($request) => str_ends_with($request->url(), '/4.0/purchase/bills') && $request->method() === 'POST'));
    }

    public function test_upload_timeout_stays_blocked(): void
    {
        $service = app(BexioSandboxDispatchService::class);
        $id = $service->prepare($this->actor, $this->tenant, 'expense');
        $this->uploadStatus = 504;
        $service->send($this->actor, $this->tenant, $id);
        $this->assertSame('uncertain', $this->row($id)->state);
        $this->expectException(HttpException::class);
        $service->resumeRejectedUpload($this->actor, $this->tenant, $id);
    }
}
