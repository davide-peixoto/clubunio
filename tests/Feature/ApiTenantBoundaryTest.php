<?php

namespace Tests\Feature;

use App\ClubUnio\Api\ApiContext;
use App\ClubUnio\Api\ApiTokenService;
use App\ClubUnio\Api\Modules\ApiModuleRegistry;
use App\ClubUnio\Models\{AccessProfile, Account, ApiIntegration, Tenant};
use App\ClubUnio\Services\PermissionCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Http, Mail};
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiTenantBoundaryTest extends TestCase
{
    private $db;
    private Tenant $one;
    private Tenant $two;
    private Account $admin;
    private ApiIntegration $integration;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Explicit rollback-test opt-in required.');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        Http::preventStrayRequests();
        Mail::fake();
        $this->one = Tenant::create(['name' => 'API boundary one', 'slug' => 'api-boundary-'.Str::random(16)]);
        $this->two = Tenant::create(['name' => 'API boundary two', 'slug' => 'api-boundary-'.Str::random(16)]);
        $this->admin = new Account;
        $this->admin->forceFill(['username' => Str::random(30), 'display_name' => 'Synthetic boundary admin',
            'password' => bcrypt(Str::random(30)), 'active' => true, 'api_only' => false])->save();
        $this->admin->tenants()->attach($this->one, ['role' => 'admin']);
        $permissions = [];
        foreach (array_keys(PermissionCatalog::MODULES) as $module) {
            foreach (array_keys(PermissionCatalog::functions($module)) as $function) {
                $permissions[] = $module.'.'.$function;
            }
        }
        $profile = new AccessProfile;
        $profile->forceFill(['tenant_id' => $this->one->id, 'name' => 'Synthetic full profile',
            'active' => true, 'permissions' => $permissions])->save();
        $service = app(ApiTokenService::class);
        $this->integration = $service->createIntegration($this->admin, $this->one, ['name' => 'Synthetic boundary integration',
            'access_profile_id' => $profile->id, 'scopes' => array_keys($service->scopes()), 'rate_limit' => 600]);
        $this->token = $service->issueToken($this->admin, $this->one, $this->integration->id,
            'Synthetic boundary token', $this->integration->scopes, now()->addHour())['token'];
        config(['clubunio-domains.hosts' => ['one.api.test' => $this->one->slug, 'two.api.test' => $this->two->slug]]);
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

    public function test_domain_restriction_is_inside_authenticated_audit_envelope(): void
    {
        $this->withToken($this->token)->getJson('https://one.api.test/api/v1/context')->assertOk()
            ->assertJsonPath('data.tenant.id', $this->one->id);
        $this->withToken($this->token)->getJson('https://two.api.test/api/v1/context')->assertNotFound()
            ->assertHeader('X-Request-ID')->assertJsonMissingPath('data');
        $this->withToken($this->token)->getJson('https://unknown.api.test/api/v1/context')->assertNotFound();
        $this->withToken($this->token)->getJson('http://localhost/api/v1/context')->assertOk()
            ->assertJsonPath('data.tenant.id', $this->one->id);
        $this->assertTrue($this->db->table('clubora.api_audit')->where('tenant_id', $this->one->id)
            ->where('integration_id', $this->integration->id)->where('status', 404)->exists());
        $this->assertFalse($this->db->table('clubora.api_audit')->where('tenant_id', $this->two->id)->exists());
    }

    public function test_every_versioned_api_route_has_authentication_then_domain_binding(): void
    {
        $count = 0;
        foreach (app('router')->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            $count++;
            $middleware = $route->gatherMiddleware();
            $auth = array_search(\App\ClubUnio\Http\AuthenticateApiIntegration::class, $middleware, true);
            $domain = array_search(\App\ClubUnio\Http\BindApiTenantDomain::class, $middleware, true);
            $this->assertNotFalse($auth, $route->uri());
            $this->assertNotFalse($domain, $route->uri());
            $this->assertLessThan($domain, $auth, $route->uri());
        }
        $this->assertGreaterThan(0, $count);
    }

    public function test_foreign_domain_rejects_write_before_idempotency_or_side_effect(): void
    {
        $this->withToken($this->token)->postJson('https://two.api.test/api/v1/teams', ['name' => 'Never created', 'active' => true],
            ['Idempotency-Key' => 'boundary-domain-write'])->assertNotFound();
        $this->assertSame(0, $this->db->table('clubora.api_idempotency')->where('integration_id', $this->integration->id)->count());
        $this->assertSame(0, $this->db->table('clubora.teams')->whereIn('tenant_id', [$this->one->id, $this->two->id])->count());
    }

    public function test_all_registered_resource_queries_are_bound_to_token_tenant(): void
    {
        $context = new ApiContext($this->integration->account, $this->one, $this->integration, $this->integration->tokens()->firstOrFail());
        $registry = app(ApiModuleRegistry::class);
        foreach ($registry->definitions() as $resource => $definition) {
            $query = $registry->query($context, $resource);
            $this->assertStringContainsString('"tenant_id" = ?', $query->toSql(), $resource);
            $this->assertContains($this->one->id, $query->getBindings(), $resource);
        }
    }

    public function test_foreign_ids_and_tenant_overrides_do_not_expose_or_change_records(): void
    {
        $foreign = $this->db->table('clubora.teams')->insertGetId(['tenant_id' => $this->two->id, 'name' => 'Foreign confidential team']);
        $this->withToken($this->token)->getJson('/api/v1/teams/'.$foreign)->assertNotFound();
        $this->withToken($this->token)->getJson('/api/v1/teams?search=Foreign')->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($this->token)->getJson('/api/v1/teams?tenant_id='.$this->two->id)->assertUnprocessable();
        $this->withToken($this->token)->patchJson('/api/v1/teams/'.$foreign, ['name' => 'Wrong', '_version' => str_repeat('0', 64)],
            ['Idempotency-Key' => 'boundary-foreign-update'])->assertNotFound();
        $this->withToken($this->token)->postJson('/api/v1/teams', ['name' => 'Wrong', 'tenant_id' => $this->two->id],
            ['Idempotency-Key' => 'boundary-tenant-injection'])->assertUnprocessable();
        $this->assertSame('Foreign confidential team', $this->db->table('clubora.teams')->where('id', $foreign)->value('name'));
    }

    public function test_foreign_file_id_is_rejected_before_provider_access(): void
    {
        $member = $this->db->table('clubora.members')->insertGetId(['tenant_id' => $this->two->id, 'first_name' => 'Foreign', 'last_name' => 'Fixture']);
        $type = $this->db->table('clubora.document_types')->insertGetId(['tenant_id' => $this->two->id, 'code' => 'BOUNDARY_ID', 'name' => 'Synthetic identity']);
        $document = $this->db->table('clubora.member_documents')->insertGetId(['tenant_id' => $this->two->id, 'member_id' => $member,
            'document_type_id' => $type, 'filename' => 'foreign.pdf', 'original_filename' => 'foreign.pdf', 'content_type' => 'application/pdf', 'file_size' => 1]);
        $this->withToken($this->token)->getJson('/api/v1/files/member-document/'.$document)->assertNotFound();
        $this->withToken($this->token)->getJson('/api/v1/documents/'.$document)->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_same_idempotency_key_is_isolated_between_integrations(): void
    {
        $this->admin->tenants()->attach($this->two, ['role' => 'admin']);
        $profile = new AccessProfile;
        $profile->forceFill(['tenant_id' => $this->two->id, 'name' => 'Second tenant profile', 'active' => true,
            'permissions' => ['teams.view', 'teams.create']])->save();
        $second = app(ApiTokenService::class)->createIntegration($this->admin, $this->two, ['name' => 'Second synthetic integration',
            'access_profile_id' => $profile->id, 'scopes' => ['teams:read', 'teams:create'], 'rate_limit' => 600]);
        $secondToken = app(ApiTokenService::class)->issueToken($this->admin, $this->two, $second->id, 'Second token', $second->scopes, now()->addHour())['token'];
        $headers = ['Idempotency-Key' => 'boundary-common-key'];
        $first = $this->withToken($this->token)->postJson('/api/v1/teams', ['name' => 'First', 'active' => true], $headers)->assertCreated();
        $other = $this->withToken($secondToken)->postJson('/api/v1/teams', ['name' => 'Second', 'active' => true], $headers)->assertCreated();
        $this->assertNotSame($first->json('data.id'), $other->json('data.id'));
        $this->assertSame($this->two->id, $this->db->table('clubora.teams')->where('id', $other->json('data.id'))->value('tenant_id'));
        $this->withToken($this->token)->getJson('/api/v1/teams/'.$other->json('data.id'))->assertNotFound();
        $this->withToken($this->token)->postJson('/api/v1/teams', ['name' => 'First', 'active' => true], $headers)
            ->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.id', $first->json('data.id'));
    }

    public function test_token_cannot_be_rebound_by_context_header_or_browser_session(): void
    {
        $this->withToken($this->token)->withHeaders(['X-Tenant-ID' => (string) $this->two->id])->getJson('/api/v1/context')
            ->assertOk()->assertJsonPath('data.tenant.id', $this->one->id);
        $context = new ApiContext($this->integration->account, $this->two, $this->integration, $this->integration->tokens()->firstOrFail());
        try {
            $context->authorize('teams:read', 'teams', 'view');
            $this->fail('Token accepted a different context tenant');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->withoutToken()->actingAs($this->admin, 'clubunio')->getJson('/api/v1/context')->assertUnauthorized();
    }

    public function test_upload_source_status_and_processing_do_not_cross_authority_boundaries(): void
    {
        config(['clubunio-integrations.azure.tenant_slug' => $this->one->slug]);
        $member = $this->db->table('clubora.members')->insertGetId(['tenant_id' => $this->one->id, 'first_name' => 'Upload', 'last_name' => 'Fixture']);
        $foreign = $this->db->table('clubora.members')->insertGetId(['tenant_id' => $this->two->id, 'first_name' => 'Foreign', 'last_name' => 'Fixture']);
        $type = $this->db->table('clubora.document_types')->insertGetId(['tenant_id' => $this->one->id, 'code' => 'BOUNDARY_UPLOAD', 'name' => 'Synthetic upload']);
        $file = fn () => UploadedFile::fake()->createWithContent('fixture.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a3x8AAAAASUVORK5CYII='));
        $this->withToken($this->token)->post('/api/v1/uploads/member/'.$foreign,
            ['type' => $type, 'portal_visible' => '0', 'file' => $file()],
            ['Idempotency-Key' => 'boundary-upload-foreign', 'Accept' => 'application/json'])->assertNotFound();
        $this->assertSame(0, $this->db->table('clubora.document_uploads')->where('tenant_id', $this->one->id)->count());
        $own = $this->withToken($this->token)->post('/api/v1/uploads/member/'.$member,
            ['type' => $type, 'portal_visible' => '0', 'file' => $file()],
            ['Idempotency-Key' => 'boundary-upload-own', 'Accept' => 'application/json'])->assertStatus(202)->json('data.id');
        $second = app(ApiTokenService::class)->createIntegration($this->admin, $this->one, ['name' => 'Separate upload actor',
            'access_profile_id' => $this->integration->access_profile_id, 'scopes' => ['documents:upload'], 'rate_limit' => 600]);
        $token = app(ApiTokenService::class)->issueToken($this->admin, $this->one, $second->id, 'Separate upload token', $second->scopes, now()->addHour())['token'];
        $this->withToken($token)->getJson('/api/v1/uploads/'.$own)->assertNotFound();
        $this->withToken($token)->postJson('/api/v1/uploads/'.$own.'/process', [])->assertNotFound();
        $this->assertSame('pending', $this->db->table('clubora.document_uploads')->where('id', $own)->value('state'));
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_database_rejects_foreign_integration_profile_even_without_service_validation(): void
    {
        // Exercise the pending additive migration inside the fixture rollback only.
        if (! $this->db->selectOne("SELECT 1 FROM pg_constraint WHERE conrelid='clubora.api_integrations'::regclass AND conname='api_integration_tenant_profile_fk'")) {
            (require base_path('database/clubunio/2026_10_26_000078_api_tenant_boundary.php'))->up();
        }
        $profile = new AccessProfile;
        $profile->forceFill(['tenant_id' => $this->two->id, 'name' => 'Foreign profile', 'active' => true, 'permissions' => ['teams.view']])->save();
        try {
            $this->db->transaction(fn () => $this->db->table('clubora.api_integrations')->where('id', $this->integration->id)
                ->update(['access_profile_id' => $profile->id]));
            $this->fail('Foreign tenant profile accepted');
        } catch (QueryException $error) {
            $this->assertSame('23503', $error->errorInfo[0]);
        }
        $this->withToken($this->token)->getJson('/api/v1/context')->assertOk();
    }
}
