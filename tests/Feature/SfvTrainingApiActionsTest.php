<?php

namespace Tests\Feature;

use App\ClubUnio\Api\ApiContext;
use App\ClubUnio\Api\Providers\SfvApiActions;
use App\ClubUnio\Api\Providers\TrainingApiActions;
use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\ApiIntegration;
use App\ClubUnio\Models\Competition;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\BackofficeReadContext;
use App\ClubUnio\Services\PermissionCatalog;
use App\ClubUnio\Services\SfvCompetitionService;
use App\ClubUnio\Services\SfvPlanningService;
use App\ClubUnio\Services\TeamPlanningService;
use App\ClubUnio\Services\TrainingPlanAi;
use App\ClubUnio\Services\TrainingPlanContent;
use App\ClubUnio\Services\TrainingPlanService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SfvTrainingApiActionsTest extends TestCase
{
    private $db;

    private ApiContext $context;

    private int $profile;

    private int $team;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
    }

    private function fixture(): void
    {
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Opt-in PostgreSQL rollback tests; run serially with parent approval.');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('pgsql', $this->db->getDriverName());
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $tenant = Tenant::create(['slug' => 'provider-api-'.Str::random(12), 'name' => 'Provider fixture']);
        $actor = new Account;
        $actor->forceFill(['username' => Str::random(24), 'display_name' => 'Provider fixture', 'password' => bcrypt(Str::random(32)),
            'active' => true, 'api_only' => true])->save();
        $permissions = [];
        $scopes = [];
        foreach ((new SfvApiActions)->definitions() + (new TrainingApiActions)->definitions() as $definition) {
            foreach ($definition['scopes'] as $scope => [$module, $function]) {
                $scopes[] = $scope;
                $permissions[] = $module.'.'.$function;
                $permissions[] = $module.'.view';
            }
        }
        $scopes = array_values(array_unique($scopes));
        $this->profile = $this->db->table('clubora.access_profiles')->insertGetId(['tenant_id' => $tenant->id,
            'name' => 'Provider fixture', 'active' => true, 'permissions' => json_encode(array_values(array_unique($permissions)))]);
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $tenant->id, 'account_id' => $actor->id,
            'role' => 'profile', 'access_profile_id' => $this->profile]);
        $integration = new ApiIntegration;
        $integration->forceFill(['tenant_id' => $tenant->id, 'account_id' => $actor->id, 'access_profile_id' => $this->profile,
            'name' => 'Provider fixture', 'created_by' => $actor->id, 'active' => true, 'scopes' => $scopes])->save();
        $token = $integration->createToken('fixture', $scopes, now()->addHour())->accessToken;
        $this->context = new ApiContext($actor, $tenant, $integration, $token);
        $season = $this->insert('seasons', ['name' => 'Fixture season']);
        $team = $this->insert('teams', ['name' => 'Fixture team']);
        $this->team = $this->insert('team_seasons', ['team_id' => $team, 'season_id' => $season, 'label' => 'Fixture team']);
        $this->insert('training_options', ['category' => 'field', 'code' => 'K', 'name' => 'Fixture field', 'active' => true]);
        $this->insert('training_options', ['category' => 'room', 'code' => '1', 'name' => 'Fixture room', 'active' => true]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            while ($this->db->transactionLevel()) {
                $this->db->rollBack();
            }
        }
        request()->attributes->remove(BackofficeReadContext::class);
        parent::tearDown();
    }

    private function insert(string $table, array $data): int
    {
        return $this->db->table('clubora.'.$table)->insertGetId(['tenant_id' => $this->context->tenant->id] + $data);
    }

    private function fakeProviderBoundary(): void
    {
        // Remove only the test's outer rollback from the reported level; real nested writes still lock authorization.
        // AI/refresh effects are always mocked, and every SQL write stays inside the rollback fixture.
        $connection = Mockery::mock($this->db);
        $connection->shouldReceive('transactionLevel')->andReturnUsing(fn () => max(0, $this->db->transactionLevel() - 1));
        DB::shouldReceive('connection')->with('clubunio')->andReturn($connection);
    }

    private function rejects(callable $operation, string $class = ValidationException::class, ?int $status = null): void
    {
        try {
            $operation();
        } catch (ValidationException|HttpException $error) {
            $this->assertInstanceOf($class, $error);
            if ($status !== null) {
                $this->assertSame($status, $error->getStatusCode());
            }

            return;
        }
        $this->fail('Unsafe provider operation accepted.');
    }

    private function input(): array
    {
        return ['team_season_id' => $this->team, 'durationMinutes' => 60, 'playerCount' => 18,
            'goal' => 'Passing', 'fieldSize' => 'Half pitch', 'material' => 'Balls'];
    }

    private function plan(): array
    {
        $exercise = ['title' => 'Passing', 'durationMinutes' => 20, 'setup' => 'Two groups', 'flow' => 'Pass and move',
            'coachingPoints' => ['Ball control'], 'easier' => 'More space', 'harder' => 'Less time', 'diagramDescription' => 'Two zones',
            'markers' => [['kind' => 'player', 'x' => 25, 'y' => 50]]];

        return ['title' => 'Fixture plan', 'focus' => 'Passing', 'principles' => ['SV Sissach principles'], 'qualityChecks' => ['SFV principles'],
            'exercises' => array_map(fn ($phase) => ['phase' => $phase] + $exercise, ['Einstieg', 'Hauptteil', 'Abschluss'])];
    }

    private function storedPlan(): int
    {
        return $this->insert('training_plans', ['owner_id' => $this->context->actor->id, 'team_season_id' => $this->team,
            'title' => 'Fixture plan', 'input_json' => json_encode($this->input() + ['teamName' => 'Fixture team']), 'plan_json' => json_encode($this->plan())]);
    }

    private function matchId(): int
    {
        return $this->insert('sfv_schedule', ['match_id' => 987654, 'season_id' => 2027, 'title' => 'Fixture A - Fixture B',
            'host_name' => 'Fixture A', 'guest_name' => 'Fixture B', 'starts_at' => now('Europe/Zurich')->addDay()->setTime(19, 30)->toIso8601String(),
            'location' => 'Sportzentrum Tannenbrunn - 2, Sissach', 'state' => 'Angesetzt', 'home' => false]);
    }

    public function test_contract_has_disjoint_actions_direct_approval_and_existing_permission_mappings(): void
    {
        $sfv = (new SfvApiActions)->definitions();
        $training = (new TrainingApiActions)->definitions();
        $this->assertEmpty(array_intersect_key($sfv, $training));
        $this->assertSame(['sfv.refresh', 'sfv.competition.preview', 'sfv.competition.apply', 'sfv.planning-import'], array_keys($sfv));
        $this->assertSame(['training.generate', 'training.review'], array_keys($training));
        foreach (['generate', 'review'] as $method) {
            $parameters = (new \ReflectionMethod(TrainingPlanService::class, $method))->getParameters();
            $authorization = end($parameters);
            $this->assertSame('authorization', $authorization->getName());
            $this->assertSame('Closure', $authorization->getType()->getName());
            $this->assertTrue($authorization->allowsNull());
            $this->assertNull($authorization->getDefaultValue());
        }
        $parameters = (new \ReflectionMethod(SfvPlanningService::class, 'refreshNow'))->getParameters();
        $authorization = end($parameters);
        $this->assertSame('authorization', $authorization->getName());
        $this->assertSame('Closure', $authorization->getType()->getName());
        $this->assertTrue($authorization->allowsNull());
        $this->assertNull($authorization->getDefaultValue());
        $this->assertSame(['planning:create' => 'New event only', 'planning:cancel' => 'Newly cancelled status',
            'planning:restore' => 'Restoring cancelled event'], $sfv['sfv.planning-import']['conditional_scopes']);
        foreach ($sfv + $training as $definition) {
            $this->assertSame('direct', $definition['approval']);
            $this->assertNotEmpty($definition['title']);
            $this->assertArrayNotHasKey('_request_key', $definition['fields']);
            foreach ($definition['scopes'] as $scope => [$module, $function]) {
                $this->assertSame($module.':'.($function === 'view' ? 'read' : $function), $scope);
                $this->assertArrayHasKey($function, PermissionCatalog::functions($module));
            }
        }
    }

    public function test_unknown_fields_types_and_domain_ids_are_rejected_before_providers(): void
    {
        $this->fixture();
        $this->fakeProviderBoundary();
        $c = $this->context;
        $training = new TrainingApiActions;
        foreach (['_request_key', 'request_key', 'teamName', 'tenant_id', 'provider', 'nested'] as $field) {
            $this->rejects(fn () => $training->validate($c, 'training.generate', 0, $this->input() + [$field => ['secret' => 'x']]));
        }
        foreach (['durationMinutes' => [], 'playerCount' => 'not a number', 'goal' => ['nested' => 'x']] as $field => $value) {
            $this->rejects(fn () => $training->validate($c, 'training.generate', 0, array_replace($this->input(), [$field => $value])));
        }
        $this->rejects(fn () => $training->validate($c, 'training.generate', 1, $this->input()), HttpException::class, 422);
        $this->rejects(fn () => $training->execute($c, 'training.generate', 0, $this->input()));
        $this->rejects(fn () => $training->execute($c, 'training.generate', 0, $this->input() + ['_request_key' => 'invalid']));
        $sfv = new SfvApiActions;
        $dates = ['from' => '2026-01-01', 'to' => '2026-07-06'];
        $this->assertSame($dates, $sfv->validate($c, 'sfv.refresh', 0, $dates));
        $this->rejects(fn () => $sfv->validate($c, 'sfv.refresh', $c->tenant->id, $dates), HttpException::class, 422);
        $this->rejects(fn () => $sfv->validate($c, 'sfv.refresh', 0, array_replace($dates, ['to' => '2026-07-07'])));
        foreach (['base_url', '_request_key', 'host', 'club_id'] as $field) {
            $this->rejects(fn () => $sfv->validate($c, 'sfv.refresh', 0, $dates + [$field => 'https://example.invalid']));
        }
        Http::assertNothingSent();
    }

    public function test_refresh_uses_existing_immediate_service_not_a_second_queue(): void
    {
        $this->fixture();
        $this->fakeProviderBoundary();
        $dates = ['from' => '2026-10-26', 'to' => '2026-10-27'];
        $this->mock(SfvPlanningService::class)->shouldReceive('refreshNow')->once()
            ->with($this->context->actor, $this->context->tenant, $dates, Mockery::type(\Closure::class))->andReturn(3);
        $this->assertSame(['refreshed' => 3], (new SfvApiActions)->execute($this->context, 'sfv.refresh', 0, $dates + ['_request_key' => (string) Str::uuid()]));
        Http::assertNothingSent();
    }

    public function test_generation_reuses_persisted_key_and_does_not_expand_worker_rights(): void
    {
        $this->fixture();
        $this->fakeProviderBoundary();
        $c = $this->context;
        $key = (string) Str::uuid();
        $this->mock(TrainingPlanAi::class)->shouldReceive('generate')->once()->withArgs(fn ($tenant, $input, $draft) => $tenant->id === $c->tenant->id
            && ! array_key_exists('_request_key', $input) && ! array_key_exists('team_season_id', $input) && $draft === null)->andReturn($this->plan());
        $api = new TrainingApiActions;
        $payload = $this->input() + ['_request_key' => $key];
        $worker = new Account;
        $worker->forceFill(['id' => 999999, 'active' => true]);
        $result = $api->execute($c, 'training.generate', 0, $payload, $worker);
        $this->assertSame($result, $api->execute($c, 'training.generate', 0, $payload));
        $row = $this->db->table('clubora.training_plan_requests')->where('tenant_id', $c->tenant->id)->sole();
        $this->assertSame($key, $row->request_key);
        $this->assertSame($c->actor->id, $row->actor_id);
        $this->assertSame(['plan_id'], array_keys($result));
        Http::assertNothingSent();
    }

    public function test_failed_or_uncertain_training_request_is_never_repeated(): void
    {
        $this->fixture();
        $this->fakeProviderBoundary();
        $api = new TrainingApiActions;
        $payload = $this->input() + ['_request_key' => (string) Str::uuid()];
        $this->mock(TrainingPlanAi::class)->shouldReceive('generate')->once()->andThrow(new \RuntimeException('Private provider body', 503));
        $this->rejects(fn () => $api->execute($this->context, 'training.generate', 0, $payload));
        $this->rejects(fn () => $api->execute($this->context, 'training.generate', 0, $payload));
        $query = $this->db->table('clubora.training_plan_requests')->where('tenant_id', $this->context->tenant->id);
        $this->assertSame('failed', (clone $query)->sole()->state);
        $query->update(['state' => 'pending']);
        $this->rejects(fn () => $api->execute($this->context, 'training.generate', 0, $payload));
        $this->assertFalse($this->db->table('clubora.training_plans')->where('tenant_id', $this->context->tenant->id)->exists());
        Http::assertNothingSent();
    }

    public function test_training_quota_blocks_provider_before_call(): void
    {
        $this->fixture();
        $this->fakeProviderBoundary();
        $this->insert('training_plan_requests', ['actor_id' => $this->context->actor->id, 'kind' => 'generate',
            'request_key' => (string) Str::uuid(), 'reference' => (string) Str::uuid()]);
        $this->mock(TrainingPlanAi::class)->shouldNotReceive('generate');
        $this->rejects(fn () => (new TrainingApiActions)->execute($this->context, 'training.generate', 0,
            $this->input() + ['_request_key' => (string) Str::uuid()]));
        Http::assertNothingSent();
    }

    public function test_review_is_revision_safe_quality_checked_and_not_applied_or_repeated(): void
    {
        $this->fixture();
        $this->fakeProviderBoundary();
        $id = $this->storedPlan();
        $api = new TrainingApiActions;
        $key = (string) Str::uuid();
        $this->rejects(fn () => $api->execute($this->context, 'training.review', $id, ['revision' => 2, '_request_key' => $key]));
        $plan = $this->plan();
        $plan['title'] = 'Review proposal';
        $this->mock(TrainingPlanAi::class)->shouldReceive('generate')->once()->andReturn($plan);
        $result = $api->execute($this->context, 'training.review', $id, ['revision' => 1, '_request_key' => $key]);
        $this->assertSame(['plan_id', 'revision', 'expires', 'plan'], array_keys($result));
        $this->assertSame($id, $result['plan_id']);
        $this->assertSame(1, $result['revision']);
        $this->assertGreaterThan(time(), $result['expires']);
        $this->assertSame($plan, $result['plan']);
        $this->assertSame('Fixture plan', $this->db->table('clubora.training_plans')->where('id', $id)->value('title'));
        $this->rejects(fn () => $api->execute($this->context, 'training.review', $id, ['revision' => 1, '_request_key' => $key]));
        $this->assertSame($plan, app(TrainingPlanContent::class)->validate($result['plan'], 60));
        Http::assertNothingSent();
    }

    public function test_fresh_token_scope_and_profile_revocation_block_processing(): void
    {
        $this->fixture();
        $this->fakeProviderBoundary();
        $api = new TrainingApiActions;
        $c = $this->context;
        $payload = $this->input() + ['_request_key' => (string) Str::uuid()];
        $api->validate($c, 'training.generate', 0, $this->input());
        $snapshot = ['account' => $c->actor->id, 'tenants' => collect(),
            'permissionCache' => ['permissions:'.$c->tenant->id => ['training.view', 'training.create']]];
        request()->attributes->set(BackofficeReadContext::class, $snapshot);
        $this->mock(TrainingPlanAi::class)->shouldNotReceive('generate');
        $c->token->forceFill(['abilities' => ['training:read']])->save();
        $this->rejects(fn () => $api->execute($c, 'training.generate', 0, $payload), HttpException::class, 403);
        $c->token->forceFill(['abilities' => $c->integration->scopes])->save();
        $this->db->table('clubora.access_profiles')->where('id', $this->profile)->update(['permissions' => json_encode(['training.view'])]);
        $this->rejects(fn () => $api->execute($c, 'training.generate', 0, $payload), HttpException::class, 403);
        $this->assertSame($snapshot, request()->attributes->get(BackofficeReadContext::class));
        $c->token->forceFill(['revoked_at' => now()])->save();
        $this->rejects(fn () => $api->execute($c, 'training.generate', 0, $payload), HttpException::class, 403);
        Http::assertNothingSent();
    }

    public function test_review_rejects_a_revision_changed_during_provider_call(): void
    {
        $this->fixture();
        $this->fakeProviderBoundary();
        $id = $this->storedPlan();
        $this->mock(TrainingPlanAi::class)->shouldReceive('generate')->once()->andReturnUsing(function () use ($id) {
            $this->db->table('clubora.training_plans')->where('id', $id)->increment('revision');

            return $this->plan();
        });
        $payload = ['revision' => 1, '_request_key' => (string) Str::uuid()];
        $api = new TrainingApiActions;
        $this->rejects(fn () => $api->execute($this->context, 'training.review', $id, $payload));
        $this->assertSame(2, $this->db->table('clubora.training_plans')->where('id', $id)->value('revision'));
        $this->assertSame('Fixture plan', $this->db->table('clubora.training_plans')->where('id', $id)->value('title'));
        Http::assertNothingSent();
    }

    public function test_review_quality_gate_rejects_invalid_provider_content_without_saving(): void
    {
        $this->fixture();
        $this->fakeProviderBoundary();
        $id = $this->storedPlan();
        $plan = $this->plan();
        $plan['qualityChecks'] = ['No philosophy evidence'];
        $this->mock(TrainingPlanAi::class)->shouldReceive('generate')->once()->andReturn($plan);
        $api = new TrainingApiActions;
        $payload = ['revision' => 1, '_request_key' => (string) Str::uuid()];
        $this->rejects(fn () => $api->execute($this->context, 'training.review', $id, $payload));
        $this->rejects(fn () => $api->execute($this->context, 'training.review', $id, $payload));
        $this->assertSame(1, $this->db->table('clubora.training_plans')->where('id', $id)->value('revision'));
        Http::assertNothingSent();
    }

    public function test_sfv_apply_requires_signage_scope_and_rejects_extra_json_fields(): void
    {
        $this->fixture();
        $api = new SfvApiActions;
        $c = $this->context;
        $id = $this->matchId();
        $preview = $api->execute($c, 'sfv.competition.preview', $id, []);
        $input = ['fingerprint' => $preview['fingerprint']];
        foreach (['after', 'host_room_id', 'sfv_revision', '_request_key'] as $field) {
            $this->rejects(fn () => $api->validate($c, 'sfv.competition.apply', $id, $input + [$field => ['unexpected' => 'x']]));
        }
        $c->token->forceFill(['abilities' => ['competitions:read', 'competitions:sync']])->save();
        $this->rejects(fn () => $api->execute($c, 'sfv.competition.apply', $id, $input), HttpException::class, 403);
        $this->assertFalse($this->db->table('clubora.competitions')->where('tenant_id', $c->tenant->id)->exists());
        Http::assertNothingSent();
    }

    public function test_foreign_and_inactive_teams_and_foreign_sfv_ids_are_inaccessible(): void
    {
        $this->fixture();
        $training = new TrainingApiActions;
        $foreign = Tenant::create(['slug' => 'foreign-provider-'.Str::random(12), 'name' => 'Foreign fixture']);
        $season = $this->db->table('clubora.seasons')->insertGetId(['tenant_id' => $foreign->id, 'name' => 'Foreign']);
        $team = $this->db->table('clubora.teams')->insertGetId(['tenant_id' => $foreign->id, 'name' => 'Foreign']);
        $foreignTeam = $this->db->table('clubora.team_seasons')->insertGetId(['tenant_id' => $foreign->id, 'team_id' => $team,
            'season_id' => $season, 'label' => 'Foreign']);
        $this->rejects(fn () => $training->validate($this->context, 'training.generate', 0,
            array_replace($this->input(), ['team_season_id' => $foreignTeam])), HttpException::class, 403);
        $this->db->table('clubora.team_seasons')->where('id', $this->team)->update(['active' => false]);
        $this->rejects(fn () => $training->validate($this->context, 'training.generate', 0, $this->input()), HttpException::class, 403);
        $id = $this->matchId();
        $this->db->table('clubora.sfv_schedule')->where('id', $id)->update(['tenant_id' => $foreign->id]);
        $this->rejects(fn () => (new SfvApiActions)->validate($this->context, 'sfv.competition.preview', $id, []), HttpException::class, 404);
        Http::assertNothingSent();
    }

    public function test_sfv_apply_rechecks_fingerprint_and_preserves_local_rooms(): void
    {
        $this->fixture();
        $api = new SfvApiActions;
        $c = $this->context;
        $id = $this->matchId();
        $preview = $api->execute($c, 'sfv.competition.preview', $id, []);
        $result = $api->execute($c, 'sfv.competition.apply', $id, ['fingerprint' => $preview['fingerprint']]);
        $competition = Competition::findOrFail($result['competition_id']);
        $room = $this->db->table('clubora.training_options')->where('tenant_id', $c->tenant->id)->where('category', 'room')->value('id');
        $this->assertNotNull($room);
        $competition->forceFill(['host_room_id' => $room, 'guest_room_id' => $room, 'remarks' => 'Local note'])->save();
        $preview = $api->execute($c, 'sfv.competition.preview', $id, []);
        $this->db->table('clubora.sfv_schedule')->where('id', $id)->update(['host_name' => 'Updated SFV host', 'revision' => 2]);
        $this->rejects(fn () => $api->execute($c, 'sfv.competition.apply', $id, ['fingerprint' => $preview['fingerprint']]));
        $fresh = $api->execute($c, 'sfv.competition.preview', $id, []);
        $api->execute($c, 'sfv.competition.apply', $id, ['fingerprint' => $fresh['fingerprint']]);
        $competition->refresh();
        $this->assertSame('Updated SFV host', $competition->host_name);
        $this->assertEquals($room, $competition->host_room_id);
        $this->assertEquals($room, $competition->guest_room_id);
        $this->assertSame('Local note', $competition->remarks);
        $this->assertSame(2, $competition->revision);
        Http::assertNothingSent();
    }

    private function planningFixture(): array
    {
        $this->fixture();
        $c = $this->context;
        TeamPlanningService::seedOptions($c->tenant->id);
        $permissions = json_decode($this->db->table('clubora.access_profiles')->where('id', $this->profile)->value('permissions'), true);
        $permissions = array_merge($permissions, ['planning.create', 'planning.cancel', 'planning.restore']);
        $this->db->table('clubora.access_profiles')->where('id', $this->profile)->update(['permissions' => json_encode($permissions)]);
        $scopes = array_merge($c->integration->scopes, ['planning:create', 'planning:cancel', 'planning:restore']);
        $c->integration->forceFill(['scopes' => $scopes])->save();
        $c->token->forceFill(['abilities' => $scopes])->save();
        $team = $this->db->table('clubora.team_seasons')->where('id', $this->team)->value('team_id');
        $this->db->table('clubora.teams')->where('id', $team)->update(['sfv_team_id' => 123]);
        $match = $this->matchId();
        $this->db->table('clubora.sfv_schedule')->where('id', $match)->update(['team_a_id' => 123, 'team_b_id' => 456]);
        $starts = now('Europe/Zurich')->addDays(5)->setTime(18, 0);

        return [$match, ['team_season_id' => $this->team, 'sfv_revision' => 1, 'event_revision' => 0,
            'title' => 'SFV planning fixture', 'kind' => 'MEISTERSCHAFT', 'mode' => 'ANMELDEN', 'status' => 'AKTIV',
            'starts_at' => $starts->format('Y-m-d\TH:i'), 'ends_at' => $starts->copy()->addHours(2)->format('Y-m-d\TH:i'),
            'deadline_at' => $starts->copy()->subDay()->format('Y-m-d\TH:i'), 'capacity' => 7,
            'location' => 'Fixture pitch', 'description' => 'Private coach description']];
    }

    public function test_planning_import_links_and_updates_event_preserving_capacity_deadline_and_private_data(): void
    {
        [$match, $input] = $this->planningFixture();
        $api = new SfvApiActions;
        $c = $this->context;
        $result = $api->execute($c, 'sfv.planning-import', $match, $input + ['_request_key' => (string) Str::uuid()]);
        $this->assertSame(['event_id'], array_keys($result));
        $event = $result['event_id'];
        $member = $this->insert('members', ['first_name' => 'Private', 'last_name' => 'Fixture']);
        $private = Crypt::encryptString('Private participation note');
        $this->insert('team_participations', ['event_id' => $event, 'member_id' => $member, 'status' => 'ABWESEND',
            'attendance' => 'ENTSCHULDIGT', 'source' => 'trainer', 'comment_encrypted' => $private]);
        $before = $this->db->table('clubora.team_events')->where('id', $event)->first();
        $update = array_replace($input, ['event_revision' => 1, 'title' => 'Updated SFV fixture']);
        unset($update['description']);
        // Update needs no create scope, but import still requires update, sync and notification rights.
        $c->token->forceFill(['abilities' => array_values(array_diff($c->token->abilities, ['planning:create']))])->save();
        $this->assertSame($result, $api->execute($c, 'sfv.planning-import', $match, $update));
        $after = $this->db->table('clubora.team_events')->where('id', $event)->first();
        $this->assertSame(987654, $after->sfv_match_id);
        $this->assertSame(2, $after->revision);
        $this->assertSame(7, $after->capacity);
        $this->assertSame($before->deadline_at, $after->deadline_at);
        $this->assertSame($before->description, $after->description);
        $participation = $this->db->table('clubora.team_participations')->where('event_id', $event)->sole();
        $this->assertSame($private, $participation->comment_encrypted);
        $this->assertSame('ENTSCHULDIGT', $participation->attendance);
        $this->assertSame(1, $participation->revision);
        $this->assertSame(1, $this->db->table('clubora.team_events')->where('tenant_id', $c->tenant->id)->count());
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_planning_import_rejects_unknown_fields_types_options_and_stale_revisions(): void
    {
        [$match, $input] = $this->planningFixture();
        $api = new SfvApiActions;
        $c = $this->context;
        foreach (['tenant_id', 'event_id', 'revision', 'sfv_match_id', 'participants', 'comment_encrypted', '_request_key'] as $field) {
            $this->rejects(fn () => $api->validate($c, 'sfv.planning-import', $match, $input + [$field => ['unexpected' => 'x']]));
        }
        foreach (['capacity' => [], 'kind' => 'UNKNOWN', 'mode' => [], 'deadline_at' => 'tomorrow', 'sfv_revision' => 2] as $field => $value) {
            $this->rejects(fn () => $api->validate($c, 'sfv.planning-import', $match, array_replace($input, [$field => $value])));
        }
        $withoutCapacity = $input;
        unset($withoutCapacity['capacity']);
        $this->rejects(fn () => $api->validate($c, 'sfv.planning-import', $match, $withoutCapacity));
        $this->rejects(fn () => $api->validate($c, 'sfv.planning-import', $match, array_replace($input, ['event_revision' => 1])), HttpException::class, 409);
        $api->execute($c, 'sfv.planning-import', $match, $input);
        $this->rejects(fn () => $api->execute($c, 'sfv.planning-import', $match, $input));
        $this->db->table('clubora.sfv_schedule')->where('id', $match)->increment('revision');
        $this->rejects(fn () => $api->execute($c, 'sfv.planning-import', $match, array_replace($input, ['event_revision' => 1])));
        Http::assertNothingSent();
    }

    public function test_planning_import_enforces_conditional_create_cancel_restore_and_send_scopes(): void
    {
        [$match, $input] = $this->planningFixture();
        $api = new SfvApiActions;
        $c = $this->context;
        $all = $c->token->abilities;
        foreach (['planning:create', 'planning:update', 'planning:sync', 'planning:send'] as $scope) {
            $c->token->forceFill(['abilities' => array_values(array_diff($all, [$scope]))])->save();
            $this->rejects(fn () => $api->execute($c, 'sfv.planning-import', $match, $input), HttpException::class, 403);
        }
        $c->token->forceFill(['abilities' => $all])->save();
        $result = $api->execute($c, 'sfv.planning-import', $match, $input);
        $cancel = array_replace($input, ['event_revision' => 1, 'status' => 'ABGESAGT']);
        $c->token->forceFill(['abilities' => array_values(array_diff($all, ['planning:cancel']))])->save();
        $this->rejects(fn () => $api->execute($c, 'sfv.planning-import', $match, $cancel), HttpException::class, 403);
        $c->token->forceFill(['abilities' => $all])->save();
        $api->execute($c, 'sfv.planning-import', $match, $cancel);
        $restore = array_replace($input, ['event_revision' => 2]);
        $c->token->forceFill(['abilities' => array_values(array_diff($all, ['planning:restore']))])->save();
        $this->rejects(fn () => $api->execute($c, 'sfv.planning-import', $match, $restore), HttpException::class, 403);
        $c->token->forceFill(['abilities' => $all])->save();
        $api->execute($c, 'sfv.planning-import', $match, $restore);
        $this->assertSame(3, $this->db->table('clubora.team_events')->where('id', $result['event_id'])->value('revision'));
        Http::assertNothingSent();
    }

    public function test_planning_import_rejects_foreign_match_team_identity_and_inactive_team(): void
    {
        [$match, $input] = $this->planningFixture();
        $api = new SfvApiActions;
        $c = $this->context;
        $this->db->table('clubora.sfv_schedule')->where('id', $match)->update(['team_a_id' => 789]);
        $this->rejects(fn () => $api->validate($c, 'sfv.planning-import', $match, $input));
        $this->db->table('clubora.sfv_schedule')->where('id', $match)->update(['team_a_id' => 123]);
        $this->db->table('clubora.team_seasons')->where('id', $this->team)->update(['active' => false]);
        $this->rejects(fn () => $api->validate($c, 'sfv.planning-import', $match, $input), HttpException::class, 403);
        $this->db->table('clubora.team_seasons')->where('id', $this->team)->update(['active' => true]);
        $foreign = Tenant::create(['slug' => 'planning-foreign-'.Str::random(12), 'name' => 'Foreign fixture']);
        $this->db->table('clubora.sfv_schedule')->where('id', $match)->update(['tenant_id' => $foreign->id]);
        $this->rejects(fn () => $api->validate($c, 'sfv.planning-import', $match, $input), HttpException::class, 404);
        Http::assertNothingSent();
    }

    public function test_planning_import_rolls_back_if_scope_is_removed_after_service_write(): void
    {
        [$match, $input] = $this->planningFixture();
        $c = $this->context;
        $api = new SfvApiActions;
        $real = new SfvPlanningService(app(TeamPlanningService::class));
        $all = $c->token->abilities;
        foreach (['planning:create' => null, 'planning:send' => null, 'planning:cancel' => 'AKTIV', 'planning:restore' => 'ABGESAGT'] as $scope => $previousStatus) {
            $event = null;
            $payload = $input;
            $this->app->instance(SfvPlanningService::class, $real);
            if ($previousStatus !== null) {
                $event = $api->execute($c, 'sfv.planning-import', $match, $input)['event_id'];
                $this->db->table('clubora.team_events')->where('id', $event)->update(['status' => $previousStatus]);
                $payload = array_replace($input, ['event_revision' => 1, 'status' => $previousStatus === 'AKTIV' ? 'ABGESAGT' : 'AKTIV']);
            }
            $this->mock(SfvPlanningService::class)->shouldReceive('import')->once()->andReturnUsing(function ($actor, $tenant, $id, $data) use ($real, $c, $scope, $all) {
                $result = $real->import($actor, $tenant, $id, $data);
                $c->token->forceFill(['abilities' => array_values(array_diff($all, [$scope]))])->save();

                return $result;
            });
            $messages = $this->db->table('clubora.team_planning_messages')->where('tenant_id', $c->tenant->id)->count();
            $this->rejects(fn () => $api->execute($c, 'sfv.planning-import', $match, $payload), HttpException::class, 403);
            $this->assertSame($messages, $this->db->table('clubora.team_planning_messages')->where('tenant_id', $c->tenant->id)->count());
            if ($event !== null) {
                $row = $this->db->table('clubora.team_events')->where('id', $event)->first();
                $this->assertSame(1, $row->revision);
                $this->assertSame($previousStatus, $row->status);
                $this->db->table('clubora.team_planning_messages')->where('event_id', $event)->delete();
                $this->db->table('clubora.team_events')->where('id', $event)->delete();
            } else {
                $this->assertFalse($this->db->table('clubora.team_events')->where('tenant_id', $c->tenant->id)->exists());
            }
            $c->token->refresh();
        }
        Http::assertNothingSent();
    }

    public function test_network_capable_adapters_reject_ambient_sql_transactions_before_services(): void
    {
        $this->fixture();
        $c = $this->context;
        $this->mock(TrainingPlanService::class)->shouldNotReceive('generate', 'review');
        $this->mock(SfvPlanningService::class)->shouldNotReceive('refreshNow');
        $this->rejects(fn () => (new TrainingApiActions)->execute($c, 'training.generate', 0,
            $this->input() + ['_request_key' => (string) Str::uuid()]), HttpException::class, 409);
        $this->rejects(fn () => (new TrainingApiActions)->execute($c, 'training.review', 1,
            ['revision' => 1, '_request_key' => (string) Str::uuid()]), HttpException::class, 409);
        $this->rejects(fn () => (new SfvApiActions)->execute($c, 'sfv.refresh', 0,
            ['from' => '2026-10-26', 'to' => '2026-10-27']), HttpException::class, 409);
        Http::assertNothingSent();
    }

    public static function trainingRevocationCases(): array
    {
        $cases = [];
        foreach (['training.generate', 'training.review'] as $action) {
            foreach (['token', 'scope', 'profile'] as $revocation) {
                $cases[$action.' '.$revocation] = [$action, $revocation];
            }
        }

        return $cases;
    }

    #[DataProvider('trainingRevocationCases')]
    public function test_training_revoked_during_ai_response_cannot_persist_or_complete_or_repeat(string $action, string $revocation): void
    {
        $this->fixture();
        $this->fakeProviderBoundary();
        $c = $this->context;
        $id = $action === 'training.review' ? $this->storedPlan() : 0;
        $input = $id ? ['revision' => 1] : $this->input();
        $payload = $input + ['_request_key' => (string) Str::uuid()];
        $abilities = $c->token->abilities;
        $permissions = $this->db->table('clubora.access_profiles')->where('id', $this->profile)->value('permissions');
        $this->mock(TrainingPlanAi::class)->shouldReceive('generate')->once()->andReturnUsing(function () use ($c, $action, $revocation, $abilities) {
            // There is only the test rollback fixture, no production transaction across the remote call.
            $this->assertSame(1, $this->db->transactionLevel());
            if ($revocation === 'token') {
                $c->token->forceFill(['revoked_at' => now()])->save();
            } elseif ($revocation === 'scope') {
                $scope = $action === 'training.generate' ? 'training:create' : 'training:approve';
                $c->token->forceFill(['abilities' => array_values(array_diff($abilities, [$scope]))])->save();
            } else {
                $this->db->table('clubora.access_profiles')->where('id', $this->profile)->update(['permissions' => '[]']);
            }

            return $this->plan();
        });
        $api = new TrainingApiActions;
        $this->rejects(fn () => $api->execute($c, $action, $id, $payload), HttpException::class, 403);
        $attempt = $this->db->table('clubora.training_plan_requests')->where('tenant_id', $c->tenant->id)->sole();
        $this->assertSame($payload['_request_key'], $attempt->request_key);
        $this->assertSame('pending', $attempt->state);
        $this->assertNull($attempt->plan_id);
        $this->assertSame($id ? 1 : 0, $this->db->table('clubora.training_plans')->where('tenant_id', $c->tenant->id)->count());
        if ($id) {
            $row = $this->db->table('clubora.training_plans')->where('id', $id)->first();
            $this->assertSame(1, $row->revision);
            $this->assertEquals($this->plan(), json_decode($row->plan_json, true));
        }
        // Restoring authorization does not make an uncertain, already-sent request repeatable.
        $c->token->forceFill(['revoked_at' => null, 'abilities' => $abilities])->save();
        $this->db->table('clubora.access_profiles')->where('id', $this->profile)->update(['permissions' => $permissions]);
        $this->rejects(fn () => $api->execute($c, $action, $id, $payload));
        $this->assertSame(1, $this->db->table('clubora.training_plan_requests')->where('tenant_id', $c->tenant->id)->count());
        Http::assertNothingSent();
    }

    public function test_sfv_apply_reauthorizes_after_tenant_lock_before_nested_service_write(): void
    {
        $this->fixture();
        $c = $this->context;
        $api = new SfvApiActions;
        $id = $this->matchId();
        $preview = $api->execute($c, 'sfv.competition.preview', $id, []);
        $real = new TeamPlanningService;
        $this->partialMock(TeamPlanningService::class)->shouldReceive('lock')->once()->andReturnUsing(function ($tenant) use ($real, $c) {
            $this->assertSame(2, $this->db->transactionLevel());
            $real->lock($tenant);
            $c->token->forceFill(['revoked_at' => now()])->save();
        });
        $this->mock(SfvCompetitionService::class)->shouldNotReceive('apply');
        $this->rejects(fn () => $api->execute($c, 'sfv.competition.apply', $id, ['fingerprint' => $preview['fingerprint']]), HttpException::class, 403);
        $this->assertFalse($this->db->table('clubora.competitions')->where('tenant_id', $c->tenant->id)->exists());
        Http::assertNothingSent();
    }

    public function test_sfv_refresh_revoked_during_http_response_cannot_upsert_schedule(): void
    {
        $this->fixture();
        $this->fakeProviderBoundary();
        $c = $this->context;
        $existing = $this->matchId();
        $before = (array) $this->db->table('clubora.sfv_schedule')->where('id', $existing)->first();
        foreach (['SFV_APPLICATION_KEY' => 'fixture-key', 'SFV_APPLICATION_PASS' => 'fixture-pass', 'SFV_CLUB_ID' => '506'] as $key => $value) {
            $this->insert('tenant_settings', ['area' => 'sfv', 'key' => $key, 'label' => $key, 'value_type' => 'string',
                'encrypted_value' => Crypt::encryptString($value), 'enabled' => true, 'is_secret' => true]);
        }
        $locked = false;
        $real = new TeamPlanningService;
        $this->partialMock(TeamPlanningService::class)->shouldReceive('lock')->once()->andReturnUsing(function ($tenant) use ($real, &$locked) {
            $this->assertSame(2, $this->db->transactionLevel());
            $real->lock($tenant);
            $locked = true;
        });
        Http::fake([
            'https://stg-club-api-services.football.ch/api/token' => Http::response(['token' => 'fixture-token']),
            'https://stg-club-api-services.football.ch/api/club/schedule*' => function () use ($c) {
                $this->assertSame(1, $this->db->transactionLevel());
                $c->token->forceFill(['revoked_at' => now()])->save();

                return Http::response(array_map(fn ($id) => ['matchId' => $id, 'teamAId' => 123, 'teamBId' => 456,
                    'teamNameA' => 'Must not persist', 'teamNameB' => 'Guest', 'matchDate' => now('Europe/Zurich')->addDay()->toIso8601String(),
                    'stadiumPlaygroundName' => 'Fixture pitch', 'matchStateName' => 'Angesetzt'], [987654, 987655]));
            },
        ]);
        $this->rejects(fn () => (new SfvApiActions)->execute($c, 'sfv.refresh', 0,
            ['from' => now('Europe/Zurich')->toDateString(), 'to' => now('Europe/Zurich')->addDays(2)->toDateString()]), HttpException::class, 403);
        $this->assertTrue($locked);
        $this->assertSame($before, (array) $this->db->table('clubora.sfv_schedule')->where('id', $existing)->first());
        $this->assertSame(1, $this->db->table('clubora.sfv_schedule')->where('tenant_id', $c->tenant->id)->count());
        $this->assertFalse($this->db->table('clubora.object_changes')->where('tenant_id', $c->tenant->id)
            ->where('object_type', 'sfv_schedule_refresh')->exists());
        Http::assertSentCount(2);
    }
}
