<?php

namespace Tests\Feature;

use App\ClubUnio\Mobile\MobileTrainingWork;
use App\ClubUnio\Models\{Account, Tenant};
use Illuminate\Support\Facades\{Crypt, DB, Http, Mail};
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileTrainingTest extends TestCase
{
    private $db;
    private Tenant $tenant;
    private Account $owner;
    private Account $coach;
    private Account $outsider;
    private int $team;
    private int $target;
    private int $planId;
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') { $this->markTestSkipped('Synthetic rollback fixtures only'); }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName()); $this->db->beginTransaction();
        $this->db->statement("SET LOCAL application_name = 'clubunio-mobile-training-test'");
        config(['clubunio-mobile.enabled' => true, 'clubunio-mobile.native_training_enabled' => true,
            'clubunio-mobile.training_ai.dispatch_after_response' => false, 'cache.default' => 'array', 'hashing.bcrypt.rounds' => 4]);
        Http::preventStrayRequests(); Mail::fake();
        $this->tenant = Tenant::create(['slug' => 'qa-native-training-'.bin2hex(random_bytes(5)), 'name' => 'Synthetic training']);
        $this->owner = $this->account('admin'); $this->coach = $this->account('trainer'); $this->outsider = $this->account('member');
        $outsider = $this->insert('members', ['first_name' => 'Outside', 'last_name' => 'Member']);
        $this->insert('account_member_links', ['account_id' => $this->outsider->id, 'member_id' => $outsider, 'kind' => 'self', 'state' => 'approved', 'source' => 'test']);
        $season = $this->insert('seasons', ['name' => 'Selected', 'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->addMonth()->toDateString()]);
        $this->insert('tenant_settings', ['area' => 'general', 'key' => 'SaisonID', 'label' => 'Season', 'value_type' => 'string',
            'encrypted_value' => Crypt::encryptString((string) $season), 'enabled' => true, 'is_secret' => false]);
        $team = $this->insert('teams', ['name' => 'Synthetic team']);
        $this->team = $this->insert('team_seasons', ['team_id' => $team, 'season_id' => $season, 'label' => 'Original team']);
        $this->target = $this->insert('team_seasons', ['team_id' => $team, 'season_id' => $season, 'label' => 'Selected trainer team']);
        $member = $this->insert('members', ['first_name' => 'Synthetic', 'last_name' => 'Coach']);
        $this->insert('account_member_links', ['account_id' => $this->coach->id, 'member_id' => $member, 'kind' => 'self', 'state' => 'approved', 'source' => 'test']);
        $this->insert('team_staff', ['team_season_id' => $this->target, 'member_id' => $member]);
        $this->planId = $this->insert('training_plans', ['owner_id' => $this->owner->id, 'team_season_id' => $this->team, 'title' => 'Synthetic training',
            'input_json' => json_encode($this->input() + ['teamName' => 'Original team']), 'plan_json' => json_encode($this->plan())]);
        $this->temporarySchema();
    }
    private function temporarySchema(): void
    {
        $this->db->statement('CREATE TEMP TABLE training_fixture_tenants(id bigint PRIMARY KEY) ON COMMIT DROP');
        $this->db->table('pg_temp.training_fixture_tenants')->insert(['id' => $this->tenant->id]);
        foreach (['training_plans', 'team_seasons'] as $table) {
            $this->db->statement('CREATE TEMP TABLE training_fixture_'.$table.'(tenant_id bigint NOT NULL,id bigint NOT NULL,UNIQUE(tenant_id,id)) ON COMMIT DROP');
            $this->db->statement('INSERT INTO pg_temp.training_fixture_'.$table.' SELECT tenant_id,id FROM clubora.'.$table.' WHERE tenant_id=?', [$this->tenant->id]);
        }
        $this->db->statement('CREATE TEMP TABLE training_fixture_tenant_accounts(tenant_id bigint NOT NULL,account_id bigint NOT NULL,UNIQUE(tenant_id,account_id)) ON COMMIT DROP');
        $this->db->statement('INSERT INTO pg_temp.training_fixture_tenant_accounts SELECT tenant_id,account_id FROM clubora.tenant_accounts WHERE tenant_id=?', [$this->tenant->id]);
        $ddl = $this->db->pretend(fn () => (require database_path('clubunio/2026_11_03_000100_mobile_training_shares.php'))->up());
        $this->assertCount(1, $ddl);
        $sql = str_replace('CREATE TABLE clubora.mobile_training_plan_shares', 'CREATE TEMP TABLE mobile_training_plan_shares', $ddl[0]['query']);
        $sql = str_replace(['clubora.mobile_training_plan_shares', ");\nCREATE INDEX"], ['pg_temp.mobile_training_plan_shares', ") ON COMMIT DROP;\nCREATE INDEX"], $sql);
        foreach (['tenants','training_plans','team_seasons','tenant_accounts'] as $table) { $sql = str_replace('REFERENCES clubora.'.$table.'(', 'REFERENCES pg_temp.training_fixture_'.$table.'(', $sql); }
        $this->assertStringNotContainsString('REFERENCES clubora.', $sql);
        $this->assertStringNotContainsString('ON clubora.', $sql);
        $this->db->unprepared($sql);
        $this->db->setQueryGrammar(new class($this->db) extends \Illuminate\Database\Query\Grammars\PostgresGrammar {
            public function wrapTable($table, $prefix = null) {
                if (is_string($table)) { $table = str_replace('clubora.mobile_training_plan_shares', 'pg_temp.mobile_training_plan_shares', $table); }
                return parent::wrapTable($table, $prefix);
            }
        });
    }
    protected function tearDown(): void { if (isset($this->db) && $this->db->transactionLevel()) { $this->db->rollBack(); } parent::tearDown(); }
    private function insert(string $table, array $row): int { return $this->db->table('clubora.'.$table)->insertGetId(['tenant_id' => $this->tenant->id] + $row); }
    private function account(string $role): Account {
        $actor = new Account; $actor->forceFill(['username' => 'qa-training-'.bin2hex(random_bytes(5)), 'display_name' => 'Synthetic '.$role,
            'password' => bcrypt('synthetic-password'), 'active' => true, 'api_only' => false])->save();
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $actor->id, 'role' => $role, 'portal_enabled' => true]); return $actor;
    }
    private function token(Account $actor): string { return $this->postJson('/api/mobile/v1/session', ['username' => $actor->username, 'password' => 'synthetic-password', 'device_name' => 'Synthetic training'])->assertOk()->json('data.token'); }
    private function base(): string { return '/api/mobile/v1/clubs/'.$this->tenant->id.'/training-plans'; }
    private function plan(): array {
        return ['title' => 'Synthetic training', 'focus' => 'Passen', 'principles' => ['SV Sissach: Spielfreude', 'SFV: Ballkontakte'], 'qualityChecks' => ['SV Sissach und SFV berücksichtigt'],
            'exercises' => array_map(fn ($phase) => ['phase' => $phase, 'title' => 'Passspiel', 'durationMinutes' => 20, 'setup' => 'Zwei Gruppen', 'flow' => 'Passen und freilaufen',
                'coachingPoints' => ['Ballkontrolle'], 'easier' => 'Mehr Platz', 'harder' => 'Weniger Zeit', 'diagramDescription' => 'Zwei Zonen',
                'markers' => [['kind' => 'player', 'x' => 25, 'y' => 50]]], ['Einstieg','Hauptteil','Abschluss'])];
    }
    private function input(): array { return ['team_season_id' => $this->team, 'durationMinutes' => 60, 'playerCount' => 18, 'goal' => 'Passen', 'fieldSize' => 'Halbfeld', 'material' => 'Bälle', 'level' => 'Mittel', 'intensity' => 'Normal', 'constraints' => 'Keine']; }
    private function draft(): array { return ['title' => 'Edited draft', 'focus' => 'Passen', 'playerCount' => 16, 'exercises' => array_map(fn ($e) => array_intersect_key($e, array_flip(['title','durationMinutes','setup','flow'])), $this->plan()['exercises'])]; }
    private function ai(): void {
        config(['clubunio-mobile.training_ai.enabled' => true, 'clubunio-mobile.training_ai.cost_reviewed' => true, 'clubunio-mobile.training_ai.tenant_slugs' => [$this->tenant->slug],
            'clubunio-mobile.training_ai.models' => ['fixture-model'], 'clubunio-mobile.training_ai.reservation_microusd' => 100000,
            'clubunio-mobile.training_ai.monthly_limit_microusd' => 1000000, 'clubunio-mobile.training_ai.max_output_tokens' => 8000, 'clubunio-mobile.training_ai.max_input_bytes' => 65536]);
        foreach (['OPENAI_API_KEY' => 'fake-key','OPENAI_TRAINING_MODEL' => 'fixture-model','OPENAI_TRAINING_VECTOR_STORE_ID' => 'fixture-vector'] as $key => $value) {
            $this->insert('tenant_settings', ['area' => 'training', 'key' => $key, 'label' => 'Synthetic config', 'value_type' => 'string', 'encrypted_value' => Crypt::encryptString($value), 'enabled' => true, 'is_secret' => true]);
        }
        Http::fake(['https://api.openai.com/v1/responses' => Http::response(['status' => 'completed', 'output' => [
            ['type' => 'file_search_call','status' => 'completed'], ['content' => [['type' => 'output_text','text' => json_encode($this->plan())]]]]])]);
    }
    public function test_personal_share_collaborative_edit_revoke_versions_and_private_pdf(): void
    {
        $url = $this->base().'/'.$this->planId;
        $owner = $this->token($this->owner); $coach = $this->token($this->coach);
        $this->withToken($coach)->getJson($url)->assertNotFound();
        $this->withToken($owner)->getJson($this->base().'/options')->assertOk()->assertJsonCount(2, 'data.teams')->assertJsonPath('data.canGenerate', false);
        $this->postJson($url.'/share', ['revision' => 1, 'teamSaisonId' => $this->target])->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.sharedTeamSaisonId', $this->target);
        $this->withToken($coach)->getJson($url)->assertOk()->assertJsonPath('data.canEdit', true)->assertJsonPath('data.canShare', false)->assertJsonPath('data.canReview', false);
        $this->postJson($url.'/share', ['revision' => 2, 'teamSaisonId' => null])->assertForbidden();
        $invalid = $this->draft(); $invalid['exercises'] = array_combine(['a','b','c'], $invalid['exercises']);
        $this->patchJson($url, ['revision' => 2] + $invalid)->assertUnprocessable();
        $this->patchJson($url, ['revision' => 2] + $this->draft())->assertOk()->assertJsonPath('data.revision', 3)->assertJsonPath('data.playerCount', 16);
        $this->patchJson($url, ['revision' => 2] + $this->draft())->assertConflict();
        $this->get($url.'/pdf?revision=2', ['Accept' => 'application/pdf'])->assertConflict();
        $pdf = $this->get($url.'/pdf?revision=3', ['Accept' => 'application/pdf'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent()); $this->assertStringContainsString('no-store', $pdf->headers->get('Cache-Control'));
        $this->withToken($owner)->postJson($url.'/share', ['revision' => 3, 'teamSaisonId' => null])->assertOk()->assertJsonPath('data.revision', 4);
        $this->withToken($coach)->getJson($url)->assertNotFound();
        $this->patchJson($url, ['revision' => 3] + $this->draft())->assertNotFound();
        $this->withToken($this->token($this->outsider))->getJson($this->base())->assertForbidden();
        $this->withToken($owner)->getJson($this->base())->assertOk()->assertJsonCount(1, 'data.trainingPlans');
        config(['clubunio-mobile.native_training_enabled' => false]); $this->getJson($url)->assertStatus(503);
        Http::assertNothingSent(); Mail::assertNothingSent();
    }
    public function test_ai_review_is_durable_at_most_once_draft_preserving_and_explicitly_applied(): void
    {
        $this->ai(); $this->withToken($this->token($this->owner));
        $url = $this->base().'/'.$this->planId; $key = (string) Str::uuid();
        $input = ['revision' => 1,'request_key' => $key,'consent' => true] + $this->draft();
        $work = $this->postJson($url.'/review', $input)->assertStatus(202)->assertJsonPath('data.state', 'queued')->json('data.id');
        $this->assertSame('Synthetic training', $this->db->table('clubora.training_plans')->where('id', $this->planId)->value('title'));
        app(MobileTrainingWork::class)->execute($work); app(MobileTrainingWork::class)->execute($work);
        $this->getJson($this->base().'/work/'.$work)->assertOk()->assertJsonPath('data.state', 'succeeded')->assertJsonPath('data.plan.playerCount', 16);
        $this->postJson($url.'/review', $input)->assertStatus(202)->assertJsonPath('data.id', $work);
        $this->postJson($url.'/review', array_replace($input,['request_key' => (string) Str::uuid()]))->assertStatus(202)->assertJsonPath('data.id', $work);
        $this->postJson($url.'/review', array_replace($input,['title' => 'Different request']))->assertConflict();
        Http::assertSentCount(1);
        Http::assertSent(function ($request) { $body = $request->data(); return $body['max_output_tokens'] === 8000 && $body['max_tool_calls'] === 1 && $body['store'] === false
            && str_contains($body['input'][1]['content'], 'Edited draft') && str_contains($body['input'][1]['content'], '"playerCount":16'); });
        $this->assertSame(1, $this->db->table('clubora.training_plans')->where('id', $this->planId)->value('revision'));
        $this->postJson($url.'/review/apply', ['revision' => 1,'proposal' => $work])->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.playerCount', 16);
        $this->postJson($url.'/review/apply', ['revision' => 1,'proposal' => $work])->assertConflict();
        $this->assertSame(100000, $this->db->table('clubora.mobile_ai_work')->where('id', $work)->value('reserved_microusd'));
    }
    public function test_revoked_session_closes_queued_work_and_missing_configuration_never_calls_provider(): void
    {
        $this->withToken($this->token($this->owner));
        $payload = ['request_key' => (string) Str::uuid(), 'consent' => true] + $this->input();
        $this->postJson($this->base().'/generate', $payload)->assertStatus(503);
        $this->ai();
        $work = $this->postJson($this->base().'/generate', $payload)->assertStatus(202)->json('data.id');
        $this->db->table('clubora.mobile_sessions')->where('account_id', $this->owner->id)->update(['revoked_at' => now()]);
        app(MobileTrainingWork::class)->execute($work);
        $this->assertSame('rejected', $this->db->table('clubora.mobile_ai_work')->where('id', $work)->value('state'));
        app(MobileTrainingWork::class)->execute($work); Http::assertNothingSent();
        $this->assertSame(1, $this->db->table('clubora.training_plans')->where('tenant_id', $this->tenant->id)->count());
        $profile = $this->insert('access_profiles', ['name' => 'View and approve only', 'active' => true, 'permissions' => json_encode(['training.view','training.approve'])]);
        // Same opt-in catalogue row as the existing profile regression suites;
        // uncommitted and rolled back, never visible to other connections.
        $this->db->table('clubora.account_roles')->where('code', 'profile')->update(['active' => true]);
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)->where('account_id', $this->owner->id)->update(['role' => 'profile','access_profile_id' => $profile,'portal_enabled' => false]);
        $row = app(\App\ClubUnio\Mobile\MobileTrainingPlanService::class)->get($this->owner, $this->tenant, $this->planId);
        $capabilities = app(\App\ClubUnio\Mobile\MobileTrainingData::class)->plan($this->owner, $this->tenant, $row);
        $this->assertFalse($capabilities['canEdit']); $this->assertFalse($capabilities['canReview']);
        // Existing personal-login policy keeps backoffice profiles closed entirely.
        $this->postJson('/api/mobile/v1/session', ['username' => $this->owner->username, 'password' => 'synthetic-password', 'device_name' => 'Closed profile'])->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_generation_is_private_caches_identical_drafts_and_counts_other_ai_reservations(): void
    {
        $this->ai(); $this->withToken($this->token($this->owner));
        $payload = ['request_key' => (string) Str::uuid(), 'consent' => true] + $this->input();
        $work = $this->postJson($this->base().'/generate', $payload)->assertStatus(202)->json('data.id');
        app(MobileTrainingWork::class)->execute($work);
        $id = $this->getJson($this->base().'/work/'.$work)->assertOk()->assertJsonPath('data.state', 'succeeded')
            ->assertJsonPath('data.plan.revision', 1)->assertJsonPath('data.plan.sharedTeamSaisonId', null)->json('data.plan.id');
        $this->assertNotSame($this->planId, $id);
        $row = $this->db->table('clubora.training_plans')->where('id', $id)->first();
        $this->assertSame($this->owner->id, $row->owner_id); $this->assertFalse($row->shared);
        $this->assertSame('Mittel', json_decode($row->input_json, true)['level']);
        $this->postJson($this->base().'/generate', $payload)->assertStatus(202)->assertJsonPath('data.plan.id', $id);
        $this->postJson($this->base().'/generate', array_replace($payload, ['request_key' => (string) Str::uuid()]))->assertStatus(202)->assertJsonPath('data.plan.id', $id);
        $new = $this->postJson($this->base().'/generate', array_replace($payload, ['request_key' => (string) Str::uuid(), 'previous_work' => $work]))
            ->assertStatus(202)->assertJsonPath('data.state', 'queued')->json('data.id');
        $this->assertNotSame($work, $new);
        $this->db->table('clubora.mobile_ai_work')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'account_id' => $this->owner->id,
            'request_key' => (string) Str::uuid(), 'fingerprint' => str_repeat('a', 64), 'provider' => 'azure_document_intelligence',
            'operation' => 'expense_ocr', 'state' => 'attention', 'reserved_microusd' => 900000]);
        $this->postJson($this->base().'/generate', array_replace($payload, ['request_key' => (string) Str::uuid(), 'goal' => 'Different exercise']))->assertStatus(429);
        Http::assertSentCount(1);
    }

    public function test_revocation_during_provider_call_cannot_commit_a_new_plan_or_repeat_the_call(): void
    {
        $this->ai(); $this->withToken($this->token($this->owner));
        Http::fake(['https://api.openai.com/v1/responses' => function () {
            $this->db->table('clubora.mobile_sessions')->where('account_id', $this->owner->id)->update(['revoked_at' => now()]);
            return Http::response(['status' => 'completed', 'output' => [['type' => 'file_search_call','status' => 'completed'],
                ['content' => [['type' => 'output_text','text' => json_encode($this->plan())]]]]]);
        }]);
        $work = $this->postJson($this->base().'/generate', ['request_key' => (string) Str::uuid(), 'consent' => true] + $this->input())->assertStatus(202)->json('data.id');
        app(MobileTrainingWork::class)->execute($work); app(MobileTrainingWork::class)->execute($work);
        $this->assertSame('attention', $this->db->table('clubora.mobile_ai_work')->where('id', $work)->value('state'));
        $this->assertSame(1, $this->db->table('clubora.training_plans')->where('tenant_id', $this->tenant->id)->count());
        Http::assertSentCount(1);
    }
}
