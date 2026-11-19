<?php

namespace Tests\Feature;

use App\ClubUnio\Mobile\{MobileAccess, MobileAccountController, MobileBootstrapState, MobileFollowupModules,
    MobileLanguages, MobilePitchController, MobilePitchDocumentation, MobilePitchReady, MobileReadContext, MobileSessions, MobileTeamsData};
use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\{MemberAccessService, MobileModuleSettings, ModulePermissionService, PitchPlanningProjection, PitchPlanningService, TenantDomainService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Http, Mail, Route};
use Tests\TestCase;

class MobilePitchControllerTest extends TestCase
{
    private object $domain;
    private object $sessions;
    private object $access;
    private object $teams;
    private object $projection;
    private object $permissions;
    private object $visibility;
    private string $base = '/api/mobile/v1/clubs/1';

    protected function setUp(): void
    {
        parent::setUp();
        // Never resolve the configured shared PostgreSQL connection in this adapter suite.
        config(['database.connections.clubunio' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'clubunio-mobile.enabled' => true, 'clubunio-mobile.native_pitch_planning_enabled' => true,
            'clubunio-mobile.native_facilities_enabled' => true, 'clubunio-mobile.native_bus_enabled' => false,
            'cache.default' => 'array']);
        DB::purge('clubunio');
        $db = DB::connection('clubunio');
        $db->statement("ATTACH DATABASE ':memory:' AS clubunio");
        $db->statement('CREATE TABLE clubunio.tenants (id INTEGER PRIMARY KEY, name TEXT)');
        $db->statement('CREATE TABLE clubunio.mobile_sessions (id TEXT PRIMARY KEY)');
        $db->statement('CREATE TABLE clubunio.pitch_request_idempotency (tenant_id INTEGER, account_id INTEGER, key_hash TEXT, payload_hash TEXT, response_encrypted TEXT NOT NULL, PRIMARY KEY(tenant_id,account_id,key_hash))');
        $db->statement('CREATE TABLE clubunio.pitch_test_effects (id INTEGER PRIMARY KEY, kind TEXT)');
        $db->table('clubunio.tenants')->insert([['id' => 1, 'name' => 'Synthetic'], ['id' => 2, 'name' => 'Other']]);
        $db->table('clubunio.mobile_sessions')->insert(['id' => 'synthetic-session']);
        Http::preventStrayRequests();
        Mail::fake();
        $this->app->instance(TenantDomainService::class, new class { public function resolve($request) { return null; } });
        $this->app->instance(MobileReadContext::class, new class { public function during($request, $actor, $callback) { return $callback(); } });
        $this->app->instance(MobileBootstrapState::class, new class { public function authorizeTenant($request, $tenant): void {} });
        $this->app->instance(MobileLanguages::class, new class {
            public string $language = 'en';
            public function forAccount($actor): array { return ['language' => $this->language]; }
        });
        $this->sessions = new class {
            public int $calls = 0;
            public bool $revokeOnWrite = false;
            public int $accountId = 17;
            public function authenticate($request): Account {
                $this->calls++;
                abort_unless($request->bearerToken() === 'personal-test-token', 401);
                abort_if($this->revokeOnWrite && $this->calls > 1, 401);
                $request->attributes->set('mobile.session', (object) ['id' => 'synthetic-session']);
                return (new Account)->forceFill(['id' => $this->accountId, 'active' => true, 'api_only' => false, 'display_name' => 'Synthetic']);
            }
        };
        $this->app->instance(MobileSessions::class, $this->sessions);
        $this->access = new class {
            public int $calls = 0;
            public array $tenants = [1];
            public function enter($actor, $tenant): void { $this->calls++; abort_unless(in_array($tenant->id, $this->tenants, true), 403); }
            public function teams($actor, $tenant) { return new class { public function exists(): bool { return false; } }; }
        };
        $this->app->instance(MobileAccess::class, $this->access);
        $this->teams = new class {
            public array $seen = [];
            public function team($actor, $tenant, $id): object {
                $this->seen[] = $id; abort_unless($id === 9, 404); return (object) ['id' => $id];
            }
        };
        $this->app->instance(MobileTeamsData::class, $this->teams);
        $this->domain = new class {
            public array $calls = [];
            public ?int $failure = null;
            public bool $visible = true;
            public bool $denyReplay = false;
            public bool $recordEffects = false;
            public int $replayChecks = 0;
            public array $result = ['key' => 'training:3:2026-11-04', 'revision' => 'opaque-v2', 'status' => 'approved'];
            public function canView($actor, $tenant): bool { return $this->visible; }
            public function options(...$args): array { return $this->call(__FUNCTION__, $args); }
            public function calendar(...$args): array { return $this->call(__FUNCTION__, $args); }
            public function requests(...$args): array { return $this->call(__FUNCTION__, $args); }
            public function requestFriendly(...$args): array { return $this->call(__FUNCTION__, $args); }
            public function authorizeFriendlyRequest(...$args): void { $this->replayChecks++; abort_if($this->denyReplay, 403); }
            public function move(...$args): array { return $this->call(__FUNCTION__, $args); }
            public function decide(...$args): array { return $this->call(__FUNCTION__, $args); }
            public function configureCompetition(...$args): array { return $this->call(__FUNCTION__, $args); }
            private function call($method, $args): array {
                $this->calls[] = [$method, $args];
                if ($method === 'requestFriendly' && $this->recordEffects) {
                    DB::connection('clubunio')->table('clubunio.pitch_test_effects')->insert([['kind' => 'request'], ['kind' => 'notification']]);
                }
                if ($this->failure) abort($this->failure);
                return $this->result;
            }
        };
        $this->app->instance(PitchPlanningService::class, $this->domain);
        $this->projection = new class { public bool $ready = true; public function ready(): bool { return $this->ready; } };
        $this->app->instance(PitchPlanningProjection::class, $this->projection);
        $this->permissions = new class { public bool $view = false; public function allows(...$args): bool { return $this->view; } };
        $this->app->instance(ModulePermissionService::class, $this->permissions);
        $this->app->instance(MemberAccessService::class, new class {
            public function membership(...$args): ?object { return null; }
            public bool $allowed = false;
            public function canEnter($actor, $tenant): bool { return $this->allowed; }
        });
        $this->visibility = new class { public array $keys = ['Platzverfugbarkeit']; public function visibleKeys(...$args): array { return $this->keys; } };
        $this->app->instance(MobileModuleSettings::class, $this->visibility);
        $this->app->instance(MobileFollowupModules::class, new class { public function allowed(...$args): bool { return false; } });
        $part = json_decode(file_get_contents(base_path('lang/parts/pitch-api.en.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->app['translator']->setLoaded(['*' => ['*' => ['en' => $part,
            'de' => array_combine(array_keys($part), array_keys($part))]]]);
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        DB::purge('clubunio');
        parent::tearDown();
    }

    private function personal(): static { $this->withToken('personal-test-token'); return $this; }
    private function placement(): array
    {
        return ['field_id' => 4, 'area_id' => null, 'date' => '2026-11-04', 'starts_at' => '18:30', 'duration_minutes' => 120];
    }

    public function test_all_eight_routes_use_personal_and_pitch_middleware(): void
    {
        $spec = (new MobilePitchDocumentation)->specification();
        $this->assertCount(7, $spec['paths']);
        $this->assertSame(8, array_sum(array_map('count', $spec['paths'])));
        foreach ($spec['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                $url = '/api/mobile/v1'.str_replace(['{tenant}', '{booking}', '{team}', '{competition}'], ['1', '7', '9', '11'], $path);
                $route = Route::getRoutes()->match(Request::create($url, strtoupper($method)));
                $this->assertContains(\App\ClubUnio\Mobile\MobileMiddleware::class, $route->gatherMiddleware());
                $this->assertContains(MobilePitchReady::class, $route->gatherMiddleware());
                $this->assertStringStartsWith(MobilePitchController::class.'@', $route->getActionName());
                $this->assertArrayHasKey('503', $operation['responses']);
                $this->assertArrayNotHasKey('Idempotency-Key', array_column($operation['parameters'], 'name', 'name'));
            }
        }
    }

    public function test_authentication_tenant_and_feature_gates_run_before_domain(): void
    {
        $this->getJson($this->base.'/pitch-planning/options')->assertUnauthorized();
        $this->withToken('integration-token')->getJson($this->base.'/pitch-planning/options')->assertUnauthorized();
        $this->personal()->getJson('/api/mobile/v1/clubs/2/pitch-planning/options')->assertForbidden();
        config(['clubunio-mobile.native_pitch_planning_enabled' => false]);
        $this->getJson($this->base.'/pitch-planning/options')->assertStatus(503);
        $this->assertSame([], $this->domain->calls);
    }

    public function test_calendar_forwards_civil_filters_and_preserves_service_dto(): void
    {
        $this->domain->result = ['from' => '2026-10-25', 'to' => '2026-10-25', 'timezone' => 'Europe/Zurich',
            'entries' => [['key' => 'training:3:2026-10-25', 'original_date' => '2026-10-25', 'date' => '2026-10-25',
                'starts_at' => '02:30', 'revision' => 'opaque', 'conflicts' => [['type' => 'field_unavailable', 'key' => null]]]]];
        $this->personal()->getJson($this->base.'/pitch-planning/calendar?from=2026-10-25&to=2026-10-25&period_id=2')
            ->assertOk()->assertExactJson(['data' => $this->domain->result])->assertHeader('Cache-Control', 'no-store, private');
        [$name, $arguments] = $this->domain->calls[0];
        $this->assertSame('calendar', $name);
        $this->assertSame(17, $arguments[0]->id);
        $this->assertSame(1, $arguments[1]->id);
        $this->assertSame(['from' => '2026-10-25', 'to' => '2026-10-25', 'period_id' => '2'], $arguments[2]);
    }

    public function test_inbox_forwards_only_pagination_and_status_and_preserves_contract(): void
    {
        $this->domain->result = ['timezone' => 'Europe/Zurich', 'fields' => [], 'teams' => [], 'entries' => [],
            'capabilities' => ['view' => true, 'request' => false, 'manage' => false, 'approve' => false],
            'can_filter_status' => false, 'page' => 1, 'has_more' => false];
        $url = $this->base.'/pitch-planning/requests';
        $this->personal()->getJson($url)->assertOk()->assertExactJson(['data' => $this->domain->result])
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame('requests', $this->domain->calls[0][0]);
        $this->assertSame(17, $this->domain->calls[0][1][0]->id);
        $this->assertSame(1, $this->domain->calls[0][1][1]->id);
        $this->assertSame([], $this->domain->calls[0][1][2]);
        $this->getJson($url.'?page=2&per_page=100&status=cancelled')->assertOk();
        $this->assertSame(['page' => '2', 'per_page' => '100', 'status' => 'cancelled'], $this->domain->calls[1][1][2]);
        foreach (['page=0', 'page=-1', 'page=1.5', 'page=2147483648', 'per_page=0', 'per_page=101',
            'status=unknown', 'status=', 'status[]=pending', 'from=2026-11-05', 'to=2026-11-05',
            'team_season_id=9', 'requester_account_id=17', 'tenant_id=2'] as $query) {
            $this->getJson($url.'?'.$query)->assertUnprocessable();
        }
        $this->assertCount(2, $this->domain->calls);
        $this->domain->failure = 403;
        $this->getJson($url.'?status=pending')->assertForbidden()->assertJsonPath('error.code', 'forbidden');
    }

    public function test_inbox_auth_tenant_and_feature_gates_and_openapi(): void
    {
        $url = $this->base.'/pitch-planning/requests';
        $this->getJson($url)->assertUnauthorized();
        $this->withToken('integration-token')->getJson($url)->assertUnauthorized();
        $this->personal()->getJson('/api/mobile/v1/clubs/2/pitch-planning/requests')->assertForbidden();
        config(['clubunio-mobile.native_pitch_planning_enabled' => false]);
        $this->getJson($url)->assertStatus(503);
        $this->assertSame([], $this->domain->calls);
        $spec = (new MobilePitchDocumentation)->specification();
        $operation = $spec['paths']['/clubs/{tenant}/pitch-planning/requests']['get'];
        $this->assertSame(['tenant', 'page', 'per_page', 'status'], array_column($operation['parameters'], 'name'));
        $this->assertSame(30, $operation['parameters'][2]['schema']['default']);
        $this->assertSame(100, $operation['parameters'][2]['schema']['maximum']);
        $this->assertSame(['timezone', 'fields', 'teams', 'entries', 'capabilities', 'can_filter_status', 'page', 'has_more'],
            $spec['components']['schemas']['RequestInbox']['required']);
        $entry = $spec['components']['schemas']['RequestInboxEntry'];
        $this->assertSame(['request'], $entry['properties']['source']['enum']);
        $this->assertSame(0, $entry['properties']['conflicts']['maxItems']);
        $this->assertArrayNotHasKey('requester_account_id', $entry['properties']);
        $this->assertArrayNotHasKey('notes', $entry['properties']);
        $this->assertSame($spec['components']['schemas']['Entry']['required'], $entry['required']);
    }

    public function test_team_effective_calendar_scopes_before_projection_and_rejects_overrides(): void
    {
        $url = $this->base.'/my-teams/9/pitch-calendar?from=2026-11-04&to=2026-11-10';
        $this->personal()->getJson($url)->assertOk();
        $this->assertSame(9, $this->domain->calls[0][1][2]['team_season_id']);
        $this->assertSame([9], $this->teams->seen);
        $this->getJson($url.'&team_season_id=10')->assertUnprocessable();
        $this->getJson(str_replace('/9/', '/10/', $url))->assertNotFound();
        $this->assertCount(1, $this->domain->calls);
    }

    public function test_request_reauthenticates_and_does_not_allow_authority_mass_assignment(): void
    {
        $body = $this->placement() + ['team_season_id' => 9, 'opponent' => 'Unchanged free text', 'notes' => 'Original note', 'request_id' => 'synthetic-request-1'];
        unset($body['duration_minutes']);
        $this->domain->result = ['key' => 'request:5', 'status' => 'pending', 'revision' => '1'];
        $this->personal()->postJson($this->base.'/pitch-planning/requests', $body)->assertOk()->assertJsonPath('data.status', 'pending');
        $this->assertSame(2, $this->sessions->calls);
        $this->assertSame(2, $this->access->calls);
        $this->assertSame('requestFriendly', $this->domain->calls[0][0]);
        $expected = $body + ['duration_minutes' => 120];
        unset($expected['request_id']);
        $this->assertEquals($expected, $this->domain->calls[0][1][2]);
        foreach (['status' => 'approved', 'tenant_id' => 2, 'actor_id' => 99, 'sfv_authoritative' => true] as $key => $value) {
            $this->postJson($this->base.'/pitch-planning/requests', $body + [$key => $value])->assertUnprocessable();
        }
        $this->assertCount(1, $this->domain->calls);
        $this->assertSame(0, DB::connection('clubunio')->transactionLevel());
    }

    public function test_move_preserves_opaque_identity_and_service_conflicts(): void
    {
        $body = $this->placement() + ['key' => 'training:3:2026-11-02', 'revision' => 'sha256:opaque-original'];
        $this->personal()->postJson($this->base.'/pitch-planning/move', $body)->assertOk();
        $arguments = $this->domain->calls[0][1];
        $this->assertSame($body['key'], $arguments[2]);
        $this->assertSame($body['revision'], $arguments[3]['revision']);
        $this->assertArrayNotHasKey('key', $arguments[3]);
        $this->domain->failure = 409;
        $this->postJson($this->base.'/pitch-planning/move', $body)->assertConflict()->assertJsonPath('error.code', 'conflict');
        $this->assertSame(0, DB::connection('clubunio')->transactionLevel());
    }

    public function test_friendly_replay_returns_original_and_never_duplicates_transactional_effects(): void
    {
        $body = $this->placement() + ['team_season_id' => 9, 'opponent' => 'Synthetic', 'request_id' => 'retry-original-1'];
        $this->domain->recordEffects = true;
        $first = $this->personal()->postJson($this->base.'/pitch-planning/requests', $body)->assertOk()->json();
        $this->domain->result = ['key' => 'request:99', 'revision' => 'new-result'];
        $this->postJson($this->base.'/pitch-planning/requests', array_reverse($body, true))
            ->assertOk()->assertExactJson($first)->assertHeader('Idempotency-Replayed', 'true');
        $this->assertCount(1, $this->domain->calls);
        $this->assertSame(1, $this->domain->replayChecks);
        $this->assertSame(2, DB::connection('clubunio')->table('clubunio.pitch_test_effects')->count());
        $row = DB::connection('clubunio')->table('clubunio.pitch_request_idempotency')->first();
        $this->assertSame(hash('sha256', $body['request_id']), $row->key_hash);
        $this->assertStringNotContainsString($first['data']['key'], $row->response_encrypted);
        $this->postJson($this->base.'/pitch-planning/requests', array_replace($body, ['opponent' => 'Changed']))->assertConflict();
        $this->assertCount(1, $this->domain->calls);
    }

    public function test_friendly_keys_are_scoped_to_account_and_tenant_and_defaults_are_canonical(): void
    {
        $body = $this->placement() + ['team_season_id' => 9, 'opponent' => 'Synthetic', 'request_id' => 'scoped-request-1'];
        $this->personal()->postJson($this->base.'/pitch-planning/requests', $body)->assertOk();
        $minimal = $body;
        unset($minimal['area_id'], $minimal['duration_minutes']);
        $minimal['team_season_id'] = '9';
        $this->postJson($this->base.'/pitch-planning/requests', $minimal)->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->sessions->accountId = 18;
        $this->postJson($this->base.'/pitch-planning/requests', $body)->assertOk()->assertHeaderMissing('Idempotency-Replayed');
        $this->access->tenants = [1, 2];
        $this->postJson('/api/mobile/v1/clubs/2/pitch-planning/requests', $body)->assertOk()->assertHeaderMissing('Idempotency-Replayed');
        $this->assertCount(3, $this->domain->calls);
        $this->assertSame(3, DB::connection('clubunio')->table('clubunio.pitch_request_idempotency')->count());
    }

    public function test_friendly_replay_rechecks_current_rights_and_session(): void
    {
        $body = $this->placement() + ['team_season_id' => 9, 'opponent' => 'Synthetic', 'request_id' => 'revoked-request-1'];
        $this->personal()->postJson($this->base.'/pitch-planning/requests', $body)->assertOk();
        $this->domain->denyReplay = true;
        $this->postJson($this->base.'/pitch-planning/requests', $body)->assertForbidden();
        $this->domain->denyReplay = false;
        $this->sessions->revokeOnWrite = true;
        $this->postJson($this->base.'/pitch-planning/requests', $body)->assertUnauthorized();
        $this->assertCount(1, $this->domain->calls);
    }

    public function test_friendly_failed_transaction_rolls_back_effects_and_can_retry_same_key(): void
    {
        $body = $this->placement() + ['team_season_id' => 9, 'opponent' => 'Synthetic', 'request_id' => 'rollback-request-1'];
        $this->domain->recordEffects = true;
        $this->domain->failure = 503;
        $this->personal()->postJson($this->base.'/pitch-planning/requests', $body)->assertStatus(503);
        $db = DB::connection('clubunio');
        $this->assertSame(0, $db->table('clubunio.pitch_test_effects')->count());
        $this->assertSame(0, $db->table('clubunio.pitch_request_idempotency')->count());
        $this->domain->failure = null;
        $this->postJson($this->base.'/pitch-planning/requests', $body)->assertOk();
        $this->assertSame(2, $db->table('clubunio.pitch_test_effects')->count());
        $this->assertSame(1, $db->table('clubunio.pitch_request_idempotency')->count());
    }

    public function test_friendly_requires_valid_key_and_ledger_before_mutation(): void
    {
        $body = $this->placement() + ['team_season_id' => 9, 'opponent' => 'Synthetic'];
        foreach ([null, '', 'short', str_repeat('x', 121), 'invalid key', "valid\nkey", ['array']] as $id) {
            $this->personal()->postJson($this->base.'/pitch-planning/requests', $body + ['request_id' => $id])->assertUnprocessable();
        }
        $this->postJson($this->base.'/pitch-planning/requests', $body)->assertUnprocessable();
        DB::connection('clubunio')->statement('DROP TABLE clubunio.pitch_request_idempotency');
        $this->postJson($this->base.'/pitch-planning/requests', $body + ['request_id' => 'valid-key-1'])->assertStatus(503);
        $this->assertSame([], $this->domain->calls);
    }

    public function test_friendly_response_encoding_failure_rolls_back_request_and_notification(): void
    {
        $body = $this->placement() + ['team_season_id' => 9, 'opponent' => 'Synthetic', 'request_id' => 'encoding-failure-1'];
        $this->domain->recordEffects = true;
        $this->domain->result['title'] = "\xB1\x31";
        $this->personal()->postJson($this->base.'/pitch-planning/requests', $body)->assertStatus(500);
        $db = DB::connection('clubunio');
        $this->assertSame(0, $db->table('clubunio.pitch_test_effects')->count());
        $this->assertSame(0, $db->table('clubunio.pitch_request_idempotency')->count());
        $this->assertSame(0, $db->transactionLevel());
    }

    public function test_review_forwards_exact_decision_and_cannot_bypass_domain_denial(): void
    {
        $this->personal()->postJson($this->base.'/pitch-planning/requests/7/review', ['revision' => 'v3', 'decision' => 'approved'])->assertOk();
        $this->assertSame('decide', $this->domain->calls[0][0]);
        $this->assertSame(7, $this->domain->calls[0][1][2]);
        $this->domain->failure = 403;
        $this->postJson($this->base.'/pitch-planning/requests/7/review', ['revision' => 'v3', 'decision' => 'rejected'])->assertForbidden();
        $this->postJson($this->base.'/pitch-planning/requests/7/review', ['revision' => 'v3', 'decision' => 'pending'])->assertUnprocessable();
        $this->assertCount(2, $this->domain->calls);
    }

    public function test_review_accepts_only_explicit_boolean_status_override_and_preserves_domain_authority(): void
    {
        $payload = ['revision' => 'v3', 'decision' => 'approved', 'override_field_status' => true];
        $this->personal()->postJson($this->base.'/pitch-planning/requests/7/review', $payload)->assertOk();
        $this->assertSame($payload, $this->domain->calls[0][1][3]);
        $this->postJson($this->base.'/pitch-planning/requests/7/review', array_replace($payload, ['override_field_status' => 'yes']))->assertUnprocessable();
        $this->postJson($this->base.'/pitch-planning/requests/7/review', $payload + ['override_occupancy' => true])->assertUnprocessable();
        $this->assertCount(1, $this->domain->calls);
        $this->domain->failure = 403;
        $this->postJson($this->base.'/pitch-planning/requests/7/review', $payload)->assertForbidden();
        $schema = (new MobilePitchDocumentation)->specification()['components']['schemas'];
        $this->assertSame(['revision', 'decision'], $schema['Review']['required']);
        $this->assertSame('boolean', $schema['Review']['properties']['override_field_status']['type']);
        $this->assertFalse($schema['Review']['properties']['override_field_status']['default']);
        $this->assertSame('boolean', $schema['Capabilities']['properties']['override_field_status']['type']);
        $this->assertNotContains('override_field_status', $schema['Entry']['required']);
    }

    public function test_revocation_between_read_and_write_prevents_domain_mutation(): void
    {
        $this->sessions->revokeOnWrite = true;
        $this->personal()->postJson($this->base.'/pitch-planning/move', $this->placement() + ['key' => 'request:5', 'revision' => '1'])->assertUnauthorized();
        $this->assertSame([], $this->domain->calls);
        $this->assertSame(0, DB::connection('clubunio')->transactionLevel());
    }

    public function test_input_errors_are_bounded_and_do_not_call_domain(): void
    {
        $this->personal()->getJson($this->base.'/pitch-planning/options?tenant_id=2')->assertUnprocessable();
        $this->getJson($this->base.'/pitch-planning/calendar?from=2026-11-10&to=2026-11-04')->assertUnprocessable();
        foreach ([['starts_at' => '18:30Z'], ['date' => '2026-02-30'], ['duration_minutes' => 0], ['revision' => 3]] as $invalid) {
            $body = array_replace($this->placement() + ['key' => 'request:5', 'revision' => '1'], $invalid);
            $this->postJson($this->base.'/pitch-planning/move', $body)->assertUnprocessable();
        }
        $this->assertSame([], $this->domain->calls);
    }

    public function test_module_negotiation_keeps_pitch_facilities_and_profile_visibility_separate(): void
    {
        $headers = ['X-ClubUnio-Mobile-Features' => 'pitch-planning-v1,facilities-v1'];
        app(MemberAccessService::class)->allowed = true;
        $this->personal()->getJson($this->base.'/modules', $headers)->assertOk()
            ->assertJsonPath('data.modules.0.pitch_planning', true)->assertJsonPath('data.modules.0.facilities', true);
        $this->getJson($this->base.'/modules', ['X-ClubUnio-Mobile-Features' => ''])->assertOk()
            ->assertJsonPath('data.modules.0.available', false)->assertJsonPath('data.modules.0.pitch_planning', false)->assertJsonPath('data.modules.0.facilities', false);
        config(['clubunio-mobile.native_facilities_enabled' => false]);
        $this->getJson($this->base.'/modules', $headers)->assertOk()
            ->assertJsonPath('data.modules.0.pitch_planning', true)->assertJsonPath('data.modules.0.facilities', false);
        config(['clubunio-mobile.native_facilities_enabled' => true]);
        $this->projection->ready = false;
        $this->getJson($this->base.'/modules', $headers)->assertOk()
            ->assertJsonPath('data.modules.0.facilities', true)->assertJsonPath('data.modules.0.pitch_planning', false);
        app(MemberAccessService::class)->allowed = false;
        $this->getJson($this->base.'/modules', $headers)->assertOk()->assertJsonCount(1, 'data.modules')->assertJsonPath('data.modules.0.key', 'Profil');
        $this->permissions->view = true;
        $this->getJson($this->base.'/modules', $headers)->assertOk()->assertJsonPath('data.modules.0.facilities', true)->assertJsonPath('data.modules.0.pitch_planning', false);
        $this->projection->ready = true;
        $this->domain->visible = false;
        $this->getJson($this->base.'/modules', $headers)->assertOk()->assertJsonPath('data.modules.0.pitch_planning', false);
        $this->visibility->keys = [];
        $this->getJson($this->base.'/modules', $headers)->assertOk()->assertJsonCount(1, 'data.modules');
    }

    public function test_swagger_has_personal_security_exact_revision_schema_and_resolving_references(): void
    {
        $spec = (new MobilePitchDocumentation)->specification();
        $this->assertSame([['url' => '/api/mobile/v1']], $spec['servers']);
        $this->assertSame([['personalBearer' => []]], $spec['security']);
        $this->assertSame('string', $spec['components']['schemas']['Move']['properties']['revision']['type']);
        $this->assertFalse($spec['components']['schemas']['FriendlyRequest']['additionalProperties']);
        $this->assertArrayNotHasKey('status', $spec['components']['schemas']['FriendlyRequest']['properties']);
        $this->assertSame(120, $spec['components']['schemas']['FriendlyRequest']['properties']['duration_minutes']['default']);
        $walk = function ($node) use (&$walk, $spec): void {
            if (!is_array($node)) return;
            if (isset($node['$ref'])) $this->assertArrayHasKey(basename($node['$ref']), $spec['components']['schemas']);
            foreach ($node as $value) $walk($value);
        };
        $walk($spec);
    }

    public function test_personal_locale_translates_errors_and_is_restored_after_request(): void
    {
        app()->setLocale('de');
        $this->personal()->getJson($this->base.'/pitch-planning/calendar')->assertUnprocessable()
            ->assertJsonPath('error.message', 'Check your input.')
            ->assertJsonPath('error.fields.from.0', 'The start date field is required.');
        $this->assertSame('de', app()->getLocale());
        app(MobileLanguages::class)->language = 'de';
        $this->getJson($this->base.'/pitch-planning/calendar')->assertUnprocessable()
            ->assertJsonPath('error.message', 'Eingaben prüfen.');
        $this->assertSame('de', app()->getLocale());
    }

    public function test_missing_schema_error_from_domain_is_not_reported_as_empty_calendar(): void
    {
        $this->domain->failure = 503;
        $this->personal()->getJson($this->base.'/pitch-planning/calendar?from=2026-11-04&to=2026-11-04')
            ->assertStatus(503)->assertJsonMissingPath('data');
    }

    public function test_swagger_surface_does_not_skip_the_existing_admin_gate(): void
    {
        foreach (['show', 'specification'] as $method) {
            try {
                (new \App\ClubUnio\Http\ApiDocumentationController)->$method(Request::create('/api-docs?surface=pitch-planning'));
                $this->fail('Unauthenticated documentation access was accepted.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
                $this->assertSame(401, $error->getStatusCode());
            }
        }
    }

    public function test_real_core_and_controller_types_resolve_and_match_called_signatures(): void
    {
        $this->app->forgetInstance(PitchPlanningService::class);
        $this->app->forgetInstance(PitchPlanningProjection::class);
        $this->assertInstanceOf(PitchPlanningService::class, app(PitchPlanningService::class));
        foreach ([MobilePitchController::class, MobilePitchReady::class, MobilePitchDocumentation::class,
            \App\ClubUnio\Mobile\MobilePitchRoutes::class, \App\ClubUnio\Services\PitchFriendlyRequestService::class, PitchPlanningProjection::class] as $class) {
            $this->assertInstanceOf($class, app($class));
        }
        foreach (['canView' => 2, 'options' => 2, 'calendar' => 3, 'requestFriendly' => 3, 'authorizeFriendlyRequest' => 3, 'move' => 4, 'decide' => 4, 'configureCompetition' => 4] as $method => $count) {
            $reflection = new \ReflectionMethod(PitchPlanningService::class, $method);
            $this->assertTrue($reflection->isPublic());
            $this->assertSame($count, $reflection->getNumberOfRequiredParameters());
            foreach ($reflection->getParameters() as $parameter) {
                $type = $parameter->getType();
                if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) $this->assertTrue(class_exists($type->getName()));
            }
        }
    }

    public function test_real_core_fails_closed_when_pitch_schema_is_absent(): void
    {
        $this->app->forgetInstance(PitchPlanningService::class);
        $this->app->forgetInstance(PitchPlanningProjection::class);
        $this->permissions->view = true;
        $this->personal()->getJson($this->base.'/pitch-planning/calendar?from=2026-11-04&to=2026-11-04')
            ->assertStatus(503)->assertJsonMissingPath('data');
    }

    public function test_real_core_rejects_trainer_tl_and_profile_review_even_with_module_permission(): void
    {
        $db = DB::connection('clubunio');
        $db->statement('CREATE TABLE clubunio.accounts (id INTEGER PRIMARY KEY, active INTEGER)');
        $db->statement('CREATE TABLE clubunio.tenant_accounts (id INTEGER PRIMARY KEY, tenant_id INTEGER, account_id INTEGER, role TEXT)');
        $db->table('clubunio.accounts')->insert(['id' => 17, 'active' => 1]);
        $db->table('clubunio.tenant_accounts')->insert(['id' => 1, 'tenant_id' => 1, 'account_id' => 17, 'role' => 'trainer']);
        $projection = \Mockery::mock(PitchPlanningProjection::class)->makePartial();
        $projection->shouldReceive('ready')->andReturn(true);
        $this->app->instance(PitchPlanningProjection::class, $projection);
        $this->app->forgetInstance(PitchPlanningService::class);
        $this->permissions->view = true;
        foreach (['trainer', 'technical_lead', 'profile'] as $role) {
            $db->table('clubunio.tenant_accounts')->where('id', 1)->update(['role' => $role]);
            $this->personal()->postJson($this->base.'/pitch-planning/requests/7/review', ['revision' => '1', 'decision' => 'approved'])
                ->assertForbidden()->assertJsonPath('error.code', 'forbidden');
            $this->postJson($this->base.'/pitch-planning/requests/7/review', ['revision' => '1', 'decision' => 'approved', 'override_field_status' => true])
                ->assertForbidden();
            $this->postJson($this->base.'/pitch-planning/competitions/11/teams', ['revision' => '1', 'team_season_ids' => [9]])
                ->assertForbidden();
            $this->assertSame(0, $db->transactionLevel());
        }
        $this->app->instance(\App\ClubUnio\Services\MemberAccessService::class, new class {
            public function canEnter($actor, $tenant): bool { return true; }
        });
        $this->permissions->view = false;
        foreach (['admin', 'office'] as $role) {
            $db->table('clubunio.tenant_accounts')->where('id', 1)->update(['role' => $role]);
            $this->postJson($this->base.'/pitch-planning/requests/7/review', ['revision' => '1', 'decision' => 'approved'])
                ->assertForbidden();
            $this->postJson($this->base.'/pitch-planning/competitions/11/teams', ['revision' => '1', 'team_season_ids' => []])
                ->assertForbidden();
        }
    }

    public function test_transport_limits_match_core_notes_and_allow_multi_day_block_moves(): void
    {
        $this->personal()->postJson($this->base.'/pitch-planning/requests', $this->placement() + [
            'team_season_id' => 9, 'opponent' => 'Synthetic', 'notes' => str_repeat('x', 2001),
        ])->assertUnprocessable();
        $this->assertSame([], $this->domain->calls);
        $this->postJson($this->base.'/pitch-planning/move', array_replace($this->placement(), [
            'key' => 'block:7', 'revision' => '1', 'duration_minutes' => 2880,
        ]))->assertOk();
        $this->assertSame(2880, $this->domain->calls[0][1][3]['duration_minutes']);
    }

    public function test_competition_mapping_forwards_revision_and_explicit_empty_list(): void
    {
        foreach ([[9, 10], []] as $ids) {
            $this->personal()->postJson($this->base.'/pitch-planning/competitions/11/teams', [
                'revision' => '12:3', 'team_season_ids' => $ids,
            ])->assertOk()->assertExactJson(['data' => $this->domain->result]);
            [$method, $args] = $this->domain->calls[array_key_last($this->domain->calls)];
            $this->assertSame('configureCompetition', $method);
            $this->assertSame(11, $args[2]);
            $this->assertSame(['revision' => '12:3', 'team_season_ids' => $ids], $args[3]);
        }
        $this->assertSame(4, $this->sessions->calls);
        foreach ([404, 409] as $status) {
            $this->domain->failure = $status;
            $this->postJson($this->base.'/pitch-planning/competitions/11/teams', ['revision' => '12:3', 'team_season_ids' => [9]])
                ->assertStatus($status);
        }
    }

    public function test_competition_mapping_rejects_invalid_lists_and_authority_fields(): void
    {
        $url = $this->base.'/pitch-planning/competitions/11/teams';
        $this->personal()->postJson($url, ['revision' => '1'])->assertUnprocessable();
        foreach ([null, '9', [0], [9, '9'], range(1, 21), ['named' => 9]] as $ids) {
            $this->postJson($url, ['revision' => '1', 'team_season_ids' => $ids])->assertUnprocessable();
        }
        foreach (['tenant_id' => 2, 'sfv_authoritative' => true, 'team_season_ids.*' => 9] as $key => $value) {
            $this->postJson($url, ['revision' => '1', 'team_season_ids' => [], $key => $value])->assertUnprocessable();
        }
        $this->assertSame([], $this->domain->calls);
        $schema = (new MobilePitchDocumentation)->specification()['components']['schemas']['CompetitionTeams'];
        $this->assertSame(['revision', 'team_season_ids'], $schema['required']);
        $this->assertSame(20, $schema['properties']['team_season_ids']['maxItems']);
        $this->assertTrue($schema['properties']['team_season_ids']['uniqueItems']);
    }
}
