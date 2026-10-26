<?php

namespace Tests\Feature;

use App\ClubUnio\Api\ApiContext;
use App\ClubUnio\Api\ApiTokenService;
use App\ClubUnio\Api\Providers\BexioApiActions;
use App\ClubUnio\Models\AccessProfile;
use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\ApiAccessToken;
use App\ClubUnio\Models\ApiIntegration;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\BackofficeReadContext;
use App\ClubUnio\Services\BexioSandboxDispatchService;
use App\ClubUnio\Services\BexioSandboxFixtureService;
use App\ClubUnio\Services\SyncWorkService;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BexioApiActionsTest extends TestCase
{
    private Connection $db;

    private Tenant $tenant;

    private Account $admin;

    private AccessProfile $profile;

    private ApiIntegration $integration;

    private ApiContext $context;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Opt-in PostgreSQL rollback fixtures; run serially.');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('pgsql', $this->db->getDriverName());
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        Http::preventStrayRequests();
        Mail::fake();
        $this->tenant = Tenant::create(['name' => 'Bexio API fixture', 'slug' => 'bexio-api-'.Str::random(16)]);
        $this->admin = new Account;
        $this->admin->forceFill(['username' => 'bexio-api-'.Str::random(20), 'display_name' => 'Synthetic admin',
            'password' => bcrypt('fixture'), 'active' => true, 'api_only' => false])->save();
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $this->tenant->id,
            'account_id' => $this->admin->id, 'role' => 'admin']);
        $scopes = [];
        foreach (app(BexioApiActions::class)->definitions() as $definition) {
            $scopes += $definition['scopes'];
        }
        $this->profile = new AccessProfile;
        $this->profile->forceFill(['tenant_id' => $this->tenant->id, 'name' => 'Bexio API fixture', 'active' => true,
            'permissions' => array_values(array_unique(array_map(fn ($right) => implode('.', $right), $scopes)))])->save();
        $tokens = app(ApiTokenService::class);
        // Fixture scopes are explicit; this adapter does not modify the shared scope registry.
        $this->integration = $tokens->createIntegration($this->admin, $this->tenant, ['name' => 'Bexio fixture',
            'access_profile_id' => $this->profile->id, 'scopes' => ['invoices:read'], 'rate_limit' => 600]);
        $issued = $tokens->issueToken($this->admin, $this->tenant, $this->integration->id,
            'Fixture', ['invoices:read'], now()->addHour());
        $this->integration->forceFill(['scopes' => array_keys($scopes)])->save();
        $token = ApiAccessToken::findOrFail($issued['id']);
        $token->forceFill(['abilities' => array_keys($scopes)])->save();
        $this->context = new ApiContext($this->integration->account, $this->tenant, $this->integration, $token);
        $member = $this->db->table('clubora.members')->insertGetId(['tenant_id' => $this->tenant->id, 'first_name' => 'Synthetic', 'last_name' => 'Bexio API']);
        $invoice = $this->db->table('clubora.invoices')->insertGetId([
            'tenant_id' => $this->tenant->id, 'member_id' => $member, 'active' => true, 'bexio_invoice_id' => '123']);
        $this->invoice = Invoice::findOrFail($invoice);
        $this->mock(BexioSandboxFixtureService::class, fn ($m) => $m->shouldReceive('optionalConnectionHash')->andReturn('fixture'));
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            while ($this->db->transactionLevel()) {
                $this->db->rollBack();
            }
        }
        parent::tearDown();
    }

    private function rejects(int $status, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected rejection.');
        } catch (HttpException $error) {
            $this->assertSame($status, $error->getStatusCode());
        }
    }

    private function adapter(): BexioApiActions
    {
        return app(BexioApiActions::class);
    }

    private function dispatch(string $state = 'accepted'): int
    {
        return $this->db->table('clubora.bexio_sandbox_dispatches')->insertGetId([
            'tenant_id' => $this->tenant->id, 'actor_id' => $this->admin->id, 'kind' => 'invoice',
            'invoice_id' => $this->invoice->id, 'reference' => (string) Str::uuid(), 'connection_hash' => 'fixture',
            'snapshot_hash' => 'fixture', 'payload' => '{"secret":"never return"}', 'state' => $state, 'remote_id' => '123',
        ]);
    }

    private function invoiceAction(int $dispatch, string $operation, string $state): void
    {
        $this->db->table('clubora.bexio_invoice_actions')->insert(['dispatch_id' => $dispatch, 'actor_id' => $this->admin->id,
            'operation' => $operation, 'state' => $state, 'connection_hash' => 'fixture', 'before_status' => 7]);
    }

    private function fakeExecution(string $operation, array $payload, mixed $result): void
    {
        // Only the boundary check is mocked: all fixture writes still use the outer rollback.
        // SyncWork::run is always mocked, so no provider effect can escape that transaction.
        $connection = Mockery::mock($this->db);
        $connection->shouldReceive('transactionLevel')->andReturn(0);
        DB::shouldReceive('connection')->with('clubunio')->andReturn($connection);
        $this->partialMock(SyncWorkService::class, function ($m) use ($operation, $payload, $result) {
            $m->shouldReceive('run')->once()->withArgs(fn ($actor, $tenant, $op, $id, $data) => $actor->id === $this->admin->id && ! $actor->api_only && $tenant->id === $this->tenant->id
                && $op === $operation && $data === $payload)->andReturn($result);
        });
    }

    public function test_definitions_are_explicit_admin_only_and_do_not_offer_payments_or_dispatch_retries(): void
    {
        $definitions = $this->adapter()->definitions();
        $this->assertSame(['bexio.invoice-transfer', 'bexio.invoice-issue', 'bexio.invoice-cancel', 'bexio.invoice-status',
            'bexio.invoice-sync', 'bexio.expense-status', 'bexio.expense-sync', 'bexio.catalog-sync', 'bexio.invoice-reconcile'], array_keys($definitions));
        foreach ($definitions as $action => $definition) {
            $this->assertSame('admin', $definition['approval']);
            $this->assertSame(['bexio', 'view'], $definition['scopes']['bexio:read']);
            $this->assertSame($action === 'bexio.invoice-transfer' ? ['revision'] : [], array_keys($definition['fields']));
        }
        foreach (['invoice-issue' => 'send', 'invoice-cancel' => 'cancel'] as $action => $function) {
            $this->assertSame(['invoices', $function], $definitions['bexio.'.$action]['scopes']['invoices:'.$function]);
            $this->assertSame(['invoices', 'sync'], $definitions['bexio.'.$action]['scopes']['invoices:sync']);
            $this->assertSame(['bexio', 'sync'], $definitions['bexio.'.$action]['scopes']['bexio:sync']);
        }
    }

    public function test_stage_does_not_call_admin_only_services_or_change_the_technical_role(): void
    {
        $this->mock(BexioSandboxDispatchService::class, fn ($m) => $m->shouldNotReceive('authorize', 'statusDocument', 'readStatus'));
        $this->assertSame([], $this->adapter()->validate($this->context, 'bexio.invoice-status', $this->invoice->id, []));
        $this->assertFalse(app(ApiTokenService::class)->isAdmin($this->context->actor, $this->tenant));
        $this->assertSame('profile', $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)
            ->where('account_id', $this->context->actor->id)->value('role'));
        $this->assertSame(0, $this->db->table('clubora.sync_work_items')->where('tenant_id', $this->tenant->id)->count());
        Http::assertNothingSent();
    }

    public static function scopeCases(): array
    {
        return array_map(fn ($scope) => [$scope], ['bexio:read', 'bexio:sync', 'invoices:read', 'invoices:sync']);
    }

    #[DataProvider('scopeCases')]
    public function test_each_compound_scope_is_rechecked_freshly(string $scope): void
    {
        $this->adapter()->validate($this->context, 'bexio.invoice-status', $this->invoice->id, []);
        $this->context->token->forceFill(['abilities' => array_values(array_diff($this->context->token->abilities, [$scope]))])->save();
        $this->rejects(403, fn () => $this->adapter()->validate($this->context, 'bexio.invoice-status', $this->invoice->id, []));
    }

    public function test_profile_permission_changes_bypass_stale_read_snapshots(): void
    {
        $permissions = $this->profile->permissions;
        request()->attributes->set(BackofficeReadContext::class, ['account' => $this->context->actor->id,
            'tenants' => collect(), 'permissionCache' => ['permissions:'.$this->tenant->id => $permissions]]);
        $this->profile->forceFill(['permissions' => array_values(array_diff($permissions, ['bexio.sync']))])->save();
        $this->rejects(403, fn () => $this->adapter()->validate($this->context, 'bexio.invoice-status', $this->invoice->id, []));
    }

    public static function revokedCases(): array
    {
        return [['token'], ['integration'], ['profile'], ['actor'], ['membership'], ['ceiling']];
    }

    #[DataProvider('revokedCases')]
    public function test_context_revocations_are_not_cached(string $kind): void
    {
        $this->adapter()->validate($this->context, 'bexio.invoice-status', $this->invoice->id, []);
        match ($kind) {
            'token' => $this->context->token->forceFill(['revoked_at' => now()])->save(),
            'integration' => $this->integration->forceFill(['active' => false])->save(),
            'profile' => $this->profile->forceFill(['active' => false])->save(),
            'actor' => $this->context->actor->forceFill(['active' => false])->save(),
            'membership' => $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)
                ->where('account_id', $this->context->actor->id)->update(['role' => 'office', 'access_profile_id' => null]),
            'ceiling' => $this->integration->forceFill(['scopes' => []])->save(),
        };
        $this->rejects(403, fn () => $this->adapter()->validate($this->context, 'bexio.invoice-status', $this->invoice->id, []));
    }

    public static function protectedFields(): array
    {
        return [['id'], ['tenant_id'], ['operation'], ['executor'], ['_request_key'], ['revision']];
    }

    #[DataProvider('protectedFields')]
    public function test_stage_rejects_unknown_and_protected_fields(string $field): void
    {
        $this->expectException(ValidationException::class);
        $this->adapter()->validate($this->context, 'bexio.invoice-status', $this->invoice->id, [$field => 1]);
    }

    public function test_tenant_active_record_and_catalog_object_boundaries(): void
    {
        $other = Tenant::create(['name' => 'Other Bexio fixture', 'slug' => 'bexio-other-'.Str::random(14)]);
        $member = $this->db->table('clubora.members')->insertGetId(['tenant_id' => $other->id, 'first_name' => 'Foreign fixture']);
        $foreignId = $this->db->table('clubora.invoices')->insertGetId([
            'tenant_id' => $other->id, 'member_id' => $member, 'active' => true]);
        $foreign = Invoice::findOrFail($foreignId);
        try {
            $this->adapter()->validate($this->context, 'bexio.invoice-status', $foreign->id, []);
            $this->fail('Foreign document must be hidden.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }
        $this->rejects(404, fn () => $this->adapter()->validate($this->context, 'bexio.catalog-sync', $other->id, []));
        $this->assertSame([], $this->adapter()->validate($this->context, 'bexio.catalog-sync', $this->tenant->id, []));
        $this->invoice->forceFill(['active' => false])->save();
        $this->expectException(ModelNotFoundException::class);
        $this->adapter()->validate($this->context, 'bexio.invoice-status', $this->invoice->id, []);
    }

    public function test_transfer_requires_revision_and_local_internal_release_unless_resuming_existing_dispatch(): void
    {
        $this->rejects(409, fn () => $this->adapter()->validate($this->context, 'bexio.invoice-transfer', $this->invoice->id, ['revision' => 1]));
        $workflow = $this->db->table('clubora.invoice_workflow_statuses')->insertGetId([
            'tenant_id' => $this->tenant->id, 'name' => 'Released fixture', 'code' => 'released', 'active' => true,
        ]);
        $this->invoice->forceFill(['bexio_invoice_id' => null, 'workflow_status_id' => $workflow])->save();
        $revision = (int) $this->invoice->revision;
        $this->assertSame(['revision' => $revision], $this->adapter()->validate($this->context,
            'bexio.invoice-transfer', $this->invoice->id, ['revision' => (string) $revision]));
        $this->rejects(409, fn () => $this->adapter()->validate($this->context, 'bexio.invoice-transfer',
            $this->invoice->id, ['revision' => $revision + 1]));
        $this->dispatch('uncertain');
        $this->assertSame(['revision' => $revision + 1], $this->adapter()->validate($this->context,
            'bexio.invoice-transfer', $this->invoice->id, ['revision' => $revision + 1]));
        $this->expectException(ValidationException::class);
        $this->adapter()->validate($this->context, 'bexio.invoice-transfer', $this->invoice->id, []);
    }

    public function test_execution_rejects_an_open_sql_transaction_before_any_provider_work(): void
    {
        $this->mock(SyncWorkService::class, fn ($m) => $m->shouldNotReceive('remember', 'run'));
        $this->rejects(409, fn () => $this->adapter()->execute($this->context, 'bexio.invoice-status', $this->invoice->id, [], $this->admin));
    }

    public static function executorCases(): array
    {
        return [['missing'], ['technical'], ['demoted'], ['disabled'], ['foreign'], ['became_technical']];
    }

    #[DataProvider('executorCases')]
    public function test_execution_requires_an_explicit_fresh_real_admin(string $kind): void
    {
        $connection = Mockery::mock($this->db);
        $connection->shouldReceive('transactionLevel')->andReturn(0);
        DB::shouldReceive('connection')->with('clubunio')->andReturn($connection);
        $executor = $this->admin;
        if ($kind === 'missing') {
            $executor = null;
        } elseif ($kind === 'technical') {
            $executor = $this->context->actor;
        } elseif (in_array($kind, ['demoted', 'foreign'], true)) {
            $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)
                ->where('account_id', $this->admin->id)->update(['role' => 'office']);
            if ($kind === 'foreign') {
                $other = Tenant::create(['name' => 'Foreign admin club', 'slug' => 'bexio-admin-'.Str::random(14)]);
                $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $other->id, 'account_id' => $this->admin->id, 'role' => 'admin']);
            }
        } else {
            $this->db->table('clubora.accounts')->where('id', $this->admin->id)
                ->update($kind === 'disabled' ? ['active' => false] : ['api_only' => true]);
        }
        $this->mock(SyncWorkService::class, fn ($m) => $m->shouldNotReceive('remember', 'run'));
        $this->rejects(403, fn () => $this->adapter()->execute($this->context, 'bexio.invoice-status', $this->invoice->id, [], $executor));
    }

    public function test_transfer_preserves_uncertainty_and_strips_internal_key_and_provider_secrets(): void
    {
        $dispatch = $this->dispatch('uncertain');
        $this->fakeExecution('invoice_transfer', ['revision' => 1], (object) ['state' => 'uncertain', 'payload' => 'secret', 'url' => 'secret']);
        $result = $this->adapter()->execute($this->context, 'bexio.invoice-transfer', $this->invoice->id,
            ['revision' => 1, '_request_key' => (string) Str::uuid()], $this->admin);
        $this->assertSame(['id' => $this->invoice->id, 'sync_work_id' => $result['sync_work_id'],
            'dispatch_id' => $dispatch, 'state' => 'uncertain', 'confirmed' => false], $result);
        Http::assertNothingSent();
    }

    public static function statusCases(): array
    {
        return [['invoice-status', 'ready', true], ['invoice-sync', 'applied', true], ['expense-status', 'unchanged', true],
            ['expense-sync', 'failed', false], ['invoice-status', 'disabled', false]];
    }

    #[DataProvider('statusCases')]
    public function test_status_results_are_allowlisted_and_confirmation_is_explicit(string $action, string $state, bool $confirmed): void
    {
        $kind = str_starts_with($action, 'invoice') ? 'invoice' : 'expense';
        $id = $kind === 'invoice' ? $this->invoice->id : $this->db->table('clubora.expenses')->insertGetId([
            'tenant_id' => $this->tenant->id, 'member_id' => $this->invoice->member_id, 'active' => true]);
        $check = $this->db->table('clubora.bexio_checks')->insertGetId(['tenant_id' => $this->tenant->id, 'actor_id' => $this->admin->id,
            'object_type' => $kind, 'object_id' => $id, 'reference' => (string) Str::uuid(), 'remote_id' => '123',
            'remote_status' => $kind === 'invoice' ? '8' : 'PAID', 'fingerprint' => 'fixture', 'state' => $state,
            'message' => 'private provider body', 'proposal' => '{"secret":"private"}']);
        $this->fakeExecution(str_replace('-', '_', $action), [], $check);
        $result = $this->adapter()->execute($this->context, 'bexio.'.$action, $id, [], $this->admin);
        $this->assertSame(['id', 'sync_work_id', 'check_id', 'state', 'status', 'confirmed'], array_keys($result));
        $this->assertSame($check, $result['check_id']);
        $this->assertSame($state, $result['state']);
        $this->assertSame($confirmed, $result['confirmed']);
        $this->assertStringNotContainsString('private', json_encode($result));
    }

    public static function actionCases(): array
    {
        return [['invoice-issue', 'issue', 'accepted', true], ['invoice-cancel', 'cancel', 'uncertain', false],
            ['invoice-reconcile', 'issue', 'uncertain', false], ['invoice-reconcile', 'issue', 'accepted', true]];
    }

    #[DataProvider('actionCases')]
    public function test_invoice_actions_use_syncwork_and_require_confirmed_journal_evidence(string $action, string $operation, string $state, bool $confirmed): void
    {
        $dispatch = $this->dispatch();
        $this->invoiceAction($dispatch, $operation, $state);
        $reconcile = $action === 'invoice-reconcile';
        $this->fakeExecution($reconcile ? 'invoice_result' : 'invoice_action', $reconcile ? [] : ['operation' => $operation], null);
        $result = $this->adapter()->execute($this->context, 'bexio.'.$action, $this->invoice->id, [], $this->admin);
        $this->assertSame($confirmed, $result['confirmed']);
        $this->assertSame($confirmed ? 'accepted' : 'uncertain', $result['state']);
        $this->assertSame($dispatch, $result['dispatch_id']);
    }

    public function test_catalog_sync_is_tenant_bound_and_minimal(): void
    {
        $this->fakeExecution('catalog', [], 4);
        $result = $this->adapter()->execute($this->context, 'bexio.catalog-sync', $this->tenant->id, [], $this->admin);
        $this->assertSame(['id' => $this->tenant->id, 'sync_work_id' => $result['sync_work_id'], 'state' => 'synchronized', 'confirmed' => true], $result);
    }

    public function test_unknown_actions_and_untransferred_invoice_actions_are_rejected(): void
    {
        $this->rejects(404, fn () => $this->adapter()->validate($this->context, 'bexio.payment', $this->invoice->id, []));
        foreach (['invoice-issue', 'invoice-cancel', 'invoice-reconcile'] as $action) {
            $this->rejects(409, fn () => $this->adapter()->validate($this->context, 'bexio.'.$action, $this->invoice->id, []));
        }
    }

    public function test_execution_revalidates_the_original_technical_context(): void
    {
        $this->adapter()->validate($this->context, 'bexio.invoice-status', $this->invoice->id, []);
        $this->context->token->forceFill(['revoked_at' => now()])->save();
        $connection = Mockery::mock($this->db);
        $connection->shouldReceive('transactionLevel')->andReturn(0);
        DB::shouldReceive('connection')->with('clubunio')->andReturn($connection);
        $this->mock(SyncWorkService::class, fn ($m) => $m->shouldNotReceive('remember', 'run'));
        $this->rejects(403, fn () => $this->adapter()->execute($this->context, 'bexio.invoice-status', $this->invoice->id,
            ['_request_key' => (string) Str::uuid()], $this->admin));
    }

    public static function blockedWorkCases(): array
    {
        return [['running'], ['attention'], ['closed']];
    }

    #[DataProvider('blockedWorkCases')]
    public function test_existing_started_or_attention_work_is_not_generically_retried(string $state): void
    {
        $this->dispatch('uncertain');
        $sync = app(SyncWorkService::class);
        $id = $sync->remember($this->admin, $this->tenant, 'invoice_transfer', $this->invoice->id, ['revision' => 1]);
        $sync->table()->where('id', $id)->update(['state' => $state]);
        $connection = Mockery::mock($this->db);
        $connection->shouldReceive('transactionLevel')->andReturn(0);
        DB::shouldReceive('connection')->with('clubunio')->andReturn($connection);
        $this->partialMock(SyncWorkService::class, fn ($m) => $m->shouldNotReceive('run'));
        $this->rejects(409, fn () => $this->adapter()->execute($this->context, 'bexio.invoice-transfer',
            $this->invoice->id, ['revision' => 1], $this->admin));
    }

    public function test_explicit_invoice_reconciliation_can_recheck_attention_but_never_running_work(): void
    {
        $dispatch = $this->dispatch();
        $this->invoiceAction($dispatch, 'issue', 'uncertain');
        $sync = app(SyncWorkService::class);
        $id = $sync->remember($this->admin, $this->tenant, 'invoice_result', $this->invoice->id, []);
        $sync->table()->where('id', $id)->update(['state' => 'attention']);
        $this->fakeExecution('invoice_result', [], null);
        $result = $this->adapter()->execute($this->context, 'bexio.invoice-reconcile', $this->invoice->id, [], $this->admin);
        $this->assertFalse($result['confirmed']);
        $sync->table()->where('id', $id)->update(['state' => 'running']);
        $this->rejects(409, fn () => $this->adapter()->execute($this->context, 'bexio.invoice-reconcile', $this->invoice->id, [], $this->admin));
    }

    public function test_provider_exception_is_propagated_once_without_retry_or_false_confirmation(): void
    {
        $this->dispatch('uncertain');
        $connection = Mockery::mock($this->db);
        $connection->shouldReceive('transactionLevel')->andReturn(0);
        DB::shouldReceive('connection')->with('clubunio')->andReturn($connection);
        $failure = new \RuntimeException('Synthetic uncertain provider result');
        $this->partialMock(SyncWorkService::class, fn ($m) => $m->shouldReceive('run')->once()->andThrow($failure));
        try {
            $this->adapter()->execute($this->context, 'bexio.invoice-transfer', $this->invoice->id, ['revision' => 1], $this->admin);
            $this->fail('Provider failure must propagate.');
        } catch (\RuntimeException $error) {
            $this->assertSame($failure, $error);
        }
    }
}
