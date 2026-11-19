<?php

namespace Tests\Feature;

use App\ClubUnio\Mobile\{MobileFacilitiesData, MobilePitchController, MobileTeamsData};
use App\ClubUnio\Models\{Account, FieldAvailability, Tenant, TrainingOption, TrainingSlot};
use App\ClubUnio\Pages\Components\PitchPlanningCalendar;
use App\ClubUnio\Pages\PitchPlanning;
use App\ClubUnio\Services\{ActiveTrainingPlan, ContributionTrainingService, FacilityPlanningMode, FacilityService, PitchPlanningProjection, PitchPlanningService, PitchWeeklyAreas, SignageService};
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt, DB, Http, Mail};
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SportNeutralFacilitiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.clubunio' => ['driver' => 'sqlite', 'database' => ':memory:']]);
        DB::purge('clubunio');
        $db = DB::connection('clubunio');
        $db->statement("ATTACH DATABASE ':memory:' AS clubunio");
        Http::preventStrayRequests();
        Mail::fake();
        $common = 'id INTEGER PRIMARY KEY, tenant_id INTEGER, active BOOLEAN DEFAULT true, revision INTEGER DEFAULT 1, created_at TEXT, updated_at TEXT';
        foreach ([
            'tenants' => "id INTEGER PRIMARY KEY, slug TEXT, facility_planning_mode TEXT DEFAULT 'legacy'",
            'accounts' => 'id INTEGER PRIMARY KEY, active BOOLEAN DEFAULT true, api_only BOOLEAN DEFAULT false',
            'tenant_accounts' => 'tenant_id INTEGER, account_id INTEGER, role TEXT, portal_enabled BOOLEAN DEFAULT true',
            'tenant_settings' => "$common, key TEXT, area TEXT, encrypted_value TEXT, enabled BOOLEAN, label TEXT, value_type TEXT, is_secret BOOLEAN",
            'object_changes' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, actor_id INTEGER, object_type TEXT, object_id INTEGER, before_value TEXT, after_value TEXT',
            'training_options' => "$common, category TEXT, code TEXT, name TEXT, sort_order INTEGER DEFAULT 0, facility_kind TEXT",
            'facility_statuses' => "$common, code TEXT, name TEXT, blocks_training BOOLEAN, sort_order INTEGER DEFAULT 0",
            'field_availabilities' => "$common, field_id INTEGER, status_id INTEGER, note TEXT",
            'training_slots' => "$common, team_season_id INTEGER, period_id INTEGER, weekday_id INTEGER, field_id INTEGER, area_id INTEGER, room_id INTEGER, side_id INTEGER, hall TEXT, starts_at TEXT, ends_at TEXT, all_fields BOOLEAN, without_main BOOLEAN, synthetic_only BOOLEAN, website_visible BOOLEAN",
            'seasons' => "$common, name TEXT, legacy_id INTEGER, starts_on TEXT, ends_on TEXT",
            'teams' => "$common, name TEXT",
            'team_seasons' => "$common, team_id INTEGER, season_id INTEGER, label TEXT",
            'competitions' => "$common, plays_on TEXT, show_on_signage BOOLEAN, starts_at TEXT, field_id INTEGER",
            'football_clubs' => "$common, club_number INTEGER",
            'signage_notices' => "$common, shows_on TEXT",
            'pitch_areas' => "$common, field_id INTEGER, name_de TEXT, name_en TEXT, x REAL, y REAL, width REAL, height REAL",
            'pitch_training_exceptions' => "$common, training_slot_id INTEGER, original_date TEXT, date TEXT, area_id INTEGER, cancelled BOOLEAN",
            'pitch_competition_settings' => "$common, competition_id INTEGER",
            'pitch_competition_teams' => "$common, competition_id INTEGER, team_season_id INTEGER",
            'pitch_requests' => "$common, date TEXT, field_id INTEGER, status TEXT",
            'pitch_blocks' => "$common, date TEXT, field_id INTEGER, area_id INTEGER, starts_at TEXT, duration_minutes INTEGER, reason TEXT, status TEXT",
            'pitch_planning_events' => "$common",
        ] as $table => $columns) {
            $db->statement("CREATE TABLE clubunio.$table ($columns)");
        }
        $this->app->instance(PitchPlanningProjection::class, new class extends PitchPlanningProjection
        {
            public function ready(): bool { return true; }
        });
    }

    private function add(string $table, array $row): int
    {
        return DB::connection('clubunio')->table('clubunio.'.$table)->insertGetId($row);
    }

    public static function layouts(): array
    {
        return [
            'one winter hall' => [[['hall', 'Winter Hall']]],
            'two courts' => [[['court', 'Court A'], ['court', 'Court B']]],
            'four shared resources' => [[['hall', 'Shared Hall'], ['court', 'Court A'], ['field', 'Outdoor Field'], ['other', 'Studio']]],
        ];
    }

    #[DataProvider('layouts')]
    public function test_web_mobile_and_signage_share_neutral_facilities_and_winter_schedule(array $layout): void
    {
        $this->add('tenants', ['id' => 1, 'slug' => 'synthetic-neutral', 'facility_planning_mode' => 'neutral']);
        $this->add('tenants', ['id' => 2, 'slug' => 'synthetic-foreign']);
        $this->add('accounts', ['id' => 1]);
        $this->add('tenant_accounts', ['tenant_id' => 1, 'account_id' => 1, 'role' => 'admin']);
        $tenant = Tenant::findOrFail(1);
        $actor = Account::findOrFail(1);
        $open = $this->add('facility_statuses', ['tenant_id' => 1, 'name' => 'Open fixture', 'blocks_training' => false]);
        $closed = $this->add('facility_statuses', ['tenant_id' => 1, 'name' => 'Closed fixture', 'blocks_training' => true]);
        $foreignStatus = $this->add('facility_statuses', ['tenant_id' => 2, 'name' => 'Foreign', 'blocks_training' => false]);
        $this->add('training_options', ['tenant_id' => 2, 'category' => 'field', 'name' => 'Foreign facility']);
        $service = app(ContributionTrainingService::class);
        $make = fn ($category, $code, $name, $extra = []) => $service->save($actor, $tenant, TrainingOption::class, null,
            $extra + compact('category', 'code', 'name') + ['active' => true, 'sort_order' => 0]);
        try {
            $make('field', 'BAD', 'Invalid facility', ['facility_kind' => 'hall', 'initial_status_id' => $foreignStatus]);
            $this->fail('Cross-tenant status accepted');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('initial_status_id', $error->errors());
        }
        $ids = [];
        foreach ($layout as $index => [$kind, $name]) {
            $ids[] = (int) $make('field', 'F'.$index, $name, ['facility_kind' => $kind, 'initial_status_id' => $open, 'sort_order' => $index])->id;
        }
        $winter = $make('period', 'WINTER', 'Winter');
        $summer = $make('period', 'SUMMER', 'Summer');
        $weekday = $make('weekday', '2', 'Monday');
        $this->assertSame($winter->id, app(ActiveTrainingPlan::class)->period($tenant)->id);
        $season = $this->add('seasons', ['tenant_id' => 1, 'name' => 'Synthetic season', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $this->add('tenant_settings', ['tenant_id' => 1, 'key' => 'SaisonID', 'area' => 'general', 'encrypted_value' => Crypt::encryptString((string) $season), 'enabled' => true]);
        $team = function (string $name) use ($season): int {
            $id = $this->add('teams', ['tenant_id' => 1, 'name' => $name]);
            return $this->add('team_seasons', ['tenant_id' => 1, 'team_id' => $id, 'season_id' => $season, 'label' => $name]);
        };
        $football = $team('Football juniors');
        $slot = fn ($period, $field, $teamId) => $service->save($actor, $tenant, TrainingSlot::class, null, [
            'team_season_id' => $teamId, 'period_id' => $period, 'weekday_id' => $weekday->id, 'field_id' => $field,
            'starts_at' => '18:00', 'ends_at' => '19:00', 'website_visible' => true, 'active' => true,
        ]);
        $w = $slot($winter->id, $ids[0], $football);
        $s = $slot($summer->id, $ids[count($ids) === 4 ? 2 : 0], $football);
        $this->assertNull($w->area_id);
        $this->assertSame([], app(PitchWeeklyAreas::class)->trainingOptions($tenant, $ids[0]));
        if (count($ids) === 4) {
            $slot($winter->id, $ids[0], $team('Volleyball'));
            $slot($winter->id, $ids[1], $team('Tennis'));
        }

        $filters = ['from' => '2026-11-09', 'to' => '2026-11-09'];
        $pitch = app(PitchPlanningService::class);
        $board = $pitch->calendar($actor, $tenant, $filters);
        $calendar = new PitchPlanningCalendar;
        $columns = $calendar->columns($board['fields'], $calendar->days($filters['from'], 'day', $board['entries'])[0]['events']);
        $this->assertSame($ids, array_column(array_column($columns, 'field'), 'id'));
        $this->assertSame(array_column($layout, 0), array_column($board['fields'], 'facility_kind'));
        $this->assertSame($w->id, $board['entries'][0]['source_id']);
        $this->assertNotContains($s->id, array_column($board['entries'], 'source_id'));
        $this->assertSame($s->id, $pitch->calendar($actor, $tenant, $filters + ['period_id' => $summer->id])['entries'][0]['source_id']);
        if (count($ids) === 4) {
            $this->assertSame('occupancy', $board['entries'][0]['conflicts'][0]['type']);
            $this->assertSame([], $board['entries'][2]['conflicts']);
        }
        $mobile = Request::create('/calendar', 'GET', $filters);
        $mobile->attributes->set('mobile.actor', $actor);
        $mobile->attributes->set('mobile.tenant', $tenant);
        $this->assertSame($board, app(MobilePitchController::class)->calendar($mobile)->getData(true)['data']);
        $this->assertSame($ids, array_column(app(MobileFacilitiesData::class)->read($actor, $tenant)['fields'], 'field_id'));
        $this->assertSame([$w->id], array_column(app(MobileTeamsData::class)->trainings($actor, $tenant, $football), 'TrainingID'));

        $signage = fn () => app(SignageService::class)->preview($actor, $tenant, ['date' => $filters['from']]);
        $this->assertCount(count($ids), $signage()['fields']);
        $this->assertSame($layout[0][1], $signage()['trainings']->first()->field->name);
        $this->actingAs($actor, 'clubunio');
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        Filament::setTenant($tenant, isQuiet: true);
        $this->travelTo(\Carbon\CarbonImmutable::parse($filters['from'].' 10:00', 'Europe/Zurich'));
        $page = Livewire::test(PitchPlanning::class);
        $this->assertSame(count($ids), substr_count($page->html(), 'class="pitch-column"'));
        $html = view('clubunio.signage-board', $signage() + [
            'brand' => ['color' => '#164b8a', 'ink' => '#ffffff', 'logo' => '/fixture.png', 'title' => 'Synthetic club'],
            'tenant' => $tenant, 'periodId' => $winter->id, 'publicDisplay' => false,
        ])->render();
        $this->assertSame(count($ids), substr_count($html, 'class="sg-field"'));
        foreach (array_column($layout, 1) as $name) {
            $page->assertSee($name);
            $this->assertStringContainsString($name, $html);
        }
        $availability = FieldAvailability::where('field_id', $ids[0])->firstOrFail();
        $status = fn (int $id, int $rev) => app(FacilityService::class)->save($actor, $tenant, FieldAvailability::class, $availability->id,
            ['status_id' => $id, 'revision' => $rev]);
        $status($closed, 1);
        $this->assertSame('field_unavailable', $pitch->calendar($actor, $tenant, $filters)['entries'][0]['conflicts'][0]['type']);
        $this->assertTrue(app(MobileFacilitiesData::class)->read($actor, $tenant)['fields'][0]['blocks_training']);
        $this->assertNotContains($w->id, $signage()['trainings']->pluck('id'));
        $status($open, 2);
        $this->add('pitch_blocks', ['tenant_id' => 1, 'field_id' => $ids[0], 'date' => $filters['from'], 'starts_at' => '18:00',
            'duration_minutes' => 30, 'reason' => 'Synthetic closure', 'status' => 'approved']);
        $this->assertContains('block', array_column($pitch->calendar($actor, $tenant, $filters)['entries'][0]['conflicts'], 'type'));
        $this->assertNotContains($w->id, $signage()['trainings']->pluck('id'));
        $this->assertTrue(app(MobileFacilitiesData::class)->read($actor, $tenant)['fields'][0]['can_edit']);
        DB::connection('clubunio')->table('clubunio.pitch_blocks')->where('tenant_id', 1)->update(['status' => 'cancelled']);
        DB::connection('clubunio')->table('clubunio.tenant_settings')->where('tenant_id', 1)->where('key', ActiveTrainingPlan::KEY)
            ->update(['encrypted_value' => Crypt::encryptString((string) $summer->id)]);
        $this->assertSame($s->id, $pitch->calendar($actor, $tenant, $filters)['entries'][0]['source_id']);
        $this->assertSame([$s->id], array_column(app(MobileTeamsData::class)->trainings($actor, $tenant, $football), 'TrainingID'));
        $this->assertSame([$s->id], $signage()['trainings']->pluck('id')->all());
        try {
            $service->save($actor, $tenant, TrainingOption::class, $ids[0], [
                'name' => $layout[0][1], 'revision' => 1, 'facility_kind' => $layout[0][0], 'sort_order' => 0, 'active' => false,
            ]);
            $this->fail('A facility with active training was deactivated');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('active', $error->errors());
        }
    }

    public function test_sissach_always_keeps_legacy_rules(): void
    {
        $tenant = (new Tenant)->forceFill(['slug' => 'sv-sissach', 'facility_planning_mode' => 'neutral']);
        $this->assertFalse(FacilityPlanningMode::neutral($tenant));
        $this->assertArrayHasKey(0, app(PitchWeeklyAreas::class)->trainingOptions($tenant, null));
    }

    public function test_existing_tenant_activation_does_not_guess_facilities_from_free_text(): void
    {
        $this->add('tenants', ['id' => 1, 'slug' => 'existing-test']);
        $tenant = Tenant::findOrFail(1);
        $this->add('training_slots', ['tenant_id' => 1, 'hall' => 'Free-text winter hall']);
        try {
            FacilityPlanningMode::activate($tenant);
            $this->fail('An unlinked hall was accepted');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('operation', $error->errors());
        }
        $this->assertFalse(FacilityPlanningMode::neutral($tenant));
    }
}
