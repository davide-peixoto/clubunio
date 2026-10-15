<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\TrainingPlanAi;
use App\ClubUnio\Services\TrainingPlanContent;
use App\ClubUnio\Services\TrainingPlanService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\WorkspaceForm;
use Tests\TestCase;

class TrainingPlanTest extends TestCase
{
    use WorkspaceForm;

    private $db;

    private Tenant $tenant;

    private Account $actor;

    private Account $trainer;

    private int $team;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Opt-in isolated transaction');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->db->statement('SET LOCAL search_path TO clubora');
        $this->tenant = Tenant::create(['slug' => 'training-'.Str::random(12), 'name' => 'Training fixture']);
        $this->actor = $this->account('admin');
        $this->trainer = $this->account('trainer');
        $member = $this->db->table('members')->insertGetId(['tenant_id' => $this->tenant->id, 'first_name' => 'Coach', 'last_name' => 'Fixture']);
        $this->db->table('account_member_links')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $this->trainer->id, 'member_id' => $member, 'kind' => 'self', 'state' => 'approved', 'source' => 'test']);
        $season = $this->db->table('seasons')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => 'Fixture']);
        $team = $this->db->table('teams')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => 'Fixture']);
        $this->team = $this->db->table('team_seasons')->insertGetId(['tenant_id' => $this->tenant->id, 'team_id' => $team, 'season_id' => $season, 'label' => 'Fixture team']);
        $this->db->table('team_staff')->insert(['tenant_id' => $this->tenant->id, 'team_season_id' => $this->team, 'member_id' => $member]);
        $this->actingAs($this->actor, 'clubunio');
        Http::preventStrayRequests();
    }

    private function account(string $role): Account
    {
        $account = new Account;
        $account->forceFill(['username' => Str::random(20).'@example.invalid', 'display_name' => 'Test', 'password' => bcrypt('test'), 'active' => true])->save();
        $this->db->table('tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $account->id, 'role' => $role, 'portal_enabled' => true]);

        return $account;
    }

    private function plan(): array
    {
        return ['title' => 'Testtraining', 'focus' => 'Passen', 'principles' => ['SV Sissach: Spielfreude', 'SFV: Viele Ballkontakte'],
            'qualityChecks' => ['SV Sissach und SFV berücksichtigt'], 'exercises' => array_map(fn ($phase) => [
                'phase' => $phase, 'title' => 'Passspiel', 'durationMinutes' => 20, 'setup' => 'Zwei Gruppen', 'flow' => 'Passen und freilaufen',
                'coachingPoints' => ['Ballkontrolle'], 'easier' => 'Mehr Platz', 'harder' => 'Weniger Zeit', 'diagramDescription' => 'Zwei Zonen auf halbem Spielfeld',
                'markers' => [['kind' => 'player', 'x' => 25, 'y' => 50], ['kind' => 'cone', 'x' => 75, 'y' => 50]],
            ], ['Einstieg', 'Hauptteil', 'Abschluss'])];
    }

    private function input(): array
    {
        return ['team_season_id' => $this->team, 'durationMinutes' => 60, 'playerCount' => 18, 'goal' => 'Passen', 'fieldSize' => 'Halbfeld', 'material' => 'Bälle'];
    }

    private function insert(): int
    {
        return $this->db->table('training_plans')->insertGetId(['tenant_id' => $this->tenant->id, 'owner_id' => $this->actor->id, 'team_season_id' => $this->team,
            'title' => 'Testtraining', 'input_json' => json_encode($this->input() + ['teamName' => 'Fixture']), 'plan_json' => json_encode($this->plan())]);
    }

    public function test_web_edit_pdf_and_revision_conflict(): void
    {
        $context = $this->workspaceForm();
        $id = $this->insert();
        $url = '/planning/training-plans';
        $this->get($url)->assertOk()->assertSee('Testtraining');
        $this->get($url.'/new')->assertOk()->assertSee('Schwerpunkt');
        $this->get($url.'/'.$id)->assertOk()->assertSee('Trainingsplan bearbeiten');
        $pdf = $this->get($url.'/'.$id.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        if ($path = getenv('CLUBUNIO_TRAINING_PDF_TEST_OUTPUT')) {
            file_put_contents($path, $pdf->getContent());
        }
        $plan = $this->plan();
        $plan['title'] = 'Geändert';
        $this->post($url.'/'.$id, $context + ['revision' => 1, 'plan' => $plan, 'shared' => 1])->assertRedirect();
        $this->assertSame(2, $this->db->table('training_plans')->where('id', $id)->value('revision'));
        $this->post($url.'/'.$id, $context + ['revision' => 1, 'plan' => $plan])->assertSessionHasErrors('revision');
        $this->assertSame('Geändert', $this->db->table('training_plans')->where('id', $id)->value('title'));
    }

    public function test_team_sharing_is_read_only_and_tenant_isolation(): void
    {
        $id = $this->insert();
        $url = '/planning/'.$this->tenant->slug.'/training-plans/'.$id;
        $this->actingAs($this->trainer, 'clubunio');
        $this->get($url)->assertNotFound();
        $this->db->table('training_plans')->where('id', $id)->update(['shared' => true]);
        $this->get($url)->assertOk()->assertDontSee('Trainingsplan bearbeiten');
        $this->post($url, ['revision' => 1, 'plan' => $this->plan()])->assertForbidden();
        $this->actingAs($this->account('member'), 'clubunio');
        $this->get($url)->assertForbidden();
        $other = Tenant::create(['slug' => 'other-'.Str::random(12), 'name' => 'Other']);
        $this->actingAs($this->actor, 'clubunio');
        $this->get('/planning/'.$other->slug.'/training-plans/'.$id)->assertForbidden();
    }

    public function test_archive_restore_and_private_copy_are_revision_safe_and_without_provider(): void
    {
        $context = $this->workspaceForm();
        $id = $this->insert();
        $url = '/planning/training-plans';
        $this->post($url.'/'.$id.'/archive', $context + ['revision' => 1, 'archived' => 1])->assertRedirect();
        $this->get($url)->assertDontSee('Testtraining');
        $this->get($url.'?archived=1')->assertSee('Testtraining');
        $this->get($url.'/'.$id)->assertOk()->assertDontSee('Trainingsplan bearbeiten');
        $this->post($url.'/'.$id, $context + ['revision' => 2, 'plan' => $this->plan()])->assertStatus(409);
        $this->post($url.'/'.$id.'/archive', $context + ['revision' => 1, 'archived' => 0])->assertSessionHasErrors('revision');
        $this->post($url.'/'.$id.'/archive', $context + ['revision' => 2, 'archived' => 0])->assertRedirect();
        $this->db->table('training_plans')->where('id', $id)->update(['shared' => true]);
        $this->actingAs($this->trainer, 'clubunio');
        $this->post($url.'/'.$id.'/archive', ['revision' => 3, 'archived' => 1])->assertForbidden();
        $key = (string) Str::uuid();
        $service = app(TrainingPlanService::class);
        $copy = $service->copy($this->trainer, $this->tenant, $id, 3, $key);
        $this->assertSame($copy, $service->copy($this->trainer, $this->tenant, $id, 3, $key));
        $row = $service->get($this->trainer, $this->tenant, $copy);
        $this->assertFalse($row->shared);
        $this->assertSame($this->trainer->id, $row->owner_id);
        $this->assertSame(1, $row->revision);
        $this->assertSame(2, $this->db->table('training_plans')->where('tenant_id', $this->tenant->id)->count());
        Http::assertNothingSent();
    }

    public function test_disabled_account_cannot_use_cached_training_identity(): void
    {
        $id = $this->insert();
        Account::whereKey($this->actor->id)->update(['active' => false]);
        $this->expectException(HttpException::class);
        app(TrainingPlanService::class)->get($this->actor, $this->tenant, $id);
    }

    public function test_copies_and_ai_have_independent_rate_limits(): void
    {
        $id = $this->insert();
        $service = app(TrainingPlanService::class);
        $copy = $service->copy($this->actor, $this->tenant, $id, 1, (string) Str::uuid());
        $this->mock(TrainingPlanAi::class)->shouldReceive('generate')->once()->andReturn($this->plan());
        $generated = $service->generate($this->actor, $this->tenant, $this->input(), (string) Str::uuid());
        $this->assertNotSame($copy, $generated);
        $this->assertNotSame($copy, $service->copy($this->actor, $this->tenant, $id, 1, (string) Str::uuid()));
        Http::assertNothingSent();
    }

    public function test_generation_is_idempotent_rate_limited_and_excludes_internal_ids(): void
    {
        $this->mock(TrainingPlanAi::class)->shouldReceive('generate')->once()->withArgs(function ($tenant, $input, $draft) {
            return $tenant->id === $this->tenant->id && ! isset($input['team_season_id']) && $draft === null;
        })->andReturn($this->plan());
        $service = app(TrainingPlanService::class);
        $key = (string) Str::uuid();
        $id = $service->generate($this->actor, $this->tenant, $this->input(), $key);
        $this->assertSame($id, $service->generate($this->actor, $this->tenant, $this->input(), $key));
        $this->assertSame(1, $this->db->table('training_plans')->where('tenant_id', $this->tenant->id)->count());
        $this->expectException(ValidationException::class);
        $service->generate($this->actor, $this->tenant, $this->input(), (string) Str::uuid());
    }

    public function test_review_needs_confirmation_and_cannot_overwrite_changed_revision(): void
    {
        $context = $this->workspaceForm();
        $id = $this->insert();
        $plan = $this->plan();
        $plan['title'] = 'KI Vorschlag';
        $this->mock(TrainingPlanAi::class)->shouldReceive('generate')->once()->andReturn($plan);
        $url = '/planning/training-plans/'.$id;
        $this->post($url.'/review', $context + ['revision' => 1, 'request_key' => (string) Str::uuid()])->assertRedirect();
        $this->assertSame('Testtraining', $this->db->table('training_plans')->where('id', $id)->value('title'));
        $this->get($url)->assertOk()->assertSee('Prüfvorschlag')->assertSee('KI Vorschlag');
        $this->post($url.'/apply', $context)->assertRedirect();
        $this->assertSame('KI Vorschlag', $this->db->table('training_plans')->where('id', $id)->value('title'));
        $this->post($url.'/apply', $context)->assertStatus(409);
        $this->withSession(['training_review.'.$this->tenant->id.'.'.$id => ['actor' => $this->actor->id, 'expires' => time() + 100, 'revision' => 1, 'plan' => $plan]])
            ->post($url.'/apply', $context)->assertSessionHasErrors('revision');
    }

    public function test_content_rejects_wrong_duration_and_drops_executable_content(): void
    {
        $plan = $this->plan();
        $plan['exercises'][0]['svg'] = '<svg onload="alert(1)"></svg>';
        $safe = app(TrainingPlanContent::class)->validate($plan, 60);
        $this->assertArrayNotHasKey('svg', $safe['exercises'][0]);
        $this->expectException(ValidationException::class);
        app(TrainingPlanContent::class)->validate($plan, 90);
    }

    public function test_openai_uses_file_search_strict_schema_and_no_storage(): void
    {
        foreach (['OPENAI_API_KEY' => 'fixture-key', 'OPENAI_TRAINING_VECTOR_STORE_ID' => 'vs_fixture', 'OPENAI_TRAINING_MODEL' => 'fixture-model'] as $key => $value) {
            $this->db->table('tenant_settings')->insert(['tenant_id' => $this->tenant->id, 'area' => 'openai', 'key' => $key, 'label' => $key, 'encrypted_value' => Crypt::encryptString($value), 'value_type' => 'string', 'is_secret' => true]);
        }
        Http::fake(['https://api.openai.com/v1/responses' => Http::response(['status' => 'completed', 'output' => [
            ['type' => 'file_search_call', 'status' => 'completed'],
            ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($this->plan())]]],
        ]])]);
        $result = app(TrainingPlanAi::class)->generate($this->tenant, $this->input());
        $this->assertSame('Testtraining', $result['title']);
        Http::assertSent(fn ($r) => $r['store'] === false && $r['tool_choice'] === 'required' && $r['text']['format']['strict'] === true && $r['model'] === 'fixture-model');
    }

    public function test_provider_failure_keeps_reference_without_creating_plan(): void
    {
        $this->mock(TrainingPlanAi::class)->shouldReceive('generate')->once()->andThrow(new \RuntimeException('Secret response body', 429));
        try {
            app(TrainingPlanService::class)->generate($this->actor, $this->tenant, $this->input(), (string) Str::uuid());
            $this->fail('Expected validation error');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Referenz:', $e->getMessage());
            $this->assertStringNotContainsString('Secret', $e->getMessage());
        }
        $this->assertSame(0, $this->db->table('training_plans')->where('tenant_id', $this->tenant->id)->count());
        $row = $this->db->table('training_plan_requests')->where('tenant_id', $this->tenant->id)->first();
        $this->assertSame('failed', $row->state);
        $this->assertSame(429, $row->provider_status);
    }

    protected function tearDown(): void
    {
        if ($this->db && $this->db->transactionLevel()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }
}
