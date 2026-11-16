<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\ErpNextConfiguration;
use App\ClubUnio\Services\ErpNextInvoiceTransferService;
use App\ClubUnio\Services\ErpNextStatusReconciliation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class ErpNextInvoiceTransferTest extends TestCase
{
    private Tenant $tenant;

    private Account $actor;

    private array $remote = [];

    private bool $loseFirstInvoiceResponse = true;

    private bool $remoteUnavailable = false;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.connections.clubunio' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'clubunio-erpnext.enabled' => true,
            'clubunio-erpnext.origin' => 'https://erp.example.test',
            'clubunio-erpnext.namespace' => 'test',
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        ]);
        DB::purge('clubunio');
        $db = DB::connection('clubunio');
        $db->statement("ATTACH DATABASE ':memory:' AS clubora");
        foreach ([
            'tenants' => 'id INTEGER PRIMARY KEY, active BOOLEAN, slug TEXT, accounting_provider TEXT',
            'accounts' => 'id INTEGER PRIMARY KEY, active BOOLEAN, api_only BOOLEAN, password TEXT',
            'tenant_accounts' => 'tenant_id INTEGER, account_id INTEGER, role TEXT',
            'tenant_settings' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, area TEXT, key TEXT, label TEXT, enabled BOOLEAN, value_type TEXT, is_secret BOOLEAN, encrypted_value TEXT, created_at TEXT, updated_at TEXT',
            'setting_changes' => 'tenant_id INTEGER, setting_id INTEGER, actor_id INTEGER',
            'members' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, first_name TEXT, last_name TEXT, company TEXT, street TEXT, house_number TEXT, postal_code TEXT, city TEXT, country_id INTEGER',
            'countries' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, code TEXT',
            'invoice_workflow_statuses' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, code TEXT',
            'invoices' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, member_id INTEGER, workflow_status_id INTEGER, created_at TEXT, revision INTEGER, active BOOLEAN, tax_mode TEXT, cash_rounding BOOLEAN, legacy_id INTEGER, bexio_invoice_id INTEGER',
            'invoice_lines' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, invoice_id INTEGER, description TEXT, net_amount NUMERIC, active BOOLEAN',
            'erpnext_invoice_transfers' => 'tenant_id INTEGER, invoice_id INTEGER PRIMARY KEY, reference TEXT UNIQUE, origin TEXT, total_cents INTEGER, remote_id TEXT, state TEXT DEFAULT \'pending\', created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT, last_status_attempt_at TEXT, status_sync_failed BOOLEAN DEFAULT FALSE',
            'bexio_sandbox_dispatches' => 'tenant_id INTEGER, invoice_id INTEGER, kind TEXT',
        ] as $table => $columns) {
            $db->statement('CREATE TABLE clubora.'.$table.' ('.$columns.')');
        }
        $this->insert('tenants', ['id' => 1, 'active' => true, 'slug' => 'club-1', 'accounting_provider' => 'erpnext']);
        $this->insert('tenants', ['id' => 2, 'active' => true, 'slug' => 'club-2', 'accounting_provider' => 'erpnext']);
        $this->insert('accounts', ['id' => 1, 'active' => true, 'api_only' => false, 'password' => 'fixture']);
        $this->insert('tenant_accounts', ['account_id' => 1, 'tenant_id' => 1, 'role' => 'admin']);
        $this->insert('tenant_accounts', ['account_id' => 1, 'tenant_id' => 2, 'role' => 'admin']);
        $this->insert('invoice_workflow_statuses', ['id' => 1, 'tenant_id' => 1, 'code' => 'released']);
        $this->insert('members', ['id' => 1, 'tenant_id' => 1, 'first_name' => 'Synthetic', 'last_name' => 'Member',
            'street' => 'Teststrasse', 'house_number' => '1', 'postal_code' => '4000', 'city' => 'Basel', 'country_id' => 1]);
        $this->insert('countries', ['id' => 1, 'tenant_id' => 1, 'code' => 'CH']);
        foreach ([1, 2] as $id) {
            $this->insert('invoices', ['id' => $id, 'tenant_id' => 1, 'member_id' => 1, 'workflow_status_id' => 1,
                'created_at' => '2026-11-16 12:00:00', 'revision' => 1, 'active' => true, 'tax_mode' => 'exempt', 'cash_rounding' => true]);
            $this->insert('invoice_lines', ['id' => $id, 'tenant_id' => 1, 'invoice_id' => $id, 'description' => 'Dues', 'net_amount' => 87.51, 'active' => true]);
        }
        $this->tenant = Tenant::findOrFail(1);
        $this->actor = Account::findOrFail(1);
        app(ErpNextConfiguration::class)->save($this->actor, $this->tenant, [
            'company' => 'Sandbox', 'company_address' => 'Sandbox-Billing', 'territory' => 'Switzerland',
            'customer_group' => 'Individual', 'item_code' => 'DUES', 'income_account' => 'Membership',
            'due_days' => 30, 'api_key' => 'fixture-key', 'api_secret' => 'fixture-secret',
        ]);
        Http::preventStrayRequests();
        Http::fake(['https://erp.example.test/*' => $this->handleRemote(...)]);
    }

    public function test_lost_response_resumes_existing_draft_and_second_invoice_has_unique_id(): void
    {
        $service = app(ErpNextInvoiceTransferService::class);
        $this->reject(fn () => $service->transfer($this->actor, $this->tenant, 1, 1), 503);
        $this->assertSame('pending', $service::query($this->tenant)->where('invoice_id', 1)->value('state'));
        $first = $service->transfer($this->actor, $this->tenant, 1, 1);
        $second = $service->transfer($this->actor, $this->tenant, 2, 1);
        $this->assertSame('draft', $first->state);
        $this->assertSame('draft', $second->state);
        $this->assertNotSame($first->remote_id, $second->remote_id);
        $this->assertNotSame($first->reference, $second->reference);
        $this->assertCount(1, $this->remote['Customer']);
        $this->assertCount(1, $this->remote['Address']);
        $this->assertCount(2, $this->remote['Sales Invoice']);
        $this->remote['Sales Invoice'][$first->remote_id]['docstatus'] = 1;
        foreach ([8750 => 'open', 1500 => 'part_paid', 0 => 'paid'] as $outstanding => $state) {
            $this->remote['Sales Invoice'][$first->remote_id]['outstanding_amount'] = $outstanding / 100;
            $this->assertSame($state, $service->refresh($this->actor, $this->tenant, 1)->state);
        }
        $this->assertStringNotContainsString('fixture-secret', DB::connection('clubunio')->table('clubora.tenant_settings')->value('encrypted_value'));
    }

    public function test_other_tenant_and_disabled_provider_cannot_export(): void
    {
        $service = app(ErpNextInvoiceTransferService::class);
        $this->reject(fn () => $service->transfer($this->actor, Tenant::findOrFail(2), 1, 1), 404);
        DB::connection('clubunio')->table('clubora.tenants')->where('id', 1)->update(['accounting_provider' => 'none']);
        $this->reject(fn () => $service->transfer($this->actor, $this->tenant, 1, 1), 409);
        Http::assertNothingSent();
    }

    public function test_status_reconciliation_only_reads_and_retries_failed_checks(): void
    {
        $service = app(ErpNextInvoiceTransferService::class);
        $this->reject(fn () => $service->transfer($this->actor, $this->tenant, 1, 1), 503);
        $this->insert('erpnext_invoice_transfers', ['tenant_id' => 1, 'invoice_id' => 2, 'reference' => 'missing', 'total_cents' => 8750]);
        $priorRequests = Http::recorded()->count();
        $reconciliation = app(ErpNextStatusReconciliation::class);
        $this->assertSame(['confirmed' => 1, 'unconfirmed' => 1, 'failed' => 0, 'skipped' => 0], $reconciliation->run(20));
        $row = $service::query($this->tenant)->where('invoice_id', 1);
        $this->assertSame('draft', $row->value('state'));
        $remoteId = $row->value('remote_id');
        $this->remote['Sales Invoice'][$remoteId]['docstatus'] = 1;
        $this->remote['Sales Invoice'][$remoteId]['outstanding_amount'] = 0;
        $this->travel(5)->minutes();
        $this->remoteUnavailable = true;
        $this->assertSame(2, $reconciliation->run(20)['failed']);
        $this->assertTrue((bool) $row->value('status_sync_failed'));
        $this->assertSame('draft', $row->value('state'));
        $this->travel(5)->minutes();
        $this->remoteUnavailable = false;
        $this->assertSame(1, $reconciliation->run(20)['confirmed']);
        $this->assertFalse((bool) $row->value('status_sync_failed'));
        $this->assertSame('paid', $row->value('state'));
        $this->assertTrue(Http::recorded()->slice($priorRequests)->every(fn ($entry) => $entry[0]->method() === 'GET'));
        $priorRequests = Http::recorded()->count();
        $this->travel(5)->minutes();
        DB::connection('clubunio')->table('clubora.tenants')->where('id', 1)->update(['accounting_provider' => 'bexio']);
        $this->assertSame(['confirmed' => 0, 'unconfirmed' => 0, 'failed' => 0, 'skipped' => 0], $reconciliation->run(20));
        Http::assertSentCount($priorRequests);
    }

    private function handleRemote(Request $request)
    {
        $this->assertTrue($request->hasHeader('Authorization', 'token fixture-key:fixture-secret'));
        if ($this->remoteUnavailable) {
            return Http::response([], 503);
        }
        $segments = explode('/', trim(parse_url($request->url(), PHP_URL_PATH), '/'));
        $type = rawurldecode($segments[2]);
        if (isset($segments[3])) {
            return Http::response(['data' => $this->remote[$type][rawurldecode($segments[3])]]);
        }
        if ($request->method() === 'GET') {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            $reference = json_decode($query['filters'], true)[0][2];
            $matches = array_filter($this->remote[$type] ?? [], fn ($row) => $row['custom_clubunio_reference'] === $reference);

            return Http::response(['data' => array_values($matches)]);
        }
        $name = $type.'-'.(count($this->remote[$type] ?? []) + 1);
        $doc = $request->data() + ['name' => $name, 'total_taxes_and_charges' => 0];
        if ($type === 'Sales Invoice') {
            $this->assertSame(0, $doc['docstatus']);
            $this->assertSame('CHF', $doc['currency']);
            $this->assertSame('87.50', $doc['items'][0]['rate']);
            $this->assertSame('Address-1', $doc['customer_address']);
            $doc['grand_total'] = 87.50;
        }
        $this->remote[$type][$name] = $doc;
        if ($type === 'Sales Invoice' && $this->loseFirstInvoiceResponse) {
            $this->loseFirstInvoiceResponse = false;

            return Http::response([], 504);
        }

        return Http::response(['data' => $doc], 201);
    }

    private function insert(string $table, array $row): void
    {
        DB::connection('clubunio')->table('clubora.'.$table)->insert($row);
    }

    private function reject(callable $operation, int $status): void
    {
        try {
            $operation();
            $this->fail('Unexpectedly succeeded');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame($status, $e->getStatusCode());
        } catch (ModelNotFoundException) {
            $this->assertSame($status, 404);
        }
    }
}
