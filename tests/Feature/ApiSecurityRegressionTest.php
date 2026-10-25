<?php

namespace Tests\Feature;

use App\ClubUnio\Api\ApiContext;
use App\ClubUnio\Api\ApiTokenService;
use App\ClubUnio\Http\AuthenticateApiIntegration;
use App\ClubUnio\Models\AccessProfile;
use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\ApiAccessToken;
use App\ClubUnio\Models\ApiIntegration;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\ContributionInvoiceService;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ApiSecurityRegressionTest extends TestCase
{
    private Connection $db;

    private Tenant $tenant;

    private Account $admin;

    private AccessProfile $profile;

    private ApiIntegration $integration;

    private ApiContext $context;

    private string $plainToken;

    private bool $failRequestAudit = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Synthetic fixtures require explicit rollback-test opt-in.');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        Http::preventStrayRequests();
        Mail::fake();
        $this->tenant = Tenant::create(['name' => 'API security fixture', 'slug' => 'api-security-'.Str::random(14)]);
        $this->admin = new Account;
        $this->admin->forceFill(['username' => 'api-security-'.Str::random(20), 'display_name' => 'Synthetic security admin',
            'password' => bcrypt(Str::random(32)), 'active' => true, 'api_only' => false])->save();
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $this->tenant->id,
            'account_id' => $this->admin->id, 'role' => 'admin']);
        $this->profile = new AccessProfile;
        $this->profile->forceFill(['tenant_id' => $this->tenant->id, 'name' => 'Security fixture profile', 'active' => true,
            'permissions' => ['teams.view', 'teams.create', 'contributions.view', 'contributions.create', 'invoices.view', 'invoices.create']])->save();
        $service = app(ApiTokenService::class);
        $this->integration = $service->createIntegration($this->admin, $this->tenant, [
            'name' => 'Security fixture integration', 'access_profile_id' => $this->profile->id,
            'scopes' => ['teams:read', 'teams:create', 'contributions:read', 'contributions:create', 'invoices:create'],
            'rate_limit' => 600,
        ]);
        $issued = $service->issueToken($this->admin, $this->tenant, $this->integration->id,
            'Security fixture token', $this->integration->scopes, now()->addHour());
        $this->plainToken = $issued['token'];
        $this->context = new ApiContext($this->integration->account, $this->tenant, $this->integration,
            ApiAccessToken::findOrFail($issued['id']));
    }

    protected function tearDown(): void
    {
        $this->failRequestAudit = false;
        if (isset($this->db)) {
            while ($this->db->transactionLevel()) {
                $this->db->rollBack();
            }
        }
        parent::tearDown();
    }

    private function forbidden(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Authorization unexpectedly succeeded.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
    }

    public function test_previously_loaded_context_rejects_revoked_token(): void
    {
        $this->context->authorize('teams:read', 'teams', 'view');
        app(ApiTokenService::class)->revokeToken($this->admin, $this->tenant, $this->context->token->id);
        $this->assertNull($this->context->token->revoked_at);
        $this->forbidden(fn () => $this->context->authorize('teams:read', 'teams', 'view'));
        $this->forbidden(fn () => $this->context->authorizationFingerprint());
    }

    public function test_previously_loaded_context_rejects_disabled_integration(): void
    {
        $this->context->authorize('teams:read', 'teams', 'view');
        $this->db->table('clubora.api_integrations')->where('id', $this->integration->id)->update(['active' => false]);
        $this->assertTrue($this->context->integration->active);
        $this->forbidden(fn () => $this->context->authorize('teams:read', 'teams', 'view'));
    }

    public function test_fresh_technical_principal_and_membership_are_required(): void
    {
        $this->context->authorize('teams:read', 'teams', 'view');
        $this->db->table('clubora.accounts')->where('id', $this->context->actor->id)->update(['api_only' => false]);
        $this->assertTrue($this->context->actor->api_only);
        $this->forbidden(fn () => $this->context->authorize('teams:read', 'teams', 'view'));
        $this->db->table('clubora.accounts')->where('id', $this->context->actor->id)->update(['api_only' => true]);
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)
            ->where('account_id', $this->context->actor->id)->update(['role' => 'office', 'access_profile_id' => null]);
        $this->forbidden(fn () => $this->context->authorize('teams:read', 'teams', 'view'));
    }

    public function test_wildcards_and_stale_token_scopes_cannot_authorize(): void
    {
        $this->context->token->forceFill(['abilities' => ['*']])->save();
        $this->forbidden(fn () => $this->context->authorize('teams:read', 'teams', 'view'));
        $this->context->token->forceFill(['abilities' => $this->integration->scopes])->save();
        $this->db->table('clubora.api_access_tokens')->where('id', $this->context->token->id)
            ->update(['abilities' => json_encode(['teams:read'], JSON_THROW_ON_ERROR)]);
        $this->forbidden(fn () => $this->context->authorize('teams:create', 'teams', 'create'));
        $this->context->authorize('teams:read', 'teams', 'view');
    }

    public function test_demoted_admin_cannot_issue_token_using_loaded_admin_object(): void
    {
        $before = $this->integration->tokens()->count();
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)
            ->where('account_id', $this->admin->id)->update(['role' => 'office']);
        $this->forbidden(fn () => app(ApiTokenService::class)->issueToken($this->admin, $this->tenant,
            $this->integration->id, 'Must not issue', ['teams:read'], now()->addHour()));
        $this->assertSame($before, $this->integration->tokens()->count());
    }

    public static function changedNestedAuthority(): array
    {
        return ['profile permission removed' => ['profile'], 'token scope removed' => ['token'], 'integration ceiling reduced' => ['integration']];
    }

    #[DataProvider('changedNestedAuthority')]
    public function test_lost_nested_invoice_authority_rejects_completed_replay(string $change): void
    {
        $input = $this->contributionInput();
        $headers = ['Idempotency-Key' => 'security-contribution-replay'];
        $first = $this->withToken($this->plainToken)->postJson('/api/v1/contributions', $input, $headers)->assertCreated();
        $this->withToken($this->plainToken)->postJson('/api/v1/contributions', $input, $headers)
            ->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.id', $first->json('data.id'));
        if ($change === 'profile') {
            $this->profile->forceFill(['permissions' => array_values(array_diff($this->profile->permissions, ['invoices.create']))])->save();
        } elseif ($change === 'token') {
            $this->db->table('clubora.api_access_tokens')->where('id', $this->context->token->id)
                ->update(['abilities' => json_encode(array_values(array_diff($this->integration->scopes, ['invoices:create'])), JSON_THROW_ON_ERROR)]);
        } else {
            $this->db->table('clubora.api_integrations')->where('id', $this->integration->id)
                ->update(['scopes' => json_encode(array_values(array_diff($this->integration->scopes, ['invoices:create'])), JSON_THROW_ON_ERROR)]);
        }
        // The top-level contributions:create grant still exists; only nested invoice authority changed.
        $this->context->authorize('contributions:create', 'contributions', 'create');
        $response = $this->withToken($this->plainToken)->postJson('/api/v1/contributions', $input, $headers)
            ->assertConflict()->assertJsonMissingPath('data');
        $this->assertSame('conflict', $response->json('error.code'),
            'Unexpected error envelope; top-level keys: '.implode(', ', array_keys($response->json())));
        $this->assertSame(1, $this->db->table('clubora.invoices')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(1, $this->db->table('clubora.api_idempotency')->where('integration_id', $this->integration->id)->count());
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    private function insert(string $table, array $values): int
    {
        return $this->db->table('clubora.'.$table)->insertGetId(['tenant_id' => $this->tenant->id] + $values);
    }

    private function contributionInput(): array
    {
        $gender = $this->insert('genders', ['code' => 'N', 'name' => 'Synthetic']);
        $member = $this->insert('members', ['first_name' => 'Synthetic', 'last_name' => 'Security fixture', 'birth_date' => '2012-01-01', 'gender_id' => $gender]);
        $season = $this->insert('seasons', ['name' => 'Synthetic season']);
        $team = $this->insert('teams', ['name' => 'Synthetic team']);
        $assignment = $this->insert('team_seasons', ['team_id' => $team, 'season_id' => $season, 'label' => 'Synthetic assignment']);
        $this->insert('team_players', ['team_season_id' => $assignment, 'member_id' => $member]);
        $price = $this->insert('contribution_prices', ['name' => 'Synthetic tariff', 'amount' => '50.0000']);
        $this->insert('contribution_rules', ['season_id' => $season, 'price_id' => $price, 'gender_id' => $gender, 'year_from' => 2012, 'year_to' => 2012]);
        $this->insert('invoice_workflow_statuses', ['name' => 'Draft', 'code' => 'draft']);
        $this->insert('invoice_line_statuses', ['name' => 'Pending', 'code' => 'P']);
        $this->insert('invoice_categories', ['name' => 'Contribution', 'code' => 'contribution']);
        $preview = app(ContributionInvoiceService::class)->preview($this->context->actor, $this->tenant, $member, $season);

        return ['member_id' => $member, 'season_id' => $season, 'fingerprint' => $preview['fingerprint']];
    }

    public function test_request_audit_failure_preserves_write_and_logs_only_sanitized_identifiers(): void
    {
        Log::spy();
        $this->db->beforeExecuting(function (string $sql) {
            if ($this->failRequestAudit && str_starts_with($sql, 'insert into "clubora"."api_audit"')) {
                throw new RuntimeException('SYNTHETIC-SENSITIVE-EXCEPTION '.$this->plainToken);
            }
        });
        $this->failRequestAudit = true;
        try {
            $response = $this->withToken($this->plainToken)->postJson('/api/v1/teams', ['name' => 'Audit fallback fixture', 'active' => true],
                ['Idempotency-Key' => 'security-audit-failure'])->assertCreated()->assertHeader('X-Audit-Status', 'fallback')
                ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Request-ID');
        } finally {
            $this->failRequestAudit = false;
        }
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString($this->plainToken, $response->getContent());
        $this->assertStringNotContainsString('SYNTHETIC-SENSITIVE-EXCEPTION', $response->getContent());
        $this->assertSame(1, $this->db->table('clubora.teams')->where('tenant_id', $this->tenant->id)->where('name', 'Audit fallback fixture')->count());
        $this->assertSame('complete', $this->db->table('clubora.api_idempotency')->where('integration_id', $this->integration->id)->value('state'));
        Log::shouldHaveReceived('critical')->once()->with('ClubUnio API audit unavailable', [
            'request_id' => $response->headers->get('X-Request-ID'), 'integration_id' => $this->integration->id,
            'token_id' => $this->context->token->id, 'tenant_id' => $this->tenant->id, 'status' => 201,
        ]);
        Log::shouldNotHaveReceived('error');
        $this->withToken($this->plainToken)->postJson('/api/v1/teams', ['name' => 'Audit fallback fixture', 'active' => true],
            ['Idempotency-Key' => 'security-audit-failure'])->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
    }

    public function test_transactional_authorization_queries_request_shared_locks(): void
    {
        // Inspect lock requests only: another connection cannot see rollback-only fixture rows.
        $queries = [];
        $this->db->beforeExecuting(function (string $sql) use (&$queries) {
            $queries[] = $sql;
        });
        $this->context->authorize('teams:read', 'teams', 'view');
        app(ApiTokenService::class)->issueToken($this->admin, $this->tenant, $this->integration->id,
            'Shared-lock fixture', ['teams:read'], now()->addHour());
        $principal = array_values(array_filter($queries, fn ($sql) => str_contains($sql, '"api_integrations" as "integration"')));
        $admin = array_values(array_filter($queries, fn ($sql) => str_contains($sql, '"tenant_accounts" as "ta"') && str_contains($sql, '"accounts" as "a"')));
        $this->assertNotEmpty($principal);
        $this->assertNotEmpty($admin);
        $this->assertStringContainsString('for share', strtolower($principal[0]));
        $this->assertStringContainsString('for share', strtolower($admin[0]));
    }

    public function test_controller_database_exception_is_sanitized_before_rendering_and_logging(): void
    {
        config(['app.debug' => true]);
        $handler = new TestHandler;
        Log::swap(new Logger(new MonologLogger('api-security-regression', [$handler])));
        $secret = 'SYNTHETIC-PRIVATE-BINDING-'.Str::random(16);
        $sql = 'select * from synthetic_private_table where private_value = ?';
        Route::get('/api/v1/security-regression/db-error', function (Request $request) use ($secret, $sql) {
            $request->attributes->get('clubunio.api.context')->authorize('teams:read', 'teams', 'view');
            // Throw without executing SQL, so the enclosing fixture transaction remains usable.
            throw new QueryException('clubunio', $sql, [$secret], new RuntimeException('SYNTHETIC-RAW-DATABASE-MESSAGE'));
        })->middleware(AuthenticateApiIntegration::class);

        $response = $this->withToken($this->plainToken)->getJson('/api/v1/security-regression/db-error')
            ->assertStatus(500)->assertJsonPath('error.code', 'request_failed')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Request-ID');
        $this->assertSame(['error'], array_keys($response->json()));
        $this->assertSame(['code', 'message', 'request_id'], array_keys($response->json('error')));
        $this->assertSame($response->headers->get('X-Request-ID'), $response->json('error.request_id'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame('ERROR', $records[0]->level->getName());
        $this->assertSame('ClubUnio API failure', $records[0]->message);
        $this->assertSame(['request_id', 'exception', 'file', 'line'], array_keys($records[0]->context));
        $this->assertSame(QueryException::class, $records[0]->context['exception']);
        $this->assertSame($response->json('error.request_id'), $records[0]->context['request_id']);
        $this->assertSame(basename(__FILE__), $records[0]->context['file']);
        $this->assertIsInt($records[0]->context['line']);
        $logged = json_encode($records[0]->toArray(), JSON_THROW_ON_ERROR);
        foreach ([$secret, $sql, 'SYNTHETIC-RAW-DATABASE-MESSAGE', $this->plainToken] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
            $this->assertStringNotContainsString($private, $logged);
        }
        $this->assertSame(1, $this->db->table('clubora.api_audit')->where('tenant_id', $this->tenant->id)
            ->where('request_id', $response->json('error.request_id'))->where('status', 500)->count());
    }
}
