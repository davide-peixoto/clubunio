<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\AccountingProvider;
use App\ClubUnio\Services\BexioConnectionService;
use App\ClubUnio\Services\BexioOidcClient;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AccountingProviderTest extends TestCase
{
    private Account $admin;

    protected function setUp(): void
    {
        parent::setUp();
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('In-memory SQLite required');
        }
        config(['database.connections.clubunio' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('clubunio');
        $db = DB::connection('clubunio');
        $db->unprepared("ATTACH DATABASE ':memory:' AS clubora");
        $db->unprepared(<<<'SQL'
CREATE TABLE clubora.tenants (
    id INTEGER PRIMARY KEY, slug TEXT, name TEXT, accounting_provider TEXT DEFAULT 'none',
    created_at TEXT, updated_at TEXT
);
CREATE TABLE clubora.accounts (id INTEGER PRIMARY KEY, active INTEGER);
CREATE TABLE clubora.tenant_accounts (account_id INTEGER, tenant_id INTEGER, role TEXT);
CREATE TABLE clubora.tenant_settings (
    id INTEGER PRIMARY KEY, tenant_id INTEGER, area TEXT, key TEXT, label TEXT,
    value_type TEXT, is_secret INTEGER, enabled INTEGER, encrypted_value TEXT,
    created_at TEXT, updated_at TEXT
);
CREATE TABLE clubora.erpnext_invoice_transfers (tenant_id INTEGER, state TEXT);
CREATE TABLE clubora.bexio_sandbox_dispatches (tenant_id INTEGER, state TEXT);
SQL);
        $db->table('clubora.accounts')->insert(['id' => 1, 'active' => 1]);
        $this->admin = Account::findOrFail(1);
    }

    public function test_provider_choice_is_exclusive_and_scoped_to_each_tenant(): void
    {
        $bexio = $this->tenant('bexio');
        $erpnext = $this->tenant('erpnext');
        $neither = $this->tenant('neither');
        $service = app(AccountingProvider::class);
        $service->choose($this->admin, $bexio, 'bexio', 'none');
        $service->choose($this->admin, $erpnext, 'erpnext', 'none');

        $this->assertSame('bexio', AccountingProvider::current($bexio));
        $this->assertSame('erpnext', AccountingProvider::current($erpnext));
        $this->assertSame('none', AccountingProvider::current($neither));
        try {
            app(BexioConnectionService::class)->accessToken($erpnext);
            $this->fail('Inactive Bexio connection must not provide an access token.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
    }

    public function test_switch_from_erpnext_waits_for_pending_invoice_and_checks_the_previous_choice(): void
    {
        $tenant = $this->tenant('test');
        $service = app(AccountingProvider::class);
        $service->choose($this->admin, $tenant, 'erpnext', 'none');
        $transfers = DB::connection('clubunio')->table('clubora.erpnext_invoice_transfers');
        $transfers->insert(['tenant_id' => $tenant->id, 'state' => 'pending']);

        try {
            $service->choose($this->admin, $tenant, 'bexio', 'erpnext');
            $this->fail('Pending invoice must block provider switch.');
        } catch (ValidationException $exception) {
            $this->assertSame([trans('accounting_provider.open_transfers')], $exception->errors()['provider']);
        }

        $transfers->where('tenant_id', $tenant->id)->update(['state' => 'draft']);
        $service->choose($this->admin, $tenant, 'bexio', 'erpnext');
        $this->assertSame('bexio', AccountingProvider::current($tenant));
        $this->assertSame('draft', $transfers->where('tenant_id', $tenant->id)->value('state'));

        try {
            $service->choose($this->admin, $tenant, 'none', 'erpnext');
            $this->fail('Stale form must not change the provider.');
        } catch (ValidationException $exception) {
            $this->assertSame([trans('accounting_provider.changed')], $exception->errors()['provider']);
        }

        $this->assertSame('bexio', AccountingProvider::current($tenant));
    }

    public function test_only_active_bexio_tenant_can_refresh_its_existing_connection(): void
    {
        $active = $this->tenant('bexio');
        $inactive = $this->tenant('none');
        app(AccountingProvider::class)->choose($this->admin, $active, 'bexio', 'none');
        $connection = app(BexioConnectionService::class);
        $settings = DB::connection('clubunio')->table('clubora.tenant_settings');
        foreach ([$active, $inactive] as $tenant) {
            foreach (['BEXIO_CLIENT_ID' => 'fixture-client', 'BEXIO_CLIENT_SECRET' => 'fixture-secret'] as $key => $value) {
                $settings->insert(['tenant_id' => $tenant->id, 'area' => 'bexio', 'key' => $key, 'encrypted_value' => Crypt::encryptString($value)]);
            }
            $settings->insert([
                'tenant_id' => $tenant->id, 'area' => 'bexio', 'key' => BexioConnectionService::CONNECTION_KEY,
                'encrypted_value' => Crypt::encryptString(json_encode([
                    'fingerprint' => $connection->configuration($tenant)['fingerprint'],
                    'refresh_token' => 'fixture-refresh',
                ], JSON_THROW_ON_ERROR)),
            ]);
        }
        Http::preventStrayRequests();
        Http::fake([BexioOidcClient::TOKEN_ENDPOINT => Http::response([
            'access_token' => 'fixture-new-access', 'refresh_token' => 'fixture-new-refresh',
            'token_type' => 'Bearer', 'expires_in' => 3600,
        ])]);

        $this->assertSame('fixture-new-access', $connection->accessToken($active));
        $this->assertSame('fixture-new-refresh', $connection->connection($active)['refresh_token']);
        try {
            $connection->accessToken($inactive);
            $this->fail('A tenant with no provider cannot refresh a historical Bexio connection.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        Http::assertSentCount(1);
        $this->assertSame('fixture-refresh', $connection->connection($inactive)['refresh_token']);
    }

    public function test_open_bexio_transfer_blocks_provider_switch(): void
    {
        $tenant = $this->tenant('test');
        $service = app(AccountingProvider::class);
        $service->choose($this->admin, $tenant, 'bexio', 'none');
        DB::connection('clubunio')->table('clubora.bexio_sandbox_dispatches')
            ->insert(['tenant_id' => $tenant->id, 'state' => 'uncertain']);

        $this->expectException(ValidationException::class);
        $service->choose($this->admin, $tenant, 'erpnext', 'bexio');
    }

    private function tenant(string $slug): Tenant
    {
        $tenant = Tenant::create(['slug' => $slug, 'name' => 'Synthetic club']);
        DB::connection('clubunio')->table('clubora.tenant_accounts')
            ->insert(['tenant_id' => $tenant->id, 'account_id' => $this->admin->id, 'role' => 'admin']);

        return $tenant;
    }
}
