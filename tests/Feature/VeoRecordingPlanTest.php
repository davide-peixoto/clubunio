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
            'veo_recording_adjustments' => 'tenant_id integer, match_id integer, field_id integer, starts_at text, ends_at text, basis_hash text, revision integer, updated_by integer, updated_at text, primary key(tenant_id,match_id)',
            'object_changes' => 'id integer primary key, tenant_id integer, actor_id integer, object_type text, object_id integer, before_value text, after_value text',
            'tenants' => 'id integer primary key, name text, slug text',
            'accounts' => 'id integer primary key, active boolean',
            'tenant_accounts' => 'tenant_id integer, account_id integer, role text',
        ] as $table => $columns) {
            $db->statement("CREATE TABLE clubora.$table ($columns)");
        }
        $this->tenant = (new Tenant)->forceFill(['id' => 1]);
        $this->table('tenants')->insert(['id' => 1, 'name' => 'Fixture', 'slug' => 'fixture']);
        $this->table('accounts')->insert(['id' => 8, 'active' => true]);
        $this->table('tenant_accounts')->insert(['tenant_id' => 1, 'account_id' => 8, 'role' => 'admin']);
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

    public function test_confirmed_last_minute_override_is_used_until_booking_ends(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-14T13:00:00+01:00'));
        $this->match(21, '14:00:00');
        $this->plan->setTeamEnabled($this->tenant, 5, true);
        $this->plan->saveCamera($this->tenant, 11, 'Camera B');
        $competition = $this->table('competitions')->where('association_match_id', 21);
        $competition->update(['starts_at' => '14:15:00', 'field_id' => 11]);
        $this->assertSame('unplaced', $this->entry()['status']);
        $this->table('object_changes')->insert([
            'tenant_id' => 1, 'object_type' => 'sfv_local_override', 'object_id' => $competition->value('id'),
            'after_value' => json_encode(['placement' => ['2026-11-14', '14:15', 11], 'until' => '2026-11-14T16:15:00+01:00']),
        ]);
        $this->assertSame('Camera B', $this->entry()['camera']);
        $this->assertSame('14:15', $this->entry()['starts_at']->format('H:i'));
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-14T16:15:00+01:00'));
        $this->assertSame('unplaced', $this->entry()['status']);
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

    public function test_override_window_includes_exactly_one_hour_before_kickoff(): void
    {
        $kickoff = \Carbon\CarbonImmutable::parse('2026-11-14T14:00:00+01:00');
        $this->assertFalse(\App\ClubUnio\Services\SfvLocalOverride::allowsAt($kickoff, $kickoff->subHour()->subSecond()));
        $this->assertTrue(\App\ClubUnio\Services\SfvLocalOverride::allowsAt($kickoff, $kickoff->subHour()));
        $this->assertTrue(\App\ClubUnio\Services\SfvLocalOverride::allowsAt($kickoff, $kickoff->subSecond()));
        $this->assertFalse(\App\ClubUnio\Services\SfvLocalOverride::allowsAt($kickoff, $kickoff));
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

    public function test_recording_move_is_audited_without_changing_the_fixture_or_pitch(): void
    {
        $this->prepareRecording();
        $before = (array) $this->table('competitions')->first();
        $source = (array) $this->table('sfv_schedule')->first();
        $this->adjust(['time' => '13:30', 'duration' => 150]);
        $entry = $this->entry();
        $this->assertSame('13:30', $entry['starts_at']->format('H:i'));
        $this->assertSame('16:00', $entry['ends_at']->format('H:i'));
        $this->assertTrue($entry['adjusted']);
        $this->assertSame($before, (array) $this->table('competitions')->first());
        $this->assertSame($source, (array) $this->table('sfv_schedule')->first());
        $this->assertSame(1, $this->table('object_changes')->where('object_type', 'veo_recording')->count());
    }

    public function test_stale_recording_write_is_rejected_and_sfv_change_requires_review(): void
    {
        $this->prepareRecording();
        $revision = $this->entry()['revision'];
        $this->adjust(['time' => '13:30']);
        try { $this->adjust(['revision' => $revision]); $this->fail('Stale write accepted'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) { $this->assertSame(409, $exception->getStatusCode()); }
        $this->table('sfv_schedule')->where('match_id', 21)->update(['starts_at' => '2026-11-14T15:00:00+01:00']);
        $this->assertSame('needs_review', $this->entry()['status']);
        $this->assertSame('15:00', $this->entry()['starts_at']->format('H:i'));
    }

    public function test_camera_overlap_is_rejected_and_reset_returns_to_automatic_plan(): void
    {
        $this->prepareRecording();
        $this->match(22, '17:00:00');
        try { $this->adjust(['time' => '16:30']); $this->fail('Overlap accepted'); }
        catch (\Illuminate\Validation\ValidationException $exception) { $this->assertArrayHasKey('form.time', $exception->errors()); }
        $this->assertSame(0, $this->table('veo_recording_adjustments')->count());
        $this->adjust(['time' => '13:30']);
        app(\App\ClubUnio\Services\VeoRecordingAdjustments::class)->save($this->actor(), $this->tenant, 21, ['revision' => $this->entry()['revision']], true);
        $this->assertSame('14:00', $this->entry()['starts_at']->format('H:i'));
        $this->assertFalse($this->entry()['adjusted']);
        $this->assertSame(2, $this->table('object_changes')->count());
    }

    public function test_cross_day_recording_remains_visible_in_new_date_and_overnight_window(): void
    {
        $this->prepareRecording();
        $this->adjust(['date' => '2026-11-16', 'time' => '23:00', 'duration' => 120]);
        $this->assertCount(0, $this->plan->entries($this->tenant, '2026-11-14', '2026-11-14'));
        $this->assertCount(1, $this->plan->entries($this->tenant, '2026-11-16', '2026-11-16'));
        $this->assertCount(1, $this->plan->entries($this->tenant, '2026-11-17', '2026-11-17'));
        $this->assertSame('23:00', app(\App\ClubUnio\Services\VeoRecordingAdjustments::class)->current($this->tenant, 21)['starts_at']->format('H:i'));
    }

    public function test_fresh_permissions_and_impersonation_block_recording_writes(): void
    {
        $this->prepareRecording();
        $this->table('tenant_accounts')->update(['role' => 'trainer']);
        try { $this->adjust([]); $this->fail('Trainer accepted'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) { $this->assertSame(403, $exception->getStatusCode()); }
        $this->table('tenant_accounts')->update(['role' => 'admin']);
        request()->attributes->set('clubunio_impersonation', true);
        try { $this->adjust([]); $this->fail('Impersonated write accepted'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) { $this->assertSame(403, $exception->getStatusCode()); }
        finally { request()->attributes->remove('clubunio_impersonation'); }
        $this->assertSame(0, $this->table('veo_recording_adjustments')->count());
    }

    public function test_foreign_field_and_disabled_camera_are_rejected(): void
    {
        $this->prepareRecording();
        foreach ([12 => 404, 11 => 422] as $field => $status) {
            try { $this->adjust(['field_id' => $field]); $this->fail('Invalid camera accepted'); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) { $this->assertSame($status, $exception->getStatusCode()); }
        }
        $this->assertSame(0, $this->table('veo_recording_adjustments')->count());
    }

    public function test_foreign_fixture_and_disabled_team_are_not_editable(): void
    {
        $this->prepareRecording();
        $service = app(\App\ClubUnio\Services\VeoRecordingAdjustments::class);
        try { $service->current((new Tenant)->forceFill(['id' => 2]), 21); $this->fail('Foreign fixture exposed'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) { $this->assertSame(404, $exception->getStatusCode()); }
        $this->plan->setTeamEnabled($this->tenant, 5, false);
        try { $service->current($this->tenant, 21); $this->fail('Disabled team editable'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) { $this->assertSame(404, $exception->getStatusCode()); }
    }

    private function prepareRecording(): void
    {
        $this->match(21, '14:00:00');
        $this->plan->setTeamEnabled($this->tenant, 5, true);
        $this->plan->saveCamera($this->tenant, 10, 'Camera A');
    }

    public function test_calendar_and_drag_proposal_render_in_both_languages_without_writing(): void
    {
        $this->prepareRecording();
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('clubunio'));
        // SQLite cannot select schema.table.*; isolate only the rendering optimization.
        $this->partialMock(\App\ClubUnio\Services\BackofficeReadContext::class)->shouldReceive('duringRender')->andReturnUsing(fn ($render) => $render());
        \Filament\Facades\Filament::setTenant($this->tenant, isQuiet: true);
        \Filament\Facades\Filament::auth()->setUser($this->actor());
        $options = \Mockery::mock(\App\ClubUnio\Services\PitchPlanningService::class);
        $options->shouldReceive('options')->andReturn(['fields' => [['id' => 10, 'name' => 'Pitch A', 'available' => true]],
            'teams' => [['id' => 5, 'name' => 'Fixture team']]]);
        app()->instance(\App\ClubUnio\Services\PitchPlanningService::class, $options);
        foreach (['de', 'en'] as $locale) {
            app()->setLocale($locale);
            $component = \Livewire\Livewire::test(VeoCalendarHarness::class)->assertStatus(200)->set('date', '2026-11-14')->assertStatus(200);
            foreach (['day', 'week', 'month'] as $mode) {
                $component->set('mode', $mode)->assertHasNoErrors()->assertSee('data-cu-facility-planner', false)
                    ->assertSee('Fixture 21')->assertSee(trans('veo_calendar.settings'));
            }
            $component->call('proposeMove', 'recording:21', 10, '2026-11-14', '13:30')
                ->assertSet('form.time', '13:30')->assertSet('selected.match_id', 21)
                ->assertDispatched('open-modal', id: 'veo-recording-editor');
            $this->assertSame(0, $this->table('veo_recording_adjustments')->count());
            $component->call('closeEditor')->assertSet('selected', null);
        }
    }

    private function actor(): \App\ClubUnio\Models\Account
    {
        return \App\ClubUnio\Models\Account::findOrFail(8);
    }

    private function adjust(array $changes): void
    {
        app(\App\ClubUnio\Services\VeoRecordingAdjustments::class)->save($this->actor(), $this->tenant, 21,
            array_replace(['revision' => $this->entry()['revision'], 'date' => '2026-11-14', 'time' => '14:00', 'duration' => 120, 'field_id' => 10], $changes));
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

class VeoCalendarHarness extends \App\ClubUnio\Pages\VeoPlanning
{
    public function boot(): void
    {
        \Filament\Facades\Filament::setTenant((new Tenant)->forceFill(['id' => 1]), isQuiet: true);
        \Filament\Facades\Filament::auth()->setUser(\App\ClubUnio\Models\Account::findOrFail(8));
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('clubunio.veo-planning', $this->getViewData());
    }
}
