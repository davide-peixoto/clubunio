<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\BexioConnectionService;
use App\ClubUnio\Services\BexioSandboxFixtureService;
use App\ClubUnio\Services\InvoicePdfService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class InvoicePdfPerformanceTest extends TestCase
{
    private $db;

    private Account $actor;

    private Tenant $tenant;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Transactional database opt-in required');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->tenant = Tenant::create(['slug' => 'pdf-perf-'.Str::random(12), 'name' => 'Synthetic']);
        $this->actor = new Account;
        $this->actor->forceFill(['username' => Str::random(20), 'display_name' => 'Synthetic', 'password' => bcrypt('fixture'), 'active' => true])->save();
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $this->actor->id, 'role' => 'office']);
        $member = $this->db->table('clubora.members')->insertGetId(['tenant_id' => $this->tenant->id, 'first_name' => 'Synthetic']);
        $this->invoice = new Invoice;
        $this->invoice->forceFill(['tenant_id' => $this->tenant->id, 'member_id' => $member, 'bexio_invoice_id' => '12345'])->save();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->transactionLevel()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    public function test_bexio_pdf_does_not_load_local_display_relations_and_download_reauthorizes(): void
    {
        $this->mock(BexioConnectionService::class, fn ($mock) => $mock->shouldReceive('accessToken')->twice()->andReturn('fixture-token'));
        $this->mock(BexioSandboxFixtureService::class, fn ($mock) => $mock->shouldReceive('guardPdf')->twice()->andReturnNull());
        Http::fake(['api.bexio.com/*' => Http::response(['content' => base64_encode('%PDF-1.7 synthetic')])]);
        $this->db->enableQueryLog();
        $this->db->flushQueryLog();
        $service = app(InvoicePdfService::class);
        $response = $service->open($this->actor, $this->tenant, $this->invoice->id, true);
        $this->assertSame(200, $response->getStatusCode());
        $sql = implode("\n", array_column($this->db->getQueryLog(), 'query'));
        foreach (['members', 'seasons', 'invoice_lines', 'invoice_workflow_statuses'] as $table) {
            $this->assertStringNotContainsString('from "clubora"."'.$table.'"', $sql);
        }
        $this->assertLessThanOrEqual(2, count($this->db->getQueryLog()));
        $this->db->flushQueryLog();
        $download = $service->open($this->actor, $this->tenant, $this->invoice->id, true, true);
        $this->assertSame($response->getContent(), $download->getContent());
        $this->assertStringStartsWith('attachment;', $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $download->headers->get('Cache-Control'));
        $this->assertGreaterThanOrEqual(2, count($this->db->getQueryLog()));
        $this->db->disableQueryLog();
        Http::assertSentCount(2);
    }

    public function test_role_revocation_and_foreign_tenant_never_reach_provider(): void
    {
        Http::fake();
        $this->db->table('clubora.tenant_accounts')->where('account_id', $this->actor->id)->update(['role' => 'trainer']);
        try {
            app(InvoicePdfService::class)->open($this->actor, $this->tenant, $this->invoice->id, true);
            $this->fail('Revoked role opened invoice');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }
        $foreign = Tenant::create(['slug' => 'pdf-foreign-'.Str::random(8), 'name' => 'Other']);
        try {
            app(InvoicePdfService::class)->open($this->actor, $foreign, $this->invoice->id, true);
            $this->fail('Foreign invoice opened');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }
        Http::assertNothingSent();
    }

    public function test_inline_viewer_has_no_wrapper_iframe_and_keeps_protected_download(): void
    {
        $url = 'https://example.invalid/documents/invoices/123/bexio';
        $html = view('clubunio.invoices.preview', compact('url'))->render();
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringContainsString('data-clubunio-pdf-viewer', $html);
        $this->assertStringContainsString($url.'?download=1', $html);
        $this->assertStringNotContainsString('blob:', $html);
        $standalone = view('clubunio.invoices.viewer', compact('url'))->render();
        $this->assertStringContainsString('pdf-viewer.js?v=2', $standalone);
        $this->assertStringContainsString('data-pdf-control="pdf"', $standalone);
    }
}
