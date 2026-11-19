<?php

namespace Tests\Feature;

use App\ClubUnio\Models\{Account, Tenant, TrainingSlot};
use App\ClubUnio\Resources\TrainingSlotResource;
use App\ClubUnio\Services\{ActiveTrainingPlan, ContributionTrainingService, PitchPlanningNotificationService, PitchPlanningProjection, PitchPlanningService, PitchWeeklyAreas, SeasonPlanningService, SignageService};
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\{Crypt, DB, Http, Mail};
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Migration SQL runs only against pg_temp; synthetic parent fixtures always roll back. */
class PitchWeeklyAreasTest extends TestCase
{
    private $db;
    private $grammar;
    private Tenant $tenant;
    private Account $actor;
    private int $season;
    private int $team;
    private int $field;
    private int $otherField;
    private int $period;
    private int $weekday;
    private array $area;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') $this->markTestSkipped('Synthetic PostgreSQL rollback opt-in required.');
        Http::preventStrayRequests();
        Mail::fake();
        $this->db = DB::connection('clubunio');
        $this->assertSame('pgsql', $this->db->getDriverName());
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->grammar = $this->db->getQueryGrammar();
        $this->db->beginTransaction();
        $suffix = bin2hex(random_bytes(8));
        $this->tenant = Tenant::create(['slug' => 'weekly-'.$suffix, 'name' => 'Weekly fixture']);
        $this->actor = new Account;
        $this->actor->forceFill(['username' => 'weekly-'.$suffix, 'display_name' => 'Weekly fixture', 'password' => bcrypt('fixture'), 'active' => true])->save();
        $this->insert('tenant_accounts', ['account_id' => $this->actor->id, 'role' => 'admin']);
        $this->season = $this->insert('seasons', ['name' => 'Weekly season', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $team = $this->insert('teams', ['name' => 'Weekly team']);
        $this->team = $this->insert('team_seasons', ['season_id' => $this->season, 'team_id' => $team, 'label' => 'Weekly team']);
        $this->field = $this->insert('training_options', ['category' => 'field', 'code' => 'H', 'name' => 'Main pitch']);
        $this->otherField = $this->insert('training_options', ['category' => 'field', 'code' => 'K', 'name' => 'Other pitch']);
        $this->period = $this->insert('training_options', ['category' => 'period', 'code' => 'WEEKLY', 'name' => 'Weekly period']);
        $this->weekday = $this->insert('training_options', ['category' => 'weekday', 'code' => '4', 'name' => 'Wednesday']);
        $status = $this->insert('facility_statuses', ['code' => 'WEEKLY', 'name' => 'Open', 'blocks_training' => false]);
        foreach ([$this->field, $this->otherField] as $field) $this->insert('field_availabilities', ['field_id' => $field, 'status_id' => $status]);
        $this->insert('tenant_settings', ['area' => 'general', 'key' => ActiveTrainingPlan::KEY, 'label' => 'Period', 'value_type' => 'string', 'encrypted_value' => Crypt::encryptString((string) $this->period)]);
        $this->insert('tenant_settings', ['area' => 'general', 'key' => 'SaisonID', 'label' => 'Season', 'value_type' => 'string', 'encrypted_value' => Crypt::encryptString((string) $this->season)]);
        foreach (['tenants', 'accounts', 'training_options', 'training_slots', 'team_seasons', 'competitions'] as $table) {
            $this->db->statement("CREATE TEMP TABLE $table (LIKE clubunio.$table INCLUDING ALL) ON COMMIT DROP");
            $column = in_array($table, ['tenants', 'accounts']) ? 'id' : 'tenant_id';
            $this->db->statement("INSERT INTO pg_temp.$table SELECT * FROM clubunio.$table WHERE $column = ?", [$table === 'accounts' ? $this->actor->id : $this->tenant->id]);
        }
        // Recreate the pre-001700 baseline even after a future authorized deployment.
        $this->db->statement('ALTER TABLE pg_temp.training_slots DROP COLUMN IF EXISTS area_id');
        foreach (['001500_pitch_planning', '001700_pitch_weekly_areas'] as $migration) {
            $queries = $this->db->pretend(fn () => (require database_path('clubunio/2026_11_04_'.$migration.'.php'))->up());
            foreach ($queries as $query) {
                $sql = str_replace(['clubunio.', 'CREATE TABLE '], ['pg_temp.', 'CREATE TEMP TABLE '], $query['query']);
                $this->db->statement($sql);
            }
        }
        $this->db->setQueryGrammar(new class($this->db) extends \Illuminate\Database\Query\Grammars\PostgresGrammar {
            public function wrapTable($table, $prefix = null) {
                if (str_starts_with($table, 'clubunio.training_slots') || str_starts_with($table, 'clubunio.pitch_')) $table = str_replace('clubunio.', 'pg_temp.', $table);
                return parent::wrapTable($table, $prefix);
            }
            public function wrap($value) {
                if (is_string($value)) $value = str_replace('clubunio.training_slots.', 'pg_temp.training_slots.', $value);
                return parent::wrap($value);
            }
        });
        $this->app->instance(PitchWeeklyAreas::class, new class extends PitchWeeklyAreas { public function ready(): bool { return true; } });
        $this->app->instance(PitchPlanningProjection::class, new class extends PitchPlanningProjection { public function ready(): bool { return true; } });
        $this->mock(PitchPlanningNotificationService::class)->shouldReceive('record')->andReturnNull();
        $this->area = app(PitchPlanningService::class)->saveArea($this->actor, $this->tenant, [
            'field_id' => $this->field, 'name_de' => 'Linke Haelfte', 'name_en' => 'Left half', 'x' => 0, 'y' => 0, 'width' => .5, 'height' => 1]);
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $this->actingAs($this->actor, 'clubunio');
        Filament::setTenant($this->tenant);
    }

    protected function tearDown(): void
    {
        if ($this->db) {
            while ($this->db->transactionLevel()) $this->db->rollBack();
            $this->db->setQueryGrammar($this->grammar);
        }
        parent::tearDown();
    }

    private function insert(string $table, array $data): int
    {
        return $this->db->table('clubunio.'.$table)->insertGetId($data + ['tenant_id' => $this->tenant->id]);
    }

    private function input(array $extra = []): array
    {
        return $extra + ['team_season_id' => $this->team, 'field_id' => $this->field, 'area_id' => $this->area['id'],
            'period_id' => $this->period, 'weekday_id' => $this->weekday, 'starts_at' => '18:00', 'ends_at' => '19:30',
            'all_fields' => true, 'without_main' => false, 'synthetic_only' => false, 'website_visible' => true, 'active' => true];
    }

    private function save(array $input, ?int $id = null): TrainingSlot
    {
        return app(ContributionTrainingService::class)->save($this->actor, $this->tenant, TrainingSlot::class, $id, $input);
    }

    private function invalid(callable $action, string $key = 'area_id'): void
    {
        try { $action(); $this->fail('Expected validation failure'); }
        catch (ValidationException $error) { $this->assertArrayHasKey($key, $error->errors()); }
    }

    private function conflict(callable $action): void
    {
        try { $action(); $this->fail('Expected stale revision or geometry conflict'); }
        catch (HttpException $error) { $this->assertSame(409, $error->getStatusCode()); }
    }

    public function test_save_copy_and_omitted_metadata_preserve_defaults_with_revision_and_audit(): void
    {
        $slot = $this->save($this->input());
        $this->assertSame($this->area['id'], $slot->area_id);
        $fields = TrainingSlotResource::form(Schema::make())->getComponents(withHidden: true);
        $names = array_map(fn ($field) => $field->getName(), $fields);
        $this->assertContains('area_id', $names);
        $copy = $slot->only($names);
        unset($copy['revision']);
        $copied = $this->save($copy);
        $this->assertNotSame($slot->id, $copied->id);
        $this->assertSame($slot->area_id, $copied->area_id);
        $input = $this->input(['revision' => $slot->revision, 'ends_at' => '20:00']);
        unset($input['area_id']);
        $edited = $this->save($input, $slot->id);
        $this->assertSame($this->area['id'], $edited->area_id);
        $this->assertSame(2, (int) $edited->revision);
        $this->invalid(fn () => $this->save($input, $slot->id), 'revision');
        $input['revision'] = $edited->revision;
        $input['field_id'] = $this->otherField;
        $this->invalid(fn () => $this->save($input, $slot->id));
        $input['area_id'] = null;
        $this->assertNull($this->save($input, $slot->id)->area_id);
        $audit = $this->db->table('clubunio.object_changes')->where('tenant_id', $this->tenant->id)->where('object_type', 'training_slots')->orderByDesc('id')->first();
        $this->assertNull(json_decode($audit->after_value, true)['area_id']);
    }

    public function test_new_training_defaults_to_half_and_parallel_training_uses_other_half(): void
    {
        $right = app(PitchPlanningService::class)->saveArea($this->actor, $this->tenant, [
            'field_id' => $this->field, 'name_de' => 'B', 'name_en' => 'B', 'x' => .5, 'y' => 0, 'width' => .5, 'height' => 1]);
        $input = $this->input();
        unset($input['area_id']);
        $first = $this->save($input);
        $this->assertSame($this->area['id'], $first->area_id);
        $second = $this->save($this->input(['area_id' => 0]));
        $this->assertSame($right['id'], $second->area_id);
        $later = $this->save($this->input(['area_id' => 0, 'starts_at' => '19:30', 'ends_at' => '20:30']));
        $this->assertSame($first->area_id, $later->area_id);
        $this->assertNull($this->save($this->input(['area_id' => null]))->area_id);
        $this->assertNull($this->save($this->input(['area_id' => 0, 'field_id' => null, 'hall' => 'Hall']))->area_id);
        $edited = $this->save($input + ['revision' => $first->revision], $first->id);
        $this->assertSame($first->area_id, $edited->area_id);
        $this->assertSame(__('Halber Platz (automatisch)'), app(PitchWeeklyAreas::class)->trainingOptions($this->tenant, $this->field)[0]);
    }

    public function test_thirds_and_quarters_are_selectable_persisted_and_localized_without_changing_half_default(): void
    {
        $presets = require base_path('deploy/pitch-fraction-presets.php');
        $ids = [];
        foreach ($presets as $preset) {
            $area = app(PitchPlanningService::class)->saveArea($this->actor, $this->tenant, ['field_id' => $this->field] + $preset);
            $ids[] = $area['id'];
            $slot = $this->save($this->input(['area_id' => $area['id']]));
            $this->assertSame($area['id'], $slot->area_id);
            foreach (['de', 'en'] as $locale) {
                app()->setLocale($locale);
                $label = $preset['name_'.$locale];
                $this->assertSame($label, app(PitchWeeklyAreas::class)->trainingOptions($this->tenant, $this->field)[$area['id']]);
                $field = collect(app(PitchPlanningProjection::class)->fields($this->tenant))->firstWhere('id', $this->field);
                $this->assertSame($label, collect($field['areas'])->firstWhere('id', $area['id'])['name']);
            }
        }
        $automatic = $this->save($this->input(['area_id' => 0, 'starts_at' => '21:00', 'ends_at' => '22:00']));
        $this->assertSame($this->area['id'], $automatic->area_id);
        $this->assertNotContains($automatic->area_id, $ids);
    }

    public function test_service_rejects_wrong_field_foreign_area_no_field_and_inactive_field(): void
    {
        $this->invalid(fn () => $this->save($this->input(['field_id' => $this->otherField])));
        $this->invalid(fn () => $this->save($this->input(['field_id' => null, 'hall' => 'Hall'])));
        $foreign = Tenant::create(['slug' => 'weekly-foreign-'.bin2hex(random_bytes(8)), 'name' => 'Foreign']);
        $this->db->statement('INSERT INTO pg_temp.tenants SELECT * FROM clubunio.tenants WHERE id = ?', [$foreign->id]);
        $field = $this->insert('training_options', ['tenant_id' => $foreign->id, 'category' => 'field', 'code' => 'F', 'name' => 'Foreign']);
        $this->db->statement('INSERT INTO pg_temp.training_options SELECT * FROM clubunio.training_options WHERE id = ?', [$field]);
        $area = $this->insert('pitch_areas', ['tenant_id' => $foreign->id, 'field_id' => $field, 'name_de' => 'Privat', 'name_en' => 'Private', 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1]);
        $this->invalid(fn () => $this->save($this->input(['area_id' => $area])));
        $this->assertNotContains('Private', app(PitchWeeklyAreas::class)->options($this->tenant, $field));
        $this->db->table('clubunio.training_options')->where('id', $this->field)->update(['active' => false]);
        $this->invalid(fn () => $this->save($this->input()), 'field_id');
    }

    public function test_migration_constraints_reject_invalid_pairing_and_null_field(): void
    {
        foreach ([['field_id' => $this->otherField], ['field_id' => null], ['tenant_id' => $this->tenant->id + 999999]] as $extra) {
            try {
                $this->db->transaction(fn () => $this->insert('training_slots', $extra + $this->input()));
                $this->fail('Database boundary missing');
            } catch (\Illuminate\Database\QueryException $error) {
                $this->assertContains($error->getCode(), ['23503', '23514']);
            }
        }
        $this->assertNull($this->save($this->input(['field_id' => null, 'area_id' => null, 'hall' => 'Hall']))->area_id);
    }

    public function test_projection_exception_whole_field_and_base_revision_changes(): void
    {
        $slot = $this->save($this->input());
        $service = app(PitchPlanningService::class);
        $key = 'training:'.$slot->id.':2026-11-04';
        $base = $service->resolve($this->tenant, $key);
        $this->assertSame($slot->area_id, $base['area_id']);
        $move = ['revision' => $base['revision'], 'date' => '2026-11-06', 'starts_at' => '10:00', 'duration_minutes' => 90, 'field_id' => $this->otherField, 'area_id' => null];
        $moved = $service->move($this->actor, $this->tenant, $key, $move);
        $this->assertNull($moved['area_id']);
        $this->assertSame($this->otherField, $moved['field_id']);
        $this->save($this->input(['revision' => $slot->revision, 'area_id' => null]), $slot->id);
        $fresh = $service->resolve($this->tenant, $key);
        $this->assertNotSame($moved['revision'], $fresh['revision']);
        $this->assertSame($moved['field_id'], $fresh['field_id']);
        $this->assertNull($fresh['area_id']);
        $this->conflict(fn () => $service->move($this->actor, $this->tenant, $key, ['revision' => $moved['revision']] + $move));
        $next = $service->resolve($this->tenant, 'training:'.$slot->id.':2026-11-11');
        $this->assertNull($next['area_id']);
        $this->assertSame($this->field, $next['field_id']);
    }

    public function test_weekly_only_usage_prevents_geometry_changes_but_allows_names(): void
    {
        $this->save($this->input());
        $service = app(PitchPlanningService::class);
        $this->conflict(fn () => $service->saveArea($this->actor, $this->tenant, array_replace($this->area, ['width' => .75])));
        $renamed = $service->saveArea($this->actor, $this->tenant, array_replace($this->area, ['name_en' => 'West half']));
        $this->assertSame('West half', $renamed['name_en']);
    }

    public function test_signage_and_mobile_template_project_localized_safe_area_metadata(): void
    {
        $slot = $this->save($this->input());
        foreach (['de' => 'Linke Haelfte', 'en' => 'Left half'] as $locale => $label) {
            app()->setLocale($locale);
            $display = app(SignageService::class)->preview($this->actor, $this->tenant, ['date' => '2026-11-04', 'period_id' => $this->period]);
            $this->assertSame($slot->area_id, $display['trainings']->first()->area_id);
            $this->assertSame($label, $display['trainings']->first()->pitch_area_name);
            $method = new \ReflectionMethod(\App\ClubUnio\Mobile\MobileTeamsData::class, 'trainingsForAuthorizedTeam');
            $rows = $method->invoke(app(\App\ClubUnio\Mobile\MobileTeamsData::class), $this->tenant, $this->team);
            $this->assertSame($slot->area_id, $rows[0]['area_id']);
            $this->assertSame($label, $rows[0]['area_name']);
            $this->assertArrayNotHasKey('tenant_id', $rows[0]);
        }
        $this->assertSame($slot->area_id, $slot->fresh()->area_id);
    }

    public function test_season_copy_roundtrip_and_review_rejects_area_from_other_field(): void
    {
        $this->save($this->input());
        $service = app(SeasonPlanningService::class);
        $doc = $service->shape($service->seed($this->actor, $this->tenant, $this->season));
        $this->assertSame($this->area['id'], $doc['slots'][0]['area_id']);
        $doc['slots'][0]['field_id'] = $this->otherField;
        $draft = $service->save($this->actor, $this->tenant, null, null, $doc, $this->season);
        $review = $service->review($this->actor, $this->tenant, $draft->id);
        $this->assertContains(__('Das ausgewählte Spielfeld oder die Teilfläche ist nicht verfügbar.'), $review['errors']);
        $doc['slots'][0]['field_id'] = $this->field;
        $draft = $service->save($this->actor, $this->tenant, $draft->id, $draft->revision, $doc);
        $review = $service->review($this->actor, $this->tenant, $draft->id);
        $this->assertSame([], $review['errors']);
        $season = $service->complete($this->actor, $this->tenant, $draft->id, $draft->revision, $review['token'], true);
        $newTeam = $this->db->table('clubunio.team_seasons')->where('tenant_id', $this->tenant->id)->where('season_id', $season)->value('id');
        $this->assertNotNull($newTeam);
        $this->assertSame($this->area['id'], TrainingSlot::where('tenant_id', $this->tenant->id)->where('team_season_id', $newTeam)->firstOrFail()->area_id);
    }

    public function test_missing_weekly_schema_keeps_legacy_writes_and_rejects_nonempty_area(): void
    {
        $this->db->statement('ALTER TABLE pg_temp.training_slots DROP COLUMN area_id');
        $this->app->instance(PitchWeeklyAreas::class, new class extends PitchWeeklyAreas { public function ready(): bool { return false; } });
        $slot = $this->save($this->input(['area_id' => null]));
        $this->assertNull($slot->area_id);
        $this->assertNull(app(PitchPlanningProjection::class)->training($slot, '2026-11-04')['area_id']);
        $this->invalid(fn () => $this->save($this->input()));
    }

    public function test_generic_api_roundtrips_area_and_keeps_pre_migration_export_query_safe(): void
    {
        $profile = $this->insert('access_profiles', ['name' => 'Weekly API', 'active' => true,
            'permissions' => json_encode(['training_slots.view', 'training_slots.create', 'training_slots.update'])]);
        $this->actor->forceFill(['api_only' => true])->save();
        $this->db->table('clubunio.tenant_accounts')->where('tenant_id', $this->tenant->id)->update(['role' => 'profile', 'access_profile_id' => $profile]);
        $scopes = ['training_slots:read', 'training_slots:create', 'training_slots:update'];
        $integration = new \App\ClubUnio\Models\ApiIntegration;
        $integration->forceFill(['tenant_id' => $this->tenant->id, 'account_id' => $this->actor->id, 'access_profile_id' => $profile,
            'name' => 'Weekly API fixture', 'scopes' => $scopes, 'active' => true, 'created_by' => $this->actor->id])->save();
        $token = $integration->createToken('fixture', $scopes, now()->addHour())->accessToken;
        $context = new \App\ClubUnio\Api\ApiContext($this->actor, $this->tenant, $integration, $token);
        $module = new \App\ClubUnio\Api\Modules\ApiModule(\App\ClubUnio\Api\Modules\ApiModuleDefinitions::all()['training_slots']);
        $created = $module->create($context, $this->input());
        $this->assertSame($this->area['id'], $created['area_id']);
        $this->assertArrayNotHasKey('tenant_id', $created);
        $this->assertSame($this->area['id'], $module->query($context)->first()->area_id);
        $updated = $module->update($context, $created['id'], $this->input(['revision' => $created['revision'], 'area_id' => null]));
        $this->assertNull($updated['area_id']);
        $this->invalid(fn () => $module->update($context, $created['id'], $this->input(['revision' => $updated['revision'], 'field_id' => $this->otherField])));
        $this->db->statement('ALTER TABLE pg_temp.training_slots DROP COLUMN area_id');
        $this->app->instance(PitchWeeklyAreas::class, new class extends PitchWeeklyAreas { public function ready(): bool { return false; } });
        $this->assertNull($module->query($context)->first()->area_id);
        $this->assertNull($module->show($context, $created['id'])['area_id']);
    }

    public function test_weekly_area_write_does_not_expand_read_only_permissions(): void
    {
        $profile = $this->insert('access_profiles', ['name' => 'Weekly read only', 'active' => true, 'permissions' => json_encode(['training_slots.view'])]);
        $this->db->table('clubunio.tenant_accounts')->where('tenant_id', $this->tenant->id)->update(['role' => 'profile', 'access_profile_id' => $profile]);
        try { $this->save($this->input()); $this->fail('Read-only profile wrote weekly area'); }
        catch (HttpException $error) { $this->assertSame(403, $error->getStatusCode()); }
        $this->assertSame(0, $this->db->table('pg_temp.training_slots')->count());
    }
}
