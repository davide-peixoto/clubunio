<?php

namespace Tests\Feature;

use App\ClubUnio\Http\TeamPlanningController;
use App\ClubUnio\Http\TrainingPlanController;
use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Competition;
use App\ClubUnio\Models\FacilityStatus;
use App\ClubUnio\Models\TeamSeason;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\AccessProfileService;
use App\ClubUnio\Services\BusKeyboxAccessService;
use App\ClubUnio\Services\BusReservationService;
use App\ClubUnio\Services\FacilityService;
use App\ClubUnio\Services\LegacyTrainingPlanImporter;
use App\ClubUnio\Services\PermissionCatalog;
use App\ClubUnio\Services\PlanningCalendarService;
use App\ClubUnio\Services\PlanningGuestService;
use App\ClubUnio\Services\PlanningSeriesService;
use App\ClubUnio\Services\SeasonAssignmentTransferService;
use App\ClubUnio\Services\SfvCompetitionService;
use App\ClubUnio\Services\SfvPlanningService;
use App\ClubUnio\Services\SignageBrandService;
use App\ClubUnio\Services\TeamAssignmentService;
use App\ClubUnio\Services\TeamPlanningService;
use App\ClubUnio\Services\TrainingPlanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SportsProfilePermissionTest extends TestCase
{
    private $db;
    private Tenant $tenant;
    private Account $admin;
    private Account $actor;
    private $profile;
    private int $team;
    private int $member;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Transaction opt-in required');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->db->statement('SET LOCAL search_path TO clubora');
        if (! Schema::connection('clubunio')->hasTable('clubora.access_profiles')) {
            (require database_path('clubunio/2026_10_16_000069_access_profiles.php'))->up();
        }
        if (! Schema::connection('clubunio')->hasColumn('clubora.tenant_accounts', 'access_profile_id')) {
            (require database_path('clubunio/2026_10_16_000070_account_access_profiles.php'))->up();
        }
        $this->db->table('account_roles')->where('code', 'profile')->update(['active' => true]);
        $this->tenant = Tenant::create(['slug' => 'sports-profile-'.Str::random(12), 'name' => 'Sports permission fixture']);
        $this->admin = $this->account('admin');
        $this->profile = app(AccessProfileService::class)->save($this->admin, $this->tenant, null, [
            'name' => 'Sports fixture', 'active' => true, 'permissions' => PermissionCatalog::readOnly(), 'reason' => 'Synthetic sports permission test',
        ]);
        $this->actor = $this->account('profile');
        $this->db->table('tenant_accounts')->where('account_id', $this->actor->id)->update(['access_profile_id' => $this->profile->id]);
        $season = $this->db->table('seasons')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => 'Fixture season']);
        $team = $this->db->table('teams')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => 'Fixture team']);
        $this->team = $this->db->table('team_seasons')->insertGetId(['tenant_id' => $this->tenant->id, 'season_id' => $season, 'team_id' => $team, 'label' => 'Fixture team']);
        $this->member = $this->db->table('members')->insertGetId(['tenant_id' => $this->tenant->id, 'first_name' => 'Sports', 'last_name' => 'Fixture']);
        $this->db->table('account_member_links')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $this->actor->id, 'member_id' => $this->member, 'kind' => 'self', 'state' => 'approved', 'source' => 'test']);
        $this->db->table('team_staff')->insert(['tenant_id' => $this->tenant->id, 'team_season_id' => $this->team, 'member_id' => $this->member]);
        TeamPlanningService::seedOptions($this->tenant->id);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->transactionLevel()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    private function account(string $role): Account
    {
        $actor = new Account;
        $actor->forceFill(['username' => Str::random(20), 'display_name' => 'Synthetic sports actor', 'password' => bcrypt('fixture'), 'active' => true])->save();
        $this->db->table('tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $actor->id, 'role' => $role, 'portal_enabled' => false,
            'access_profile_id' => $role === 'profile' ? $this->profile->id : null]);
        return $actor;
    }

    private function grant(array $permissions): void
    {
        $this->profile->forceFill(['permissions' => $permissions])->save();
    }

    private function denied(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Denied operation succeeded');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
    }

    private function eventInput(): array
    {
        return ['team_season_id' => $this->team, 'title' => 'Fixture event', 'kind' => 'TRAINING', 'mode' => 'ANMELDEN', 'status' => 'AKTIV',
            'starts_at' => now()->addDays(10)->format('Y-m-d').'T18:00', 'ends_at' => now()->addDays(10)->format('Y-m-d').'T20:00',
            'deadline_at' => now()->addDays(9)->format('Y-m-d').'T18:00'];
    }

    private function renderPlanning(string $name, array $data = []): string
    {
        $service = app(TeamPlanningService::class);
        return view('clubunio.planning.'.$name, $data + [
            'actor' => $this->actor, 'tenant' => $this->tenant,
            'teams' => $service->teams($this->admin, $this->tenant)->get(),
            'managed' => [$this->team], 'own' => collect(), 'colors' => [],
            'options' => $service->options($this->tenant), 'errors' => new \Illuminate\Support\ViewErrorBag,
        ])->render();
    }

    public function test_planning_render_actions_are_independent_and_keep_ownership_and_locks(): void
    {
        $service = app(TeamPlanningService::class);
        $id = $service->saveEvent($this->admin, $this->tenant, null, $this->eventInput());
        $record = $service->event($this->admin, $this->tenant, $id);
        $member = (object) ['id' => $this->member, 'display_name' => 'Fixture member'];
        $data = ['record' => $record, 'manager' => true, 'roster' => collect([$member]), 'parts' => collect(),
            'ownIds' => [$this->member], 'locked' => false, 'invited' => [$this->member], 'search' => '',
            'candidates' => collect([(object) ['id' => 999, 'first_name' => 'Candidate', 'last_name' => 'Fixture']]),
            'guests' => collect([(object) ['id' => 999, 'revision' => 1, 'first_name' => 'Guest', 'last_name' => 'Fixture',
                'status' => 'OFFEN', 'attendance' => 'OFFEN', 'comment' => '']])];
        $actions = ['update' => 'Termin bearbeiten', 'create' => 'Gastspieler hinzufügen',
            'deactivate' => 'Gast entfernen', 'send' => 'aria-label="Einladen"', 'cancel' => 'Einladung entfernen'];
        foreach ([null, ...array_keys($actions), null] as $allowed) {
            $this->grant(array_filter(['planning.view', $allowed ? 'planning.'.$allowed : null]));
            $html = $this->renderPlanning('show', $data);
            foreach ($actions as $function => $label) {
                if ($function === $allowed) {
                    $this->assertStringContainsString($label, $html);
                } else {
                    $this->assertStringNotContainsString($label, $html);
                }
            }
        }
        $this->grant(PermissionCatalog::keys());
        $restricted = array_replace($data, ['manager' => false, 'ownIds' => []]);
        $this->assertStringNotContainsString('Teilnahme speichern', $this->renderPlanning('show', $restricted));
        $restricted['ownIds'] = [$this->member];
        $this->assertStringContainsString('Teilnahme speichern', $this->renderPlanning('show', $restricted));
        $restricted['locked'] = true;
        $this->assertStringNotContainsString('Teilnahme speichern', $this->renderPlanning('show', $restricted));
        $restricted['actor'] = $this->admin;
        $restricted['locked'] = false;
        $this->assertStringContainsString('Teilnahme speichern', $this->renderPlanning('show', $restricted));
    }

    public function test_absence_render_requires_capability_and_existing_ownership(): void
    {
        $member = (object) ['id' => $this->member, 'display_name' => 'Fixture', 'first_name' => 'Fixture', 'last_name' => 'Member'];
        $absence = (object) ['id' => 999, 'member_id' => $this->member, 'revision' => 1, 'kind' => 'ABWESEND',
            'starts_on' => '2026-10-20', 'ends_on' => '2026-10-21', 'note' => 'Fixture absence'];
        $data = ['record' => null, 'own' => collect([$member]), 'members' => collect([$this->member => $member]),
            'absences' => new \Illuminate\Pagination\LengthAwarePaginator([$absence], 1, 25)];
        foreach ([null, 'create', 'update', 'deactivate'] as $allowed) {
            $this->grant(array_filter(['planning.view', $allowed ? 'planning.'.$allowed : null]));
            $html = $this->renderPlanning('absences', $data);
            foreach (['create' => 'Absenz erfassen', 'update' => 'aria-label="Bearbeiten"', 'deactivate' => 'Absenz löschen'] as $function => $label) {
                if ($allowed === $function) {
                    $this->assertStringContainsString($label, $html);
                } else {
                    $this->assertStringNotContainsString($label, $html);
                }
            }
        }
        $this->grant(['planning.view', 'planning.create']);
        $this->assertStringNotContainsString('class="planning-form"', $this->renderPlanning('absences', array_replace($data, ['record' => $absence])));
        $this->grant(['planning.view', 'planning.update']);
        $this->assertStringContainsString('class="planning-form"', $this->renderPlanning('absences', array_replace($data, ['record' => $absence])));
        $this->grant(PermissionCatalog::keys());
        $html = $this->renderPlanning('absences', array_replace($data, ['own' => collect()]));
        $this->assertStringNotContainsString('Absenz erfassen', $html);
        $this->assertStringNotContainsString('aria-label="Bearbeiten"', $html);
        $this->assertStringNotContainsString('Absenz löschen', $html);
        $html = $this->renderPlanning('absences', $data + ['actor' => $this->admin]);
        $this->assertStringContainsString('Absenz erfassen', $html);
        $this->assertStringContainsString('Absenz löschen', $html);
    }

    public function test_planning_render_forms_lifecycle_options_and_series(): void
    {
        $service = app(TeamPlanningService::class);
        $id = $service->saveEvent($this->admin, $this->tenant, null, $this->eventInput());
        $record = $service->event($this->admin, $this->tenant, $id);
        $this->grant(['planning.view']);
        foreach (['form' => ['record' => null], 'series' => ['record' => $record, 'preview' => null], 'options' => []] as $name => $data) {
            $this->assertStringNotContainsString('method="post"', $this->renderPlanning($name, $data));
        }
        $this->grant(['planning.view', 'planning.create']);
        $this->assertStringContainsString('method="post"', $this->renderPlanning('form', ['record' => null]));
        $this->assertStringNotContainsString('method="post"', $this->renderPlanning('form', ['record' => $record]));
        $this->assertStringContainsString('Neue Auswahl', $this->renderPlanning('options'));
        $this->grant(['planning.view', 'planning.update']);
        $this->assertStringNotContainsString('value="ABGESAGT"', $this->renderPlanning('form', ['record' => $record]));
        $this->assertStringNotContainsString('name="cancel"', $this->renderPlanning('series', ['record' => $record, 'preview' => null]));
        $html = $this->renderPlanning('options');
        $this->assertStringContainsString('disabled', $html);
        $this->assertStringNotContainsString('Neue Auswahl', $html);
        $this->grant(['planning.view', 'planning.update', 'planning.cancel', 'planning.deactivate', 'planning.restore']);
        $this->assertStringContainsString('value="ABGESAGT"', $this->renderPlanning('form', ['record' => $record]));
        $this->assertStringContainsString('name="cancel"', $this->renderPlanning('series', ['record' => $record, 'preview' => null]));
        $this->assertStringNotContainsString('disabled', $this->renderPlanning('options'));
        $record->status = 'ABGESAGT';
        $this->grant(['planning.view', 'planning.update']);
        $this->assertStringNotContainsString('value="AKTIV"', $this->renderPlanning('form', ['record' => $record]));
        $this->grant(['planning.view', 'planning.update', 'planning.restore']);
        $this->assertStringContainsString('value="AKTIV"', $this->renderPlanning('form', ['record' => $record]));
    }

    public function test_planning_render_calendar_index_and_sfv_actions(): void
    {
        $calendar = app(PlanningCalendarService::class)->calendar($this->admin, $this->tenant, []);
        $index = ['events' => new \Illuminate\Pagination\LengthAwarePaginator([], 0, 25), 'counts' => collect(), 'filters' => []];
        $match = (object) ['id' => 999, 'match_id' => 999, 'starts_at' => now(), 'title' => 'Fixture match', 'location' => '', 'home' => true, 'state' => 'Scheduled'];
        $sfv = ['matches' => new \Illuminate\Pagination\LengthAwarePaginator([$match], 1, 25),
            'filters' => ['from' => '2026-10-01', 'to' => '2026-12-01'], 'imported' => collect()];
        $this->grant(['planning.view']);
        $this->assertStringNotContainsString('Kalender exportieren', $this->renderPlanning('calendar', $calendar));
        $this->assertStringNotContainsString('Termin erstellen', $this->renderPlanning('index', $index));
        $this->assertStringNotContainsString('Vom SFV aktualisieren', $this->renderPlanning('sfv', $sfv));
        $this->assertStringNotContainsString('>Übernehmen</a>', $this->renderPlanning('sfv', $sfv));
        $this->grant(['planning.view', 'planning.create', 'planning.export', 'planning.sync', 'bus.view']);
        $this->assertStringContainsString('Termin erstellen', $this->renderPlanning('index', $index));
        $this->assertStringNotContainsString('Termin erstellen', $this->renderPlanning('index', $index + ['managed' => []]));
        $this->assertStringContainsString('Kalender exportieren', $this->renderPlanning('calendar', $calendar));
        $calendar['filters']['buses'] = 1;
        $this->assertStringNotContainsString('Kalender exportieren', $this->renderPlanning('calendar', $calendar));
        $this->assertStringContainsString('Vom SFV aktualisieren', $this->renderPlanning('sfv', $sfv));
        $this->assertStringContainsString('>Übernehmen</a>', $this->renderPlanning('sfv', $sfv));
        $this->grant(['planning.view', 'planning.export', 'bus.view', 'bus.export']);
        $this->assertStringContainsString('Kalender exportieren', $this->renderPlanning('calendar', $calendar));
        $this->assertStringNotContainsString('>Übernehmen</a>', $this->renderPlanning('sfv', $sfv));
    }

    public function test_read_only_never_grants_mutations_or_portal_fallback(): void
    {
        $a = $this->actor;
        $t = $this->tenant;
        $this->assertTrue($a->canAccessTenant($t));
        $this->assertFalse($a->hasUnrestrictedBackofficeAccess($t));
        $this->assertSame(1, app(TeamPlanningService::class)->teams($a, $t)->count());
        $this->assertSame(0, app(TeamPlanningService::class)->teams($a, $t, true)->count());
        $this->assertSame(1, app(TrainingPlanService::class)->teams($a, $t)->count());
        $this->assertSame(1, app(BusReservationService::class)->teams($a, $t)->count());
        // An approved self link must not turn a denied profile action into a portal action.
        $operations = [
            fn () => app(TeamPlanningService::class)->saveEvent($a, $t, null, []),
            fn () => app(TeamPlanningService::class)->saveEvent($a, $t, 1, []),
            fn () => app(TeamPlanningService::class)->createSeries($a, $t, []),
            fn () => app(TeamPlanningService::class)->participate($a, $t, 1, $this->member, []),
            fn () => app(TeamPlanningService::class)->saveAbsence($a, $t, null, []),
            fn () => app(TeamPlanningService::class)->saveAbsence($a, $t, 1, [], true),
            fn () => app(TrainingPlanService::class)->generate($a, $t, [], (string) Str::uuid()),
            fn () => app(TrainingPlanService::class)->save($a, $t, 1, 1, [], false),
            fn () => app(TrainingPlanService::class)->copy($a, $t, 1, 1, (string) Str::uuid()),
            fn () => app(TrainingPlanService::class)->review($a, $t, 1, 1, (string) Str::uuid()),
            fn () => app(TrainingPlanService::class)->archive($a, $t, 1, 1, true),
            fn () => app(TrainingPlanService::class)->archive($a, $t, 1, 1, false),
            fn () => app(BusReservationService::class)->create($a, $t, []),
            fn () => app(BusReservationService::class)->reschedule($a, $t, 1, []),
            fn () => app(BusReservationService::class)->decide($a, $t, 1, 1, 'GENEHMIGT', null),
            fn () => app(BusReservationService::class)->decide($a, $t, 1, 1, 'STORNIERT', null),
            fn () => app(BusReservationService::class)->assignImported($a, $t, 1, []),
            fn () => app(BusReservationService::class)->saveOption($a, $t, []),
            fn () => app(BusKeyboxAccessService::class)->invalidate($a, $t, 1),
            fn () => app(TeamAssignmentService::class)->save($a, $t, TeamSeason::class, null, []),
            fn () => app(FacilityService::class)->save($a, $t, FacilityStatus::class, null, []),
            fn () => app(SfvCompetitionService::class)->applyBatch($a, $t, []),
            fn () => app(SfvCompetitionService::class)->apply($a, $t, []),
            fn () => app(SfvPlanningService::class)->refresh($a, $t, []),
            fn () => app(SfvPlanningService::class)->refreshNow($a, $t, []),
            fn () => app(SfvPlanningService::class)->import($a, $t, 1, []),
            fn () => app(LegacyTrainingPlanImporter::class)->stage($a, $t),
            fn () => app(LegacyTrainingPlanImporter::class)->import($a, $t, 1, []),
            fn () => app(LegacyTrainingPlanImporter::class)->importHistorical($a, $t, true),
            fn () => app(SeasonAssignmentTransferService::class)->apply($a, $t, 1, 2, ''),
        ];
        foreach ($operations as $operation) {
            $this->denied($operation);
        }
        $this->assertSame(0, $this->db->table('training_plan_requests')->where('tenant_id', $t->id)->count());
    }

    public function test_create_update_cancel_and_revocation_are_independent(): void
    {
        $service = app(TeamPlanningService::class);
        $this->grant(['planning.view', 'planning.create']);
        $id = $service->saveEvent($this->actor, $this->tenant, null, $this->eventInput());
        $this->assertGreaterThan(0, $id);
        $this->denied(fn () => $service->saveEvent($this->actor, $this->tenant, $id, $this->eventInput() + ['revision' => 1]));
        $this->grant(['planning.view', 'planning.update']);
        $service->saveEvent($this->actor, $this->tenant, $id, $this->eventInput() + ['revision' => 1]);
        $cancel = array_replace($this->eventInput(), ['revision' => 2, 'status' => 'ABGESAGT']);
        $this->denied(fn () => $service->saveEvent($this->actor, $this->tenant, $id, $cancel));
        $this->grant(['planning.view', 'planning.update', 'planning.cancel']);
        $service->saveEvent($this->actor, $this->tenant, $id, $cancel);
        $this->assertSame('ABGESAGT', $this->db->table('team_events')->where('id', $id)->value('status'));
        $this->grant(['planning.view']);
        $this->denied(fn () => $service->saveEvent($this->actor, $this->tenant, $id, $cancel));
        $this->profile->forceFill(['active' => false])->save();
        $this->denied(fn () => $service->teams($this->actor, $this->tenant));
    }

    public function test_facility_status_changes_require_separate_lifecycle_rights(): void
    {
        $service = app(FacilityService::class);
        $this->grant(['facilities.view', 'facilities.create']);
        $input = ['code' => 'fixture', 'name' => 'Fixture', 'active' => true, 'blocks_training' => false, 'sort_order' => 1];
        $row = $service->save($this->actor, $this->tenant, FacilityStatus::class, null, $input);
        unset($input['code']);
        $input += ['revision' => 1];
        $input['active'] = false;
        $this->grant(['facilities.view', 'facilities.update']);
        $this->denied(fn () => $service->save($this->actor, $this->tenant, FacilityStatus::class, $row->id, $input));
        $this->grant(['facilities.view', 'facilities.update', 'facilities.deactivate']);
        $row = $service->save($this->actor, $this->tenant, FacilityStatus::class, $row->id, $input);
        $this->assertFalse($row->active);
        $input['active'] = true;
        $input['revision'] = 2;
        $this->denied(fn () => $service->save($this->actor, $this->tenant, FacilityStatus::class, $row->id, $input));
        $this->grant(['facilities.view', 'facilities.update', 'facilities.restore']);
        $this->assertTrue($service->save($this->actor, $this->tenant, FacilityStatus::class, $row->id, $input)->active);
    }

    public function test_training_archive_restore_and_download_do_not_follow_view_or_ownership(): void
    {
        $service = app(TrainingPlanService::class);
        $id = $this->db->table('training_plans')->insertGetId(['tenant_id' => $this->tenant->id, 'owner_id' => $this->actor->id,
            'team_season_id' => $this->team, 'title' => 'Fixture', 'input_json' => '{}', 'plan_json' => '{}']);
        $this->grant(['training.view']);
        $this->assertSame($id, $service->get($this->actor, $this->tenant, $id)->id);
        $this->denied(fn () => $service->get($this->actor, $this->tenant, $id, true));
        $this->denied(fn () => $service->archive($this->actor, $this->tenant, $id, 1, true));
        $request = Request::create('/fixture');
        $request->setUserResolver(fn () => $this->actor);
        $this->denied(fn () => app(TrainingPlanController::class)->pdf($request, $this->tenant, $id));
        $this->grant(['training.view', 'training.deactivate']);
        $service->archive($this->actor, $this->tenant, $id, 1, true);
        $this->denied(fn () => $service->archive($this->actor, $this->tenant, $id, 2, false));
        $this->grant(['training.view', 'training.restore']);
        $service->archive($this->actor, $this->tenant, $id, 2, false);
        $this->assertNull($this->db->table('training_plans')->where('id', $id)->value('archived_at'));
        $other = Tenant::create(['slug' => 'foreign-'.Str::random(12), 'name' => 'Other']);
        $this->denied(fn () => $service->visible($this->actor, $other));
    }

    public function test_admin_settings_and_controller_side_effects_remain_guarded(): void
    {
        $request = Request::create('/fixture', 'POST');
        $request->setUserResolver(fn () => $this->actor);
        $controller = app(TeamPlanningController::class);
        $this->denied(fn () => $controller->calendarExport($request, $this->tenant));
        $this->denied(fn () => $controller->guest($request, $this->tenant, 1));
        $this->denied(fn () => $controller->invite($request, $this->tenant, 1, $this->member));
        $this->denied(fn () => $controller->saveOption($request, $this->tenant));
        $this->grant(PermissionCatalog::keys());
        $this->denied(fn () => app(BusKeyboxAccessService::class)->configure($this->actor, $this->tenant, null, []));
        $this->denied(fn () => app(SignageBrandService::class)->save($this->actor, $this->tenant, []));
    }

    public function test_bus_creation_approval_and_keybox_do_not_inherit_view(): void
    {
        $service = app(BusReservationService::class);
        foreach (['ANGEFRAGT', 'GENEHMIGT', 'ABGELEHNT', 'STORNIERT'] as $code) {
            $this->db->table('bus_statuses')->insert(['tenant_id' => $this->tenant->id, 'code' => $code, 'name' => $code]);
        }
        $bus = $this->db->table('buses')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => 'Fixture bus']);
        $this->grant(['bus.view', 'bus.create']);
        $input = ['bus_id' => $bus, 'team_season_id' => $this->team, 'member_id' => $this->member,
            'starts_at' => now()->addDays(10)->format('Y-m-d').'T18:00', 'ends_at' => now()->addDays(10)->format('Y-m-d').'T20:00', 'status' => 'ANGEFRAGT'];
        $this->denied(fn () => $service->create($this->actor, $this->tenant, array_replace($input, ['status' => 'GENEHMIGT'])));
        $id = $service->create($this->actor, $this->tenant, $input);
        $this->assertSame('ANGEFRAGT', $this->db->table('bus_reservations')->where('id', $id)->value('status'));
        $this->denied(fn () => $service->decide($this->actor, $this->tenant, $id, 1, 'GENEHMIGT', null));
        $this->grant(['bus.view', 'bus.approve']);
        $service->decide($this->actor, $this->tenant, $id, 1, 'GENEHMIGT', null);
        $this->grant(['bus.view']);
        $row = $service->visible($this->actor, $this->tenant)->where('id', $id)->first();
        $this->assertFalse($service->canReschedule($this->actor, $this->tenant, $row));
        $this->denied(fn () => app(BusKeyboxAccessService::class)->issueSimulation($this->actor, $this->tenant, $id, 2));
        $this->denied(fn () => $service->decide($this->actor, $this->tenant, $id, 2, 'STORNIERT', null));
        $this->grant(['bus.view', 'bus.cancel']);
        $service->decide($this->actor, $this->tenant, $id, 2, 'STORNIERT', null);
        $this->assertSame('STORNIERT', $this->db->table('bus_reservations')->where('id', $id)->value('status'));
    }

    public function test_calendar_export_requires_export_and_separate_bus_export_directly_and_in_controller(): void
    {
        $service = app(PlanningCalendarService::class);
        $event = app(TeamPlanningService::class)->saveEvent($this->admin, $this->tenant, null, $this->eventInput());
        $input = ['date' => substr($this->eventInput()['starts_at'], 0, 10)];
        $request = Request::create('/fixture', 'GET', $input);
        $request->setUserResolver(fn () => $this->actor);
        $this->grant(['planning.view', 'planning.download', 'bus.view']);
        $this->assertSame($event, $service->calendar($this->actor, $this->tenant, $input)['events']->first()->id);
        $this->denied(fn () => $service->export($this->actor, $this->tenant, $input));
        $this->denied(fn () => app(TeamPlanningController::class)->calendarExport($request, $this->tenant));
        $this->grant(['planning.view', 'planning.export', 'bus.view']);
        $this->assertStringContainsString('BEGIN:VCALENDAR', $service->export($this->actor, $this->tenant, $input));
        $this->assertStringContainsString('Fixture event', app(TeamPlanningController::class)->calendarExport($request, $this->tenant)->getContent());
        $busInput = $input + ['buses' => 1];
        $busRequest = Request::create('/fixture', 'GET', $busInput);
        $busRequest->setUserResolver(fn () => $this->actor);
        $this->denied(fn () => $service->export($this->actor, $this->tenant, $busInput));
        $this->denied(fn () => app(TeamPlanningController::class)->calendarExport($busRequest, $this->tenant));
        $this->db->table('bus_statuses')->insert(['tenant_id' => $this->tenant->id, 'code' => 'GENEHMIGT', 'name' => 'Approved fixture']);
        $bus = $this->db->table('buses')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => 'Export bus']);
        $this->db->table('bus_reservations')->insert(['tenant_id' => $this->tenant->id, 'bus_id' => $bus,
            'team_season_id' => $this->team, 'member_id' => $this->member, 'status' => 'GENEHMIGT',
            'starts_at' => $input['date'].' 18:00:00+02', 'ends_at' => $input['date'].' 20:00:00+02']);
        $this->grant(['planning.view', 'planning.export', 'bus.view', 'bus.export']);
        $this->assertStringContainsString('Export bus', $service->export($this->actor, $this->tenant, $busInput));
        $this->assertStringContainsString('Export bus', app(TeamPlanningController::class)->calendarExport($busRequest, $this->tenant)->getContent());
        $this->grant(['planning.view', 'planning.export', 'bus.view']);
        $this->denied(fn () => $service->export($this->actor, $this->tenant, $busInput));
        $this->grant(['planning.view', 'bus.view', 'bus.export']);
        $this->denied(fn () => $service->export($this->actor, $this->tenant, $busInput));
    }

    public function test_direct_guest_and_invitation_actions_require_their_own_capabilities(): void
    {
        $service = app(PlanningGuestService::class);
        $event = app(TeamPlanningService::class)->saveEvent($this->admin, $this->tenant, null, $this->eventInput());
        $input = ['first_name' => 'Guest', 'last_name' => 'Fixture', 'status' => 'DABEI', 'attendance' => 'OFFEN'];
        $candidate = $this->db->table('members')->insertGetId(['tenant_id' => $this->tenant->id, 'first_name' => 'Invited', 'last_name' => 'Fixture']);
        $this->grant(['planning.view', 'planning.update']);
        $this->denied(fn () => $service->save($this->actor, $this->tenant, $event, null, $input));
        $this->denied(fn () => $service->invite($this->actor, $this->tenant, $event, $candidate));
        $this->grant(['planning.view', 'planning.create']);
        $service->save($this->actor, $this->tenant, $event, null, $input);
        $guest = $this->db->table('team_guests')->where('event_id', $event)->first();
        $this->assertSame('Guest', $guest->first_name);
        $this->denied(fn () => $service->save($this->actor, $this->tenant, $event, $guest->id, $input + ['revision' => 1]));
        $this->grant(['planning.view', 'planning.update']);
        $service->save($this->actor, $this->tenant, $event, $guest->id, $input + ['revision' => 1]);
        $this->denied(fn () => $service->save($this->actor, $this->tenant, $event, $guest->id, ['revision' => 2], true));
        $this->grant(['planning.view', 'planning.deactivate']);
        $service->save($this->actor, $this->tenant, $event, $guest->id, ['revision' => 2], true);
        $this->assertNotNull($this->db->table('team_guests')->where('id', $guest->id)->value('deleted_at'));
        $this->grant(['planning.view', 'planning.send']);
        $service->invite($this->actor, $this->tenant, $event, $candidate);
        $this->assertSame(1, $this->db->table('team_invitations')->where('event_id', $event)->where('member_id', $candidate)->count());
        $this->denied(fn () => $service->invite($this->actor, $this->tenant, $event, $candidate, true));
        $this->grant(['planning.view', 'planning.cancel']);
        $service->invite($this->actor, $this->tenant, $event, $candidate, true);
        $this->assertSame(0, $this->db->table('team_invitations')->where('event_id', $event)->where('member_id', $candidate)->count());
        // Revocation also blocks otherwise idempotent invitations of existing roster members.
        $this->denied(fn () => $service->invite($this->actor, $this->tenant, $event, $this->member));
        $this->grant(['planning.view']);
        $this->denied(fn () => $service->invite($this->actor, $this->tenant, $event, $candidate, true));
    }

    public function test_published_competition_changes_require_send_but_no_ops_and_private_edits_do_not(): void
    {
        $service = app(FacilityService::class);
        $input = ['active' => true, 'plays_on' => now()->addDays(10)->toDateString(), 'starts_at' => '18:00',
            'host_name' => 'Home fixture', 'guest_name' => 'Away fixture', 'show_on_signage' => true];
        $row = $service->save($this->admin, $this->tenant, Competition::class, null, $input);
        $this->grant(['competitions.view', 'competitions.create', 'competitions.update']);
        $row = $service->save($this->actor, $this->tenant, Competition::class, $row->id, $input + ['revision' => 1]);
        $this->assertSame(2, (int) $row->revision);
        $change = array_replace($input, ['host_name' => 'Changed home', 'revision' => 2]);
        $this->denied(fn () => $service->save($this->actor, $this->tenant, Competition::class, $row->id, $change));
        $this->denied(fn () => $service->save($this->actor, $this->tenant, Competition::class, $row->id, array_replace($change, ['show_on_signage' => false])));
        $this->denied(fn () => $service->save($this->actor, $this->tenant, Competition::class, null, $input));
        $this->assertSame('Home fixture', $row->fresh()->host_name);
        $this->assertSame(2, (int) $row->fresh()->revision);
        $private = array_replace($input, ['show_on_signage' => false]);
        $draft = $service->save($this->actor, $this->tenant, Competition::class, null, $private);
        $draft = $service->save($this->actor, $this->tenant, Competition::class, $draft->id, array_replace($private, ['host_name' => 'Private change', 'revision' => 1]));
        $this->assertSame('Private change', $draft->host_name);
        $this->denied(fn () => $service->save($this->actor, $this->tenant, Competition::class, $draft->id, $input + ['revision' => 2]));
        $this->grant(['competitions.view', 'competitions.update', 'signage.view', 'signage.send']);
        $row = $service->save($this->actor, $this->tenant, Competition::class, $row->id, $change);
        $this->assertSame('Changed home', $row->host_name);
        $office = $this->account('office');
        $row = $service->save($office, $this->tenant, Competition::class, $row->id, array_replace($input, ['host_name' => 'Office change', 'revision' => 3]));
        $this->assertSame('Office change', $row->host_name);
    }

    public function test_unknown_operations_are_rejected_before_portal_fallback(): void
    {
        $trainer = $this->account('trainer');
        $this->db->table('tenant_accounts')->where('account_id', $trainer->id)->update(['portal_enabled' => true]);
        $this->db->table('account_member_links')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $trainer->id,
            'member_id' => $this->member, 'kind' => 'self', 'state' => 'approved', 'source' => 'test']);
        foreach ([TrainingPlanService::class, TeamPlanningService::class, BusReservationService::class] as $class) {
            $service = app($class);
            $service->authorizeOperation($trainer, $this->tenant, 'view');
            foreach (['', 'unknown', 'upload', 'VIEW'] as $function) {
                $this->denied(fn () => $service->authorizeOperation($trainer, $this->tenant, $function));
            }
            $this->denied(fn () => $service->authorizeOperation($this->admin, $this->tenant, 'unknown'));
            $this->denied(fn () => $service->authorizeOperation($this->actor, $this->tenant, 'unknown'));
        }
    }

    public function test_series_checks_direct_permissions_even_for_no_op_and_revoked_previews(): void
    {
        $planning = app(TeamPlanningService::class);
        $service = app(PlanningSeriesService::class);
        $events = $planning->createSeries($this->admin, $this->tenant, $this->eventInput() + ['repeat_count' => 2]);
        $input = ['from' => substr($this->eventInput()['starts_at'], 0, 10), 'shift_minutes' => 0, 'cancel' => true];
        $this->grant(['planning.view']);
        $this->denied(fn () => $service->preview($this->actor, $this->tenant, $events[0], $input));
        $this->denied(fn () => $service->apply($this->actor, $this->tenant, $events[0], $input, ''));
        $this->grant(['planning.view', 'planning.update']);
        $this->denied(fn () => $service->preview($this->actor, $this->tenant, $events[0], $input));
        $this->grant(['planning.view', 'planning.update', 'planning.cancel']);
        $emptyInput = array_replace($input, ['from' => now()->addYears(2)->toDateString()]);
        $empty = $service->preview($this->actor, $this->tenant, $events[0], $emptyInput);
        $this->assertSame([], $empty['changes']);
        $preview = $service->preview($this->actor, $this->tenant, $events[0], $input);
        $this->assertCount(2, $preview['changes']);
        $this->grant(['planning.view', 'planning.update']);
        $this->denied(fn () => $service->apply($this->actor, $this->tenant, $events[0], $emptyInput, $empty['fingerprint']));
        $this->denied(fn () => $service->apply($this->actor, $this->tenant, $events[0], $input, $preview['fingerprint']));
        $this->assertSame(2, $this->db->table('team_events')->whereIn('id', $events)->where('status', 'AKTIV')->count());
        $this->grant(['planning.view', 'planning.update', 'planning.cancel']);
        $this->assertSame(2, $service->apply($this->actor, $this->tenant, $events[0], $input, $preview['fingerprint']));
    }
}
