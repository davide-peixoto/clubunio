<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\BexioConnectionService;
use App\ClubUnio\Services\BexioReconciliationService;
use App\ClubUnio\Services\BexioSandboxFixtureService;
use App\ClubUnio\Services\InvoicePdfService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BexioSandboxFixtureTest extends TestCase
{
    private $db;

    private Tenant $tenant;

    private Account $actor;

    private array $connection = ['fingerprint' => 'fixture', 'connected_at' => '2026-10-14T10:00:00Z'];

    private string $company = 'SVS Api Test';

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Opt-in rolled-back DB tests');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->tenant = Tenant::create(['slug' => 'sandbox-'.bin2hex(random_bytes(6)), 'name' => 'Sandbox fixture']);
        $this->actor = new Account;
        $this->actor->forceFill(['username' => bin2hex(random_bytes(8)), 'display_name' => 'Test admin', 'active' => true, 'password' => bcrypt('test')])->save();
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $this->actor->id, 'role' => 'admin']);
        $this->db->table('clubora.invoice_workflow_statuses')->insert(['tenant_id' => $this->tenant->id, 'code' => 'draft', 'name' => 'Entwurf']);
        $this->mock(BexioConnectionService::class, function ($mock) {
            $mock->shouldReceive('connection')->andReturnUsing(fn () => $this->connection);
            $mock->shouldReceive('accessToken')->andReturn('synthetic-token');
        });
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match ($path) {
                '/2.0/company_profile' => Http::response([['id' => 1, 'name' => $this->company]]),
                '/2.0/contact' => Http::response(array_map(fn ($kind, $id) => ['id' => $id,
                    'name_1' => 'Clubora Sandbox '.$kind.' v1', 'remarks' => 'clubora-sandbox-fixture:v1:'.strtolower($kind)],
                    ['Member', 'Parent', 'Supplier'], [2, 3, 4])),
                '/2.0/kb_invoice' => Http::response([['id' => 1, 'api_reference' => BexioSandboxFixtureService::REFERENCE]]),
                '/2.0/kb_invoice/1' => Http::response(['id' => 1, 'api_reference' => BexioSandboxFixtureService::REFERENCE,
                    'contact_id' => 2, 'kb_item_status_id' => 7, 'total' => '25.00', 'total_taxes' => '0', 'mwst_type' => 2]),
                '/2.0/kb_invoice/1/pdf' => Http::response(['content' => base64_encode('%PDF-fixture')]),
                default => Http::response([], 500),
            };
        });
    }

    protected function tearDown(): void
    {
        if ($this->db) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    public function test_seed_is_repeatable_and_pdf_works_without_write_activation(): void
    {
        $service = app(BexioSandboxFixtureService::class);
        $first = $service->seed($this->actor, $this->tenant);
        $again = $service->seed($this->actor, $this->tenant);
        $this->assertTrue($first['created']);
        $this->assertFalse($again['created']);
        $this->assertEquals($first['invoice_id'], $again['invoice_id']);
        $this->assertSame(3, $this->db->table('clubora.members')->where('tenant_id', $this->tenant->id)->count());
        $invoice = Invoice::findOrFail($first['invoice_id']);
        $this->assertNull($invoice->legacy_id);
        $this->assertEquals(25, $invoice->lines()->sum('net_amount'));
        $response = app(InvoicePdfService::class)->open($this->actor, $this->tenant, $invoice->id, true);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('%PDF-fixture', $response->getContent());
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_imported_id_collision_is_blocked_before_provider_request(): void
    {
        $seed = app(BexioSandboxFixtureService::class)->seed($this->actor, $this->tenant);
        $invoice = Invoice::findOrFail($seed['invoice_id'])->replicate();
        $invoice->legacy_id = 123;
        $invoice->save();
        $before = count(Http::recorded());
        $response = app(InvoicePdfService::class)->open($this->actor, $this->tenant, $invoice->id, true);
        $this->assertSame(503, $response->getStatusCode());
        $this->assertCount($before, Http::recorded());
    }

    public function test_reconnection_does_not_rebind_existing_test_data(): void
    {
        $service = app(BexioSandboxFixtureService::class);
        $seed = $service->seed($this->actor, $this->tenant);
        $this->connection['connected_at'] = '2026-10-15T10:00:00Z';
        $response = app(InvoicePdfService::class)->open($this->actor, $this->tenant, $seed['invoice_id'], true);
        $this->assertSame(503, $response->getStatusCode());
        $this->expectException(\RuntimeException::class);
        $service->seed($this->actor, $this->tenant);
    }

    public function test_office_cannot_seed_and_makes_no_requests(): void
    {
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)->update(['role' => 'office']);
        try {
            app(BexioSandboxFixtureService::class)->seed($this->actor, $this->tenant);
            $this->fail('Office allowed');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
            Http::assertNothingSent();
        }
    }

    public function test_status_reconciliation_is_blocked_during_fixture_testing(): void
    {
        $fixture = app(BexioSandboxFixtureService::class)->seed($this->actor, $this->tenant);
        try {
            app(BexioReconciliationService::class)->document($this->actor, $this->tenant, 'invoice', $fixture['invoice_id']);
            $this->fail('Sandbox reconciliation allowed');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
    }

    public function test_wrong_company_does_not_create_local_records(): void
    {
        $this->company = 'Real club';
        try {
            app(BexioSandboxFixtureService::class)->seed($this->actor, $this->tenant);
            $this->fail('Wrong company accepted');
        } catch (\RuntimeException $error) {
            $this->assertSame(409, $error->getCode());
            $this->assertSame(0, $this->db->table('clubora.members')->where('tenant_id', $this->tenant->id)->count());
        }
    }
}
