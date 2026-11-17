<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\AccountingProvider;
use App\ClubUnio\Services\ErpNextConfiguration;
use App\ClubUnio\Services\ErpNextExpenseTransferService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class ErpNextExpenseTransferTest extends TestCase
{
    private Account $actor;

    private Tenant $tenant;

    private array $remote = [];

    private bool $dropResponse = true;

    protected function setUp(): void
    {
        parent::setUp();
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('In-memory SQLite required');
        }
        config([
            'database.connections.clubunio' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'clubunio-erpnext.enabled' => true,
            'clubunio-erpnext.origin' => 'https://erp.example.test',
            'clubunio-erpnext.namespace' => 'test',
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        ]);
        DB::purge('clubunio');
        $db = DB::connection('clubunio');
        $db->statement("ATTACH DATABASE ':memory:' AS clubunio");
        foreach ([
            'tenants' => 'id INTEGER PRIMARY KEY, active BOOLEAN, slug TEXT, accounting_provider TEXT, updated_at TEXT',
            'accounts' => 'id INTEGER PRIMARY KEY, active BOOLEAN, api_only BOOLEAN',
            'tenant_accounts' => 'tenant_id INTEGER, account_id INTEGER, role TEXT',
            'tenant_settings' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, area TEXT, key TEXT, label TEXT, enabled BOOLEAN, value_type TEXT, is_secret BOOLEAN, encrypted_value TEXT, created_at TEXT, updated_at TEXT',
            'setting_changes' => 'tenant_id INTEGER, setting_id INTEGER, actor_id INTEGER',
            'members' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, first_name TEXT, last_name TEXT, company TEXT, street TEXT, email TEXT',
            'expense_statuses' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, code TEXT, active BOOLEAN',
            'expense_types' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, name TEXT',
            'expenses' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, member_id INTEGER, status_id INTEGER, revision INTEGER, active BOOLEAN, payment_released_on TEXT, paid_on TEXT, legacy_id INTEGER, bexio_bill_id TEXT',
            'expense_lines' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, expense_id INTEGER, expense_type_id INTEGER, amount NUMERIC, active BOOLEAN, storage_reference TEXT',
            'erpnext_expense_transfers' => "tenant_id INTEGER, expense_id INTEGER PRIMARY KEY, source_revision INTEGER, reference TEXT UNIQUE, origin TEXT, total_cents INTEGER, remote_id TEXT, state TEXT DEFAULT 'pending', created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT",
            'erpnext_invoice_transfers' => 'tenant_id INTEGER, state TEXT',
            'bexio_sandbox_dispatches' => 'tenant_id INTEGER, expense_id INTEGER, kind TEXT',
        ] as $table => $columns) {
            $db->statement('CREATE TABLE clubunio.'.$table.' ('.$columns.')');
        }
        foreach ([1, 2] as $id) {
            $this->table('tenants')->insert(['id' => $id, 'active' => true, 'slug' => 'club-'.$id, 'accounting_provider' => 'erpnext']);
            $this->table('tenant_accounts')->insert(['account_id' => 1, 'tenant_id' => $id, 'role' => 'admin']);
        }
        $this->table('accounts')->insert(['id' => 1, 'active' => true, 'api_only' => false]);
        $this->table('members')->insert(['id' => 1, 'tenant_id' => 1, 'first_name' => 'Synthetic', 'last_name' => 'Member',
            'email' => 'private@example.test']);
        $this->table('expense_statuses')->insert([
            ['id' => 1, 'tenant_id' => 1, 'code' => 'Z', 'active' => true],
            ['id' => 2, 'tenant_id' => 1, 'code' => 'F', 'active' => true],
        ]);
        $this->table('expense_types')->insert(['id' => 1, 'tenant_id' => 1, 'name' => 'Transport']);
        $this->table('expenses')->insert(['id' => 1, 'tenant_id' => 1, 'member_id' => 1, 'status_id' => 1,
            'revision' => 3, 'active' => true, 'payment_released_on' => '2026-11-16']);
        foreach ([1, 2] as $id) {
            $this->table('expense_lines')->insert(['id' => $id, 'tenant_id' => 1, 'expense_id' => 1,
                'expense_type_id' => 1, 'amount' => 10.115, 'active' => true, 'storage_reference' => 'private-receipt']);
        }
        $this->actor = Account::findOrFail(1);
        $this->tenant = Tenant::findOrFail(1);
        app(ErpNextConfiguration::class)->save($this->actor, $this->tenant, [
            'company' => 'Sandbox', 'company_address' => 'Sandbox-Billing', 'territory' => 'Switzerland',
            'customer_group' => 'Individual', 'item_code' => 'DUES', 'income_account' => 'Membership',
            'supplier_group' => 'Services', 'expense_item_code' => 'EXPENSE', 'expense_account' => 'Reimbursements',
            'due_days' => 30, 'api_key' => 'fixture-key', 'api_secret' => 'fixture-secret',
        ]);
        Http::preventStrayRequests();
        Http::fake(['https://erp.example.test/*' => $this->handleRemote(...)]);
    }

    public function test_approved_expense_creates_one_unpaid_draft_and_refreshes_read_only(): void
    {
        $service = app(ErpNextExpenseTransferService::class);
        $this->reject(fn () => $service->transfer($this->actor, $this->tenant, 1, 3), 503);
        $this->assertSame('pending', $service::query($this->tenant)->value('state'));
        try {
            app(AccountingProvider::class)->choose($this->actor, $this->tenant, 'bexio', 'erpnext');
            $this->fail('An unconfirmed payable must block a provider change.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('provider', $e->errors());
        }
        $draft = $service->transfer($this->actor, $this->tenant, 1, 3);
        $this->assertSame('draft', $draft->state);
        $this->assertSame(2023, (int) $draft->total_cents);
        $this->assertCount(1, $this->remote['Supplier']);
        $this->assertCount(1, $this->remote['Purchase Invoice']);
        $this->assertSame($draft->remote_id, $service->transfer($this->actor, $this->tenant, 1, 3)->remote_id);
        $this->remote['Purchase Invoice'][$draft->remote_id]['docstatus'] = 1;
        foreach ([['20.23', 'open'], ['5.00', 'part_paid'], ['0.00', 'paid']] as [$outstanding, $state]) {
            $this->remote['Purchase Invoice'][$draft->remote_id]['outstanding_amount'] = $outstanding;
            $this->assertSame($state, $service->refresh($this->actor, $this->tenant, 1)->state);
        }
        $this->assertNull($this->table('expenses')->where('id', 1)->value('paid_on'));
        $this->assertSame(1, (int) $this->table('expenses')->where('id', 1)->value('status_id'));
        $this->assertCount(2, Http::recorded(fn (Request $request) => $request->method() === 'POST'));
    }

    public function test_rejects_wrong_tenant_wrong_provider_unapproved_and_already_linked_before_http(): void
    {
        $service = app(ErpNextExpenseTransferService::class);
        $this->reject(fn () => $service->transfer($this->actor, Tenant::findOrFail(2), 1, 3), 404);
        $this->table('expenses')->where('id', 1)->update(['status_id' => 2]);
        $this->reject(fn () => $service->transfer($this->actor, $this->tenant, 1, 3), 409);
        $this->table('expenses')->where('id', 1)->update(['status_id' => 1, 'bexio_bill_id' => 'existing-bill']);
        $this->reject(fn () => $service->transfer($this->actor, $this->tenant, 1, 3), 409);
        $this->table('expenses')->where('id', 1)->update(['bexio_bill_id' => null]);
        $this->table('tenants')->where('id', 1)->update(['accounting_provider' => 'bexio']);
        $this->reject(fn () => $service->transfer($this->actor, $this->tenant, 1, 3), 409);
        Http::assertNothingSent();
    }

    private function handleRemote(Request $request)
    {
        $parts = explode('/', trim(parse_url($request->url(), PHP_URL_PATH), '/'));
        $type = rawurldecode($parts[2]);
        $this->assertContains($type, ['Supplier', 'Purchase Invoice']);
        if (isset($parts[3])) {
            return Http::response(['data' => $this->remote[$type][rawurldecode($parts[3])]]);
        }
        if ($request->method() === 'GET') {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            $reference = json_decode($query['filters'], true)[0][2];
            $matching = array_filter($this->remote[$type] ?? [], fn ($row) => $row['custom_clubunio_reference'] === $reference);

            return Http::response(['data' => array_values($matching)]);
        }
        $this->assertSame('POST', $request->method());
        $data = $request->data();
        $this->assertStringNotContainsString('private-receipt', $request->body());
        $this->assertStringNotContainsString('private@example.test', $request->body());
        $name = $type.'-1';
        if ($type === 'Purchase Invoice') {
            $this->assertSame(0, $data['docstatus']);
            $this->assertSame(0, $data['is_paid']);
            $this->assertSame(0, $data['update_stock']);
            $this->assertSame('CHF', $data['currency']);
            $this->assertSame('Supplier-1', $data['supplier']);
            $this->assertSame(['10.12', '10.11'], array_column($data['items'], 'rate'));
            $this->assertSame([], $data['taxes']);
            $data['grand_total'] = 20.23;
        }
        $this->remote[$type][$name] = $data + ['name' => $name];
        if ($type === 'Purchase Invoice' && $this->dropResponse) {
            $this->dropResponse = false;

            return Http::response([], 504);
        }

        return Http::response(['data' => $this->remote[$type][$name]], 201);
    }

    private function table(string $table)
    {
        return DB::connection('clubunio')->table('clubunio.'.$table);
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
