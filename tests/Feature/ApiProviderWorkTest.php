<?php

namespace Tests\Feature;

use App\ClubUnio\Api\ApiContext;
use App\ClubUnio\Api\ApiProviderWork;
use App\ClubUnio\Api\ApiTokenService;
use App\ClubUnio\Api\Providers\ProviderApiRegistry;
use App\ClubUnio\Models\AccessProfile;
use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\ApiIntegration;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Pages\ApiIntegrations;
use App\ClubUnio\Services\TrainingPlanPdfService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ApiProviderWorkTest extends TestCase
{
    private $db;

    private Tenant $tenant;

    private Account $admin;

    private ApiIntegration $integration;

    private AccessProfile $profile;

    private string $token;

    private ProviderWorkFixture $provider;

    private bool $simulateCommittedBoundary = true;

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
        $this->provider = new ProviderWorkFixture;
        $this->app->instance(ProviderApiRegistry::class, $this->provider);
        $this->tenant = Tenant::create(['name' => 'QA provider work', 'slug' => 'api-work-'.Str::random(15)]);
        $this->admin = new Account;
        $this->admin->forceFill(['username' => 'qa-work-'.Str::random(15), 'display_name' => 'QA', 'password' => bcrypt('fixture'), 'active' => true])->save();
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $this->admin->id, 'role' => 'admin']);
        $this->profile = new AccessProfile;
        $this->profile->forceFill(['tenant_id' => $this->tenant->id, 'name' => 'QA work', 'active' => true, 'permissions' => ['teams.view', 'teams.create']])->save();
        $service = app(ApiTokenService::class);
        $this->integration = $service->createIntegration($this->admin, $this->tenant, ['name' => 'QA provider', 'access_profile_id' => $this->profile->id, 'scopes' => ['teams:read', 'teams:create'], 'rate_limit' => 600]);
        $this->token = $service->issueToken($this->admin, $this->tenant, $this->integration->id, 'QA', $this->integration->scopes, now()->addHour())['token'];
        // Only the execution boundary is simulated: the fake registry never calls a provider.
        // Every database change still belongs to the real outer rollback fixture.
        $boundary = Mockery::mock($this->db);
        $boundary->shouldReceive('transactionLevel')->andReturnUsing(fn () => $this->simulateCommittedBoundary ? 0 : $this->db->transactionLevel());
        DB::shouldReceive('connection')->with('clubunio')->andReturn($boundary);
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

    private function stage(string $operation = 'training.generate', string $key = 'qa-provider-work')
    {
        return $this->withToken($this->token)->postJson('/api/v1/provider-actions/'.$operation,
            ['object_id' => 0, 'input' => (object) ['goal' => 'QA goal']], ['Idempotency-Key' => $key]);
    }

    public function test_stage_is_encrypted_idempotent_and_does_not_call_provider(): void
    {
        $first = $this->stage()->assertStatus(202)->assertJsonPath('data.state', 'pending');
        $again = $this->stage()->assertStatus(202)->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($first->json('data.id'), $again->json('data.id'));
        $this->assertSame(0, $this->provider->calls);
        $row = app(ApiProviderWork::class)->table()->find($first->json('data.id'));
        $this->assertStringNotContainsString('QA goal', $row->encrypted_input);
        $this->assertSame(['goal' => 'QA goal'], json_decode(Crypt::decryptString($row->encrypted_input), true));
        $this->assertSame(1, $this->db->table('clubora.api_provider_events')->where('work_id', $row->id)->count());
    }

    public function test_process_once_reuses_result_and_persisted_provider_key(): void
    {
        $id = $this->stage()->assertStatus(202)->json('data.id');
        $this->withToken($this->token)->postJson('/api/v1/provider-work/'.$id.'/process', [], [], JSON_FORCE_OBJECT)
            ->assertOk()->assertJsonPath('data.state', 'succeeded')->assertJsonPath('data.result.confirmed', true);
        $key = $this->provider->key;
        $this->assertTrue(Str::isUuid($key));
        $this->withToken($this->token)->postJson('/api/v1/provider-work/'.$id.'/process', [], [], JSON_FORCE_OBJECT)->assertOk();
        $this->assertSame(1, $this->provider->calls);
        $this->assertSame($key, $this->provider->key);
        $this->stage()->assertStatus(202);
        $this->assertSame(1, $this->provider->calls);
    }

    public function test_uncertain_provider_is_not_retried_and_secrets_are_not_returned(): void
    {
        $id = $this->stage()->assertStatus(202)->json('data.id');
        $this->provider->fail = true;
        $this->withToken($this->token)->postJson('/api/v1/provider-work/'.$id.'/process', [], [], JSON_FORCE_OBJECT)->assertStatus(500)->assertDontSee('provider-secret');
        $this->withToken($this->token)->getJson('/api/v1/provider-work/'.$id)->assertOk()
            ->assertJsonPath('data.state', 'attention')->assertJsonPath('data.error_code', 'operation_unconfirmed');
        $this->withToken($this->token)->postJson('/api/v1/provider-work/'.$id.'/process', [], [], JSON_FORCE_OBJECT)->assertConflict();
        $this->assertSame(1, $this->provider->calls);
    }

    public function test_unconfirmed_return_is_attention_not_success(): void
    {
        $id = $this->stage()->assertStatus(202)->json('data.id');
        $this->provider->confirmed = false;
        $this->withToken($this->token)->postJson('/api/v1/provider-work/'.$id.'/process', [], [], JSON_FORCE_OBJECT)->assertOk()
            ->assertJsonPath('data.state', 'attention')->assertJsonPath('data.error_code', 'provider_unconfirmed');
    }

    public function test_admin_approval_keeps_technical_account_unprivileged(): void
    {
        $id = $this->stage('bexio.catalog-sync')->assertStatus(202)->assertJsonPath('data.state', 'awaiting_approval')->json('data.id');
        $this->withToken($this->token)->postJson('/api/v1/provider-work/'.$id.'/process', [], [], JSON_FORCE_OBJECT)->assertConflict();
        $result = app(ApiProviderWork::class)->approve($this->admin, $this->tenant, $id, 1);
        $this->assertSame('succeeded', $result['state']);
        $this->assertSame($this->admin->id, $result['approved_by']);
        $this->assertSame($this->admin->id, $this->provider->executor);
        $this->assertTrue($this->integration->account->api_only);
        $this->assertSame('profile', $this->db->table('clubora.tenant_accounts')->where('account_id', $this->integration->account_id)->value('role'));
    }

    public function test_revoked_original_token_blocks_admin_approval(): void
    {
        $id = $this->stage('bexio.catalog-sync')->assertStatus(202)->json('data.id');
        app(ApiTokenService::class)->revokeToken($this->admin, $this->tenant, $this->integration->tokens()->first()->id);
        try {
            app(ApiProviderWork::class)->approve($this->admin, $this->tenant, $id, 1);
            $this->fail('Revoked request must not execute');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame(0, $this->provider->calls);
    }

    public function test_changed_profile_and_running_work_block_execution(): void
    {
        $id = $this->stage()->assertStatus(202)->json('data.id');
        $this->profile->forceFill(['permissions' => ['teams.view']])->save();
        $this->withToken($this->token)->postJson('/api/v1/provider-work/'.$id.'/process', [], [], JSON_FORCE_OBJECT)->assertForbidden();
        $this->profile->forceFill(['permissions' => ['teams.view', 'teams.create']])->save();
        app(ApiProviderWork::class)->table()->where('id', $id)->update(['state' => 'running']);
        $this->withToken($this->token)->postJson('/api/v1/provider-work/'.$id.'/process', [], [], JSON_FORCE_OBJECT)->assertConflict();
        $this->assertSame(0, $this->provider->calls);
    }

    public function test_other_integration_and_other_tenant_cannot_read_or_execute_work(): void
    {
        $id = $this->stage()->assertStatus(202)->json('data.id');
        $service = app(ApiTokenService::class);
        $other = $service->createIntegration($this->admin, $this->tenant, ['name' => 'Another integration', 'access_profile_id' => $this->profile->id, 'scopes' => ['teams:read', 'teams:create'], 'rate_limit' => 600]);
        $token = $service->issueToken($this->admin, $this->tenant, $other->id, 'QA other', $other->scopes, now()->addHour())['token'];
        $this->withToken($token)->getJson('/api/v1/provider-work/'.$id)->assertNotFound();
        $this->withToken($token)->postJson('/api/v1/provider-work/'.$id.'/process', [], [], JSON_FORCE_OBJECT)->assertNotFound();
        $foreign = Tenant::create(['name' => 'Other', 'slug' => 'work-other-'.Str::random(15)]);
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $foreign->id, 'account_id' => $this->admin->id, 'role' => 'admin']);
        try {
            app(ApiProviderWork::class)->inspect($this->admin, $foreign, $id);
            $this->fail('Foreign work must not be shown');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    public function test_rejection_and_stale_approval_do_not_execute(): void
    {
        $id = $this->stage('bexio.catalog-sync')->assertStatus(202)->json('data.id');
        app(ApiProviderWork::class)->reject($this->admin, $this->tenant, $id, 1);
        $this->withToken($this->token)->getJson('/api/v1/provider-work/'.$id)->assertOk()->assertJsonPath('data.state', 'rejected');
        try {
            app(ApiProviderWork::class)->approve($this->admin, $this->tenant, $id, 1);
            $this->fail('Rejected request must not execute');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertSame(0, $this->provider->calls);
    }

    public function test_strict_request_objects_scopes_and_unknown_actions(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/provider-actions/unknown.action', ['object_id' => 0, 'input' => (object) []], ['Idempotency-Key' => 'unknown-action'])->assertNotFound();
        $this->withToken($this->token)->postJson('/api/v1/provider-actions/training.generate', ['object_id' => 0, 'input' => []], ['Idempotency-Key' => 'invalid-array'])->assertUnprocessable();
        $this->withToken($this->token)->postJson('/api/v1/provider-actions/training.generate', ['object_id' => 0, 'input' => ['goal' => 'QA', '_request_key' => Str::uuid()]], ['Idempotency-Key' => 'invalid-private'])->assertUnprocessable();
        $readToken = app(ApiTokenService::class)->issueToken($this->admin, $this->tenant, $this->integration->id, 'Read', ['teams:read'], now()->addHour())['token'];
        $this->withToken($readToken)->postJson('/api/v1/provider-actions/training.generate', ['object_id' => 0, 'input' => ['goal' => 'QA']], ['Idempotency-Key' => 'no-create-scope'])->assertForbidden();
        $this->assertSame(0, $this->provider->calls);
    }

    public function test_schedule_read_is_scoped_paginated_filtered_and_has_only_documented_fields(): void
    {
        $this->profile->forceFill(['permissions' => ['teams.view', 'teams.create', 'competitions.view']])->save();
        $this->integration->forceFill(['scopes' => [...$this->integration->scopes, 'competitions:read']])->save();
        $token = app(ApiTokenService::class)->issueToken($this->admin, $this->tenant, $this->integration->id, 'Schedule', ['competitions:read'], now()->addHour())['token'];
        $foreign = Tenant::create(['name' => 'Foreign fixture', 'slug' => 'schedule-other-'.Str::random(10)]);
        foreach ([$this->tenant->id, $foreign->id] as $tenant) {
            $this->db->table('clubora.sfv_schedule')->insert(['tenant_id' => $tenant, 'match_id' => 123, 'season_id' => 2027, 'title' => 'QA', 'starts_at' => '2026-11-01T10:00:00+01:00', 'home' => true]);
        }
        $response = $this->withToken($token)->getJson('/api/v1/sfv/schedule?from=2026-11-01&to=2026-11-01&per_page=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.match_id', 123);
        $this->assertSame(['id', 'match_id', 'season_id', 'team_a_id', 'team_b_id', 'host_name', 'guest_name', 'title', 'starts_at', 'location', 'state', 'match_type', 'home', 'revision', 'updated_at'], array_keys($response->json('data.0')));
        $this->withToken($token)->getJson('/api/v1/sfv/schedule?to=2026-10-31')->assertOk()->assertJsonPath('meta.total', 0);
        $this->withToken($token)->getJson('/api/v1/sfv/schedule?tenant_id='.$foreign->id)->assertUnprocessable();
        $this->withToken($token)->getJson('/api/v1/sfv/schedule?from=2026-11-02&to=2026-11-01')->assertUnprocessable();
        $this->withToken($token)->getJson('/api/v1/sfv/schedule?per_page=101')->assertUnprocessable();
    }

    public function test_admin_web_inspection_requires_a_specific_fresh_approval(): void
    {
        $id = $this->stage('bexio.catalog-sync')->assertStatus(202)->json('data.id');
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $this->actingAs($this->admin, 'clubunio');
        Filament::setTenant($this->tenant);
        Livewire::test(ApiIntegrations::class)->call('inspectProviderWork', $id)
            ->assertSet('providerWorkId', $id)->assertSet('providerWorkRevision', 1)
            ->assertSee('QA goal')->call('executeProviderWork')->assertSet('providerWorkId', null);
        $this->assertSame(1, $this->provider->calls);
        $this->assertSame($this->admin->id, $this->provider->executor);
        try {
            app(ApiProviderWork::class)->approve($this->admin, $this->tenant, $id, 1);
            $this->fail('Stale admin approval must not succeed');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
    }

    public function test_provider_runner_rejects_ambient_transactions(): void
    {
        $id = $this->stage()->assertStatus(202)->json('data.id');
        $this->simulateCommittedBoundary = false;
        $this->withToken($this->token)->postJson('/api/v1/provider-work/'.$id.'/process', [], [], JSON_FORCE_OBJECT)->assertConflict();
        $this->assertSame(0, $this->provider->calls);
        $this->assertSame('pending', app(ApiProviderWork::class)->table()->where('id', $id)->value('state'));
    }

    public function test_training_pdf_needs_its_own_download_scope_and_uses_the_shared_renderer(): void
    {
        $this->withToken($this->token)->getJson('/api/v1/files/training-pdf/1')->assertForbidden();
        $this->profile->forceFill(['permissions' => ['teams.view', 'teams.create', 'training.view', 'training.download']])->save();
        $this->integration->forceFill(['scopes' => [...$this->integration->scopes, 'training:download']])->save();
        $token = app(ApiTokenService::class)->issueToken($this->admin, $this->tenant, $this->integration->id, 'Training PDF', ['training:download'], now()->addHour())['token'];
        $this->mock(TrainingPlanPdfService::class, fn ($mock) => $mock->shouldReceive('open')->once()
            ->withArgs(fn ($actor, $tenant, $id) => $actor->id === $this->integration->account_id && $tenant->id === $this->tenant->id && $id === 1)
            ->andReturn(response('%PDF-fixture', 200, ['Content-Type' => 'application/pdf'])));
        $this->withToken($token)->get('/api/v1/files/training-pdf/1')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }
}

class ProviderWorkFixture extends ProviderApiRegistry
{
    public int $calls = 0;

    public bool $fail = false;

    public bool $confirmed = true;

    public ?string $key = null;

    public ?int $executor = null;

    public function definitions(): array
    {
        $definition = ['title' => 'QA', 'approval' => 'direct', 'scopes' => ['teams:create' => ['teams', 'create']], 'fields' => ['goal' => 'required|string|max:200']];

        return ['training.generate' => $definition, 'bexio.catalog-sync' => array_replace($definition, ['approval' => 'admin'])];
    }

    public function validate(ApiContext $context, string $operation, int $id, array $input): array
    {
        $this->authorize($context, $operation);
        abort_if(array_diff(array_keys($input), ['goal']), 422);

        return Validator::make($input, ['goal' => 'required|string|max:200'])->validate();
    }

    public function execute(ApiContext $context, string $operation, int $id, array $input, ?Account $executor = null): array
    {
        $this->calls++;
        $this->key = $input['_request_key'];
        $this->executor = $executor?->id;
        if ($this->fail) {
            throw new \RuntimeException('provider-secret');
        }

        return ['confirmed' => $this->confirmed, 'plan_id' => 42];
    }
}
