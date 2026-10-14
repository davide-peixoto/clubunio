<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\LegacyTrainingPlan;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Resources\LegacyTrainingPlanResource\Pages\ManageRecords;
use App\ClubUnio\Services\LegacyTrainingPlanImporter;
use App\ClubUnio\Services\LegacyTrainingPlanSource;
use App\ClubUnio\Services\TrainingPlanService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LegacyTrainingMigrationTest extends TestCase
{
    private $db;

    private Tenant $tenant;

    private Account $actor;

    private int $team;

    private object $source;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Isolated transaction required');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->tenant = Tenant::create(['slug' => 'legacy-plan-'.Str::random(8), 'name' => 'Fixture']);
        $this->actor = new Account;
        $this->actor->forceFill(['username' => Str::random(10).'@example.invalid', 'display_name' => 'Test owner', 'active' => true, 'password' => bcrypt('test')])->save();
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $this->actor->id, 'role' => 'admin']);
        $season = $this->db->table('clubora.seasons')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => 'Fixture']);
        $team = $this->db->table('clubora.teams')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => 'Fixture']);
        $this->team = $this->db->table('clubora.team_seasons')->insertGetId(['tenant_id' => $this->tenant->id, 'team_id' => $team, 'season_id' => $season, 'label' => 'Fixture', 'legacy_id' => 345]);
        $input = ['durationMinutes' => 75, 'playerCount' => 18, 'goal' => 'Passen', 'fieldSize' => 'Halbfeld', 'material' => 'Bälle'];
        $plan = ['title' => 'Alttraining', 'focus' => 'Passen', 'principles' => ['Alte Philosophie'], 'qualityChecks' => ['Frühere Qualitätsprüfung'],
            'exercises' => array_map(fn ($phase) => ['phase' => $phase, 'title' => 'Passspiel', 'durationMinutes' => 25, 'setup' => 'Zwei Gruppen', 'flow' => 'Passen', 'coachingPoints' => ['Ballkontrolle'],
                'easier' => ['Mehr Zeit', 'Mehr Platz'], 'harder' => ['Weniger Zeit', 'Weniger Platz'], 'diagram' => ['description' => 'Passübung', 'svg' => '<svg><script>unsafe()</script></svg>']], ['Einstieg', 'Hauptteil', 'Abschluss'])];
        $this->source = (object) ['TrainingPlannerAuditLogID' => 1, 'Username' => $this->actor->username, 'Status' => 'success', 'TeamName' => 'Altteam', 'SharedTeamSaisonID' => 345,
            'RequestJson' => json_encode($input, JSON_THROW_ON_ERROR), 'PlanJson' => json_encode($plan, JSON_THROW_ON_ERROR)];
        $this->partialMock(LegacyTrainingPlanSource::class, function ($m) {
            $m->shouldReceive('rows')->andReturnUsing(fn () => collect([$this->source]));
        });
        $this->actingAs($this->actor, 'clubunio');
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        Filament::setTenant($this->tenant);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->rollBack();
        }parent::tearDown();
    }

    public function test_stage_and_import_are_idempotent_private_and_safely_converted(): void
    {
        $service = app(LegacyTrainingPlanImporter::class);
        $this->assertSame(1, $service->stage($this->actor, $this->tenant)['added']);
        $this->assertSame(1, $service->stage($this->actor, $this->tenant)['unchanged']);
        $record = LegacyTrainingPlan::where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->assertSame($this->actor->id, $record->suggested_owner_id);
        $this->assertSame($this->team, $record->suggested_team_season_id);
        $this->assertStringNotContainsString('<svg', json_encode($record->plan_json));
        $this->assertSame("Mehr Zeit\nMehr Platz", $record->plan_json['exercises'][0]['easier']);
        $data = ['revision' => $record->revision, 'owner_id' => $this->actor->id, 'team_season_id' => $this->team, 'decision_note' => 'Originalzuordnung bestätigt', 'schematic_confirmed' => true];
        Livewire::test(ManageRecords::class)->callTableAction('import', $record, data: $data)->assertHasNoActionErrors();
        $record->refresh();
        $this->assertSame('imported', $record->state);
        $this->assertSame($record->plan_id, $service->import($this->actor, $this->tenant, $record->id, $data));
        $plan = $this->db->table('clubora.training_plans')->find($record->plan_id);
        $this->assertFalse($plan->shared);
        $this->assertNotNull($plan->archived_at);
        $this->assertTrue($plan->legacy_quality_pending);
        Http::assertNothingSent();
    }

    public function test_old_quality_is_not_falsely_promoted_and_private_copy_can_be_reviewed(): void
    {
        $service = app(LegacyTrainingPlanImporter::class);
        $service->stage($this->actor, $this->tenant);
        $row = LegacyTrainingPlan::where('tenant_id', $this->tenant->id)->firstOrFail();
        $id = $service->import($this->actor, $this->tenant, $row->id, ['revision' => $row->revision, 'owner_id' => $this->actor->id, 'team_season_id' => $this->team, 'decision_note' => 'Prüfung', 'schematic_confirmed' => true]);
        $training = app(TrainingPlanService::class);
        try {
            $training->archive($this->actor, $this->tenant, $id, 1, false);
            $this->fail('Unreviewed legacy activated');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('revision', $e->errors());
        }
        $copy = $training->copy($this->actor, $this->tenant, $id, 1, (string) Str::uuid());
        $plan = $training->get($this->actor, $this->tenant, $copy);
        $this->assertFalse($plan->shared);
        $this->assertTrue($plan->legacy_quality_pending);
        $value = $plan->plan;
        $value['principles'] = ['SV Sissach: Spielfreude'];
        $value['qualityChecks'] = ['SFV: geprüft'];
        $training->save($this->actor, $this->tenant, $copy, 1, $value, false);
        $this->assertFalse($training->get($this->actor, $this->tenant, $copy)->legacy_quality_pending);
    }

    public function test_source_changes_and_non_success_rows_cannot_be_imported(): void
    {
        $service = app(LegacyTrainingPlanImporter::class);
        $service->stage($this->actor, $this->tenant);
        $row = LegacyTrainingPlan::where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->source->Status = 'server_error';
        $this->assertSame('excluded', $service->convert($this->source)['state']);
        $this->expectException(HttpException::class);
        $service->import($this->actor, $this->tenant, $row->id, ['revision' => $row->revision, 'owner_id' => $this->actor->id, 'team_season_id' => $this->team, 'decision_note' => 'Prüfung', 'schematic_confirmed' => true]);
    }
}
