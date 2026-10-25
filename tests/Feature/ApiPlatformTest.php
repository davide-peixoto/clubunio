<?php

namespace Tests\Feature;

use App\ClubUnio\Api\ApiTokenService;
use App\ClubUnio\Http\ProtectApiAdminResponse;
use App\ClubUnio\Models\AccessProfile;
use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\ApiIntegration;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Pages\ApiIntegrations;
use App\ClubUnio\Services\MemberDocumentService;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class ApiPlatformTest extends TestCase
{
    private $db;

    private Tenant $tenant;

    private Account $admin;

    private AccessProfile $profile;

    private ApiIntegration $integration;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Opt-in rollback tests');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        Http::preventStrayRequests();
        Mail::fake();
        $this->tenant = Tenant::create(['name' => 'API fixture', 'slug' => 'api-'.Str::random(15)]);
        $this->admin = new Account;
        $this->admin->forceFill(['username' => 'api-admin-'.Str::random(15), 'display_name' => 'API fixture', 'password' => bcrypt('fixture'), 'active' => true])->save();
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $this->admin->id, 'role' => 'admin']);
        $this->profile = new AccessProfile;
        $this->profile->forceFill(['tenant_id' => $this->tenant->id, 'name' => 'API fixture', 'active' => true,
            'permissions' => ['teams.view', 'teams.create', 'teams.update', 'seasons.view', 'competitions.view', 'training_slots.view', 'field_availability.view']])->save();
        $service = app(ApiTokenService::class);
        $this->integration = $service->createIntegration($this->admin, $this->tenant, ['name' => 'Fixture website', 'access_profile_id' => $this->profile->id,
            'scopes' => ['website:teams:read', 'website:fixtures:read', 'website:training-times:read', 'website:facility-status:read', 'teams:read', 'teams:create', 'teams:update'], 'rate_limit' => 600]);
        $this->token = $service->issueToken($this->admin, $this->tenant, $this->integration->id, 'Fixture', $this->integration->scopes, now()->addHour())['token'];
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

    public function test_bearer_only_and_token_hash_at_rest(): void
    {
        $this->getJson('/api/v1/context')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
        $this->actingAs($this->admin, 'clubunio')->getJson('/api/v1/context')->assertUnauthorized();
        $this->withToken($this->token)->getJson('/api/v1/context')->assertOk()->assertJsonPath('data.tenant.id', $this->tenant->id);
        $stored = $this->integration->tokens()->first();
        $this->assertNotSame($this->token, $stored->token);
        $this->assertSame(hash('sha256', explode('|', $this->token, 2)[1]), $stored->token);
        $this->assertStringNotContainsString($this->token, json_encode($stored));
        $this->assertTrue($this->integration->account->api_only);
        $this->assertFalse($this->integration->account->canAccessPanel(Filament::getPanel('clubunio')));
    }

    public function test_revocation_expiry_disabled_profile_and_changed_membership_fail_closed(): void
    {
        $this->withToken($this->token)->getJson('/api/v1/website/teams')->assertOk();
        $this->profile->forceFill(['active' => false])->save();
        $this->withToken($this->token)->getJson('/api/v1/website/teams')->assertUnauthorized();
        $this->profile->forceFill(['active' => true])->save();
        $this->integration->tokens()->update(['expires_at' => now()->subMinute()->toIso8601String()]);
        $this->withToken($this->token)->getJson('/api/v1/context')->assertUnauthorized();
        $this->integration->tokens()->update(['expires_at' => now()->addHour()->toIso8601String()]);
        app(ApiTokenService::class)->revokeToken($this->admin, $this->tenant, $this->integration->tokens()->first()->id);
        $this->withToken($this->token)->getJson('/api/v1/context')->assertUnauthorized();
    }

    public function test_read_only_website_token_cannot_access_members_or_write(): void
    {
        $token = app(ApiTokenService::class)->issueToken($this->admin, $this->tenant, $this->integration->id, 'Readonly', ['website:teams:read'], now()->addHour())['token'];
        $this->withToken($token)->getJson('/api/v1/website/teams')->assertOk();
        $this->withToken($token)->getJson('/api/v1/members')->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/teams', ['name' => 'Never written', 'active' => true], ['Idempotency-Key' => 'read-only-write'])->assertForbidden();
        $this->assertFalse($this->db->table('clubora.teams')->where('tenant_id', $this->tenant->id)->where('name', 'Never written')->exists());
    }

    public function test_website_is_tenant_scoped_and_has_no_private_fields(): void
    {
        $season = $this->db->table('clubora.seasons')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => 'Fixture season']);
        $team = $this->db->table('clubora.teams')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => 'Fixture team', 'laundry_allowance' => 99]);
        $this->db->table('clubora.team_seasons')->insert(['tenant_id' => $this->tenant->id, 'season_id' => $season, 'team_id' => $team, 'label' => 'Fixture label']);
        $response = $this->withToken($this->token)->getJson('/api/v1/website/teams')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(['id', 'team_id', 'season_id', 'name', 'label', 'season'], array_keys($response->json('data.0')));
        $this->withToken($this->token)->getJson('/api/v1/website/teams?tenant_id=4')->assertUnprocessable();
        foreach (['fixtures', 'training-times', 'facility-status'] as $feed) {
            $this->withToken($this->token)->getJson('/api/v1/website/'.$feed)->assertOk();
        }
    }

    public function test_idempotent_create_replays_once_and_conflicting_reuse_is_rejected(): void
    {
        $data = ['name' => 'API synthetic team', 'active' => true];
        $headers = ['Idempotency-Key' => 'fixture-team-create'];
        $first = $this->withToken($this->token)->postJson('/api/v1/teams', $data, $headers)->assertCreated();
        $second = $this->withToken($this->token)->postJson('/api/v1/teams', $data, $headers)->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, $this->db->table('clubora.teams')->where('tenant_id', $this->tenant->id)->where('name', $data['name'])->count());
        $this->withToken($this->token)->postJson('/api/v1/teams', ['name' => 'Different', 'active' => true], $headers)->assertConflict();
        $this->withToken($this->token)->postJson('/api/v1/teams', $data)->assertUnprocessable();
        // A permission removal also blocks an otherwise valid stored replay.
        $this->profile->forceFill(['permissions' => ['teams.view']])->save();
        $this->withToken($this->token)->postJson('/api/v1/teams', $data, $headers)->assertForbidden();
    }

    public function test_admin_only_management_and_docs(): void
    {
        $this->get('/api-docs')->assertRedirect('/login');
        $this->get('/api-docs/openapi.json')->assertRedirect('/login');
        $session = ['clubunio_context.'.$this->admin->id => ['tenant' => $this->tenant->id, 'revision' => (string) Str::uuid()]];
        $this->actingAs($this->admin, 'clubunio')->withSession($session)->get('/api-docs')->assertOk();
        $this->actingAs($this->admin, 'clubunio')->withSession($session)->get('/api-docs/openapi.json')->assertOk()->assertJsonPath('openapi', '3.0.3');
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)->where('account_id', $this->admin->id)->update(['role' => 'office']);
        $this->actingAs($this->admin, 'clubunio')->withSession($session)->get('/api-docs')->assertForbidden();
        $this->assertFalse(app(ApiTokenService::class)->isAdmin($this->admin, $this->tenant));
    }

    public function test_rate_limit_and_audit_do_not_store_secrets(): void
    {
        $this->integration->forceFill(['rate_limit' => 1])->save();
        $this->withToken($this->token)->getJson('/api/v1/context')->assertOk()->assertHeader('X-Request-ID');
        $this->withToken($this->token)->getJson('/api/v1/context')->assertStatus(429)->assertHeader('Retry-After');
        $rows = $this->db->table('clubora.api_audit')->where('tenant_id', $this->tenant->id)->get();
        $this->assertStringNotContainsString($this->token, json_encode($rows));
        $this->assertTrue($rows->contains('status', 429));
    }

    private function documentToken(array $scopes): string
    {
        $this->profile->forceFill(['permissions' => [...$this->profile->permissions, 'documents.view', 'documents.download', 'documents.upload', 'documents.send']])->save();
        $this->integration->forceFill(['scopes' => [...$this->integration->scopes, ...$scopes]])->save();

        return app(ApiTokenService::class)->issueToken($this->admin, $this->tenant, $this->integration->id, 'Document fixture', $scopes, now()->addHour())['token'];
    }

    public function test_file_download_requires_separate_scope_and_reuses_authorized_service(): void
    {
        $this->mock(MemberDocumentService::class, function ($mock) {
            $mock->shouldReceive('open')->once()->withArgs(fn ($actor, $tenant, $id, $download) => $actor->api_only && $tenant->id === $this->tenant->id && $id === 123 && ! $download)
                ->andReturn(response('%PDF-fixture', 200, ['Content-Type' => 'application/pdf']));
        });
        $this->withToken($this->token)->get('/api/v1/files/member-document/123')->assertForbidden();
        $token = $this->documentToken(['documents:download']);
        $this->withToken($token)->get('/api/v1/files/member-document/123')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->withToken($token)->getJson('/api/v1/members')->assertForbidden();
    }

    public function test_upload_is_staged_encrypted_and_replay_does_not_duplicate_it(): void
    {
        config(['clubunio-integrations.azure.tenant_slug' => $this->tenant->slug]);
        $token = $this->documentToken(['documents:upload']);
        $member = $this->db->table('clubora.members')->insertGetId(['tenant_id' => $this->tenant->id, 'first_name' => 'Synthetic', 'last_name' => 'Upload']);
        $type = $this->db->table('clubora.document_types')->insertGetId(['tenant_id' => $this->tenant->id, 'code' => 'API_TEST', 'name' => 'Fixture']);
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a3x8AAAAASUVORK5CYII=');
        $upload = fn () => UploadedFile::fake()->createWithContent('fixture.png', $bytes);
        $headers = ['Idempotency-Key' => 'api-upload-fixture', 'Accept' => 'application/json'];
        $result = $this->withToken($token)->post('/api/v1/uploads/member/'.$member,
            ['type' => $type, 'portal_visible' => '0', 'file' => $upload()], $headers)->assertStatus(202)->assertJsonPath('data.state', 'pending');
        $id = $result->json('data.id');
        $this->withToken($token)->post('/api/v1/uploads/member/'.$member,
            ['type' => $type, 'portal_visible' => '0', 'file' => $upload()], $headers)->assertStatus(202)->assertJsonPath('data.id', $id)->assertHeader('Idempotency-Replayed', 'true');
        $stored = $this->db->table('clubora.document_uploads')->find($id);
        $this->assertNotSame($bytes, $stored->encrypted_bytes);
        $this->assertSame($bytes, Crypt::decryptString($stored->encrypted_bytes));
        $this->assertSame(1, $this->db->table('clubora.document_uploads')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(1, $this->db->table('clubora.sync_work_items')->where('tenant_id', $this->tenant->id)->where('operation', 'azure_upload')->count());
        $this->withToken($token)->getJson('/api/v1/uploads/'.$id)->assertOk()->assertJsonMissingPath('data.encrypted_bytes')->assertJsonMissingPath('data.filename');
        $this->withToken($this->token)->getJson('/api/v1/uploads/'.$id)->assertForbidden();
        $this->withToken($token)->post('/api/v1/uploads/member/'.$member,
            ['type' => $type, 'portal_visible' => '1', 'file' => $upload()], ['Idempotency-Key' => 'api-publish-denied', 'Accept' => 'application/json'])->assertForbidden();
    }

    public function test_real_admin_ui_renders_with_existing_profile_and_tenant_audit(): void
    {
        $this->actingAs($this->admin, 'clubunio');
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        Filament::setTenant($this->tenant);
        Livewire::test(ApiIntegrations::class)
            ->assertSee('Fixture website')->assertSee('API-Audit')->assertDontSee($this->token);
        $response = $this->withSession(['clubunio_context.'.$this->admin->id => ['tenant' => $this->tenant->id, 'revision' => (string) Str::uuid()]])
            ->get('/api-integrations')->assertOk();
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public function test_reports_and_streamed_exports_require_both_report_and_domain_scopes(): void
    {
        $this->profile->forceFill(['permissions' => [...$this->profile->permissions, 'reports.view', 'reports.export', 'members.view']])->save();
        $scopes = ['reports:read', 'reports:export', 'members:read'];
        $this->integration->forceFill(['scopes' => $scopes])->save();
        $token = app(ApiTokenService::class)->issueToken($this->admin, $this->tenant, $this->integration->id, 'Reports fixture', $scopes, now()->addHour())['token'];
        $status = $this->db->table('clubora.member_statuses')->insertGetId(['tenant_id' => $this->tenant->id,
            'code' => 'MEMBER', 'name' => 'Fixture member', 'restores_membership' => true]);
        $this->db->table('clubora.members')->insert(['tenant_id' => $this->tenant->id, 'first_name' => 'Synthetic report',
            'last_name' => 'Fixture', 'status_id' => $status, 'email' => 'not-in-report@example.invalid']);
        $this->withToken($token)->getJson('/api/v1/reports/members')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.first_name', 'Synthetic report')->assertJsonMissingPath('data.0.email');
        $this->withToken($token)->get('/api/v1/exports/members')->assertOk()->assertHeader('X-Request-ID')->assertStreamed();
        $limited = app(ApiTokenService::class)->issueToken($this->admin, $this->tenant, $this->integration->id, 'Missing domain', ['reports:read', 'reports:export'], now()->addHour())['token'];
        $this->withToken($limited)->getJson('/api/v1/reports/members')->assertForbidden();
        $this->withToken($limited)->get('/api/v1/exports/members')->assertForbidden();
    }

    public function test_existing_broad_facilities_permission_retains_only_its_existing_read_scope(): void
    {
        $this->profile->forceFill(['permissions' => ['facilities.view']])->save();
        $integration = app(ApiTokenService::class)->createIntegration($this->admin, $this->tenant,
            ['name' => 'Existing facilities view', 'access_profile_id' => $this->profile->id, 'scopes' => ['website:facility-status:read']]);
        $token = app(ApiTokenService::class)->issueToken($this->admin, $this->tenant, $integration->id, 'Facilities view', $integration->scopes, now()->addHour())['token'];
        $this->withToken($token)->getJson('/api/v1/website/facility-status')->assertOk();
        $this->withToken($token)->getJson('/api/v1/members')->assertForbidden();
    }

    public function test_token_issuance_component_responses_are_never_cacheable(): void
    {
        $request = Request::create('/livewire/update', 'POST', ['components' => [
            ['snapshot' => json_encode(['memo' => ['name' => 'app.clubunio.pages.api-integrations', 'path' => 'api-integrations']])],
        ]]);
        $response = (new ProtectApiAdminResponse)->handle($request, fn () => response('synthetic-token-response'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    }
}
