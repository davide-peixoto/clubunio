<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\VeoRecordingPlan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VeoRecordingPlanTest extends TestCase
{
    private VeoRecordingPlan $plan;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.clubunio' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('clubunio');
        $db = DB::connection('clubunio');
        $db->statement("ATTACH DATABASE ':memory:' AS clubora");
        foreach ([
            'competitions' => 'id integer primary key, tenant_id integer, active boolean, plays_on text, starts_at text, field_id integer, association_match_id integer',
            'sfv_schedule' => 'tenant_id integer, match_id integer, team_a_id integer, team_b_id integer, starts_at text, title text, home boolean, state text',
            'teams' => 'id integer primary key, tenant_id integer, sfv_team_id integer, active boolean',
            'seasons' => 'id integer primary key, tenant_id integer, starts_on text, ends_on text, active boolean',
            'team_seasons' => 'id integer primary key, tenant_id integer, team_id integer, season_id integer, active boolean',
            'pitch_competition_teams' => 'tenant_id integer, competition_id integer, team_season_id integer',
            'pitch_competition_settings' => 'tenant_id integer, competition_id integer, duration_minutes integer, withdrawn boolean',
            'training_options' => 'id integer primary key, tenant_id integer, category text, active boolean',
            'veo_cameras' => 'id integer primary key, tenant_id integer, field_id integer, name text, unique(tenant_id,field_id)',
            'veo_recording_teams' => 'tenant_id integer, team_season_id integer, primary key(tenant_id,team_season_id)',
        ] as $table => $columns) {
            $db->statement("CREATE TABLE clubora.$table ($columns)");
        }
        $this->tenant = (new Tenant)->forceFill(['id' => 1]);
        $this->plan = app(VeoRecordingPlan::class);
        $this->table('teams')->insert(['id' => 3, 'tenant_id' => 1, 'sfv_team_id' => 90, 'active' => true]);
        $this->table('seasons')->insert(['id' => 4, 'tenant_id' => 1,
            'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30', 'active' => true]);
        $this->table('team_seasons')->insert(['id' => 5, 'tenant_id' => 1, 'team_id' => 3, 'season_id' => 4, 'active' => true]);
        foreach ([10 => 1, 11 => 1, 12 => 2] as $field => $tenant) {
            $this->table('training_options')->insert(['id' => $field, 'tenant_id' => $tenant, 'category' => 'field', 'active' => true]);
        }
    }

    public function test_plan_follows_confirmed_field_and_requires_reconfirmation_after_an_sfv_time_change(): void
    {
        $this->match(21, '14:00:00');
        $this->assertSame([], $this->plan->entries($this->tenant, '2026-11-14', '2026-11-14'));
        $this->plan->setTeamEnabled($this->tenant, 5, true);
        $this->assertSame('no_camera', $this->entry()['status']);

        $this->plan->saveCamera($this->tenant, 10, 'Camera A');
        $this->plan->saveCamera($this->tenant, 11, 'Camera B');
        $this->assertSame('Camera A', $this->entry()['camera']);
        $this->assertSame('planned', $this->entry()['status']);
        $this->table('competitions')->where('association_match_id', 21)->update(['field_id' => 11]);
        $this->assertSame('Camera B', $this->entry()['camera']);

        $this->table('sfv_schedule')->where('match_id', 21)->update(['starts_at' => '2026-11-14T15:30:00+01:00']);
        $this->assertSame('15:30', $this->entry()['starts_at']->format('H:i'));
        $this->assertSame('unplaced', $this->entry()['status']);
        $this->assertNull($this->entry()['camera']);
        $this->table('competitions')->where('association_match_id', 21)->update(['starts_at' => '15:30:00']);
        $this->assertSame('planned', $this->entry()['status']);

        $this->table('sfv_schedule')->where('match_id', 21)->update(['state' => 'Abgesagt']);
        $this->assertSame([], $this->plan->entries($this->tenant, '2026-11-14', '2026-11-14'));
    }

    public function test_camera_overlap_is_reported_for_both_matches(): void
    {
        $this->match(21, '14:00:00');
        $this->match(22, '15:30:00');
        $this->match(23, '17:30:00');
        $this->plan->setTeamEnabled($this->tenant, 5, true);
        $this->plan->saveCamera($this->tenant, 10, 'Camera A');
        $entries = $this->plan->entries($this->tenant, '2026-11-14', '2026-11-14');
        $this->assertSame(['conflict', 'conflict', 'planned'], array_column($entries, 'status'));
    }

    public function test_missing_or_ambiguous_placement_is_visible_without_selecting_a_camera(): void
    {
        $this->match(21, '14:00:00');
        $this->plan->setTeamEnabled($this->tenant, 5, true);
        $this->plan->saveCamera($this->tenant, 10, 'Camera A');
        $placement = (array) $this->table('competitions')->first();
        $this->table('competitions')->insert(array_replace($placement, ['id' => 22, 'field_id' => 11]));
        $this->assertSame('unplaced', $this->entry()['status']);
        $this->assertNull($this->entry()['camera']);

        $this->table('competitions')->delete();
        $this->assertSame('unplaced', $this->entry()['status']);
        $this->assertNull($this->entry()['field_id']);
    }

    public function test_other_tenant_camera_cannot_be_configured_or_selected(): void
    {
        $this->match(21, '14:00:00');
        $this->plan->setTeamEnabled($this->tenant, 5, true);
        $otherTenant = (new Tenant)->forceFill(['id' => 2]);
        $this->plan->saveCamera($otherTenant, 12, 'Other Camera');
        $this->table('competitions')->where('association_match_id', 21)->update(['field_id' => 12]);
        $this->assertSame('unplaced', $this->entry()['status']);
        $this->assertTrue($this->plan->cameras($this->tenant)->isEmpty());

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        $this->plan->saveCamera($this->tenant, 12, 'Forbidden');
    }

    private function entry(): array
    {
        return $this->plan->entries($this->tenant, '2026-11-14', '2026-11-14')[0];
    }

    private function match(int $id, string $time): void
    {
        $this->table('sfv_schedule')->insert(['tenant_id' => 1, 'match_id' => $id, 'title' => 'Fixture '.$id,
            'team_a_id' => 90, 'team_b_id' => 91, 'starts_at' => '2026-11-14T'.$time.'+01:00', 'home' => true]);
        $this->table('competitions')->insert(['id' => $id, 'tenant_id' => 1, 'active' => true, 'plays_on' => '2026-11-14',
            'starts_at' => $time, 'field_id' => 10, 'association_match_id' => $id]);
    }

    private function table(string $name): \Illuminate\Database\Query\Builder
    {
        return DB::connection('clubunio')->table('clubora.'.$name);
    }
}
