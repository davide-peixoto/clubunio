<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\PlanningCalendarService;
use App\ClubUnio\Services\PlanningGuestService;
use App\ClubUnio\Services\PlanningSeriesService;
use App\ClubUnio\Services\SfvPlanningService;
use App\ClubUnio\Services\TeamPlanningService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Sabre\VObject\Reader;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TeamPlanningTest extends TestCase
{
    private $db;

    private Tenant $tenant;

    private Account $admin;

    private Account $player;

    private Account $trainer;

    private int $member;

    private int $otherMember;

    private int $team;

    private TeamPlanningService $service;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Opt-in rolled-back DB tests');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->db->statement('SET LOCAL search_path TO clubora');
        $this->tenant = Tenant::create(['slug' => 'planning-'.bin2hex(random_bytes(6)), 'name' => 'Planning fixture']);
        $this->service = app(TeamPlanningService::class);
        TeamPlanningService::seedOptions($this->tenant->id);
        $this->admin = $this->account('admin');
        $this->player = $this->account('member');
        $this->trainer = $this->account('trainer');
        $this->member = $this->db->table('members')->insertGetId(['tenant_id' => $this->tenant->id, 'first_name' => 'Player', 'last_name' => 'Fixture', 'birth_date' => '2012-01-01']);
        $this->otherMember = $this->db->table('members')->insertGetId(['tenant_id' => $this->tenant->id, 'first_name' => 'Other', 'last_name' => 'Fixture']);
        foreach ([[$this->player, $this->member], [$this->trainer, $this->otherMember]] as [$actor,$member]) {
            $this->db->table('account_member_links')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $actor->id, 'member_id' => $member, 'kind' => 'self', 'state' => 'approved', 'source' => 'test']);
        }
        $season = $this->db->table('seasons')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => 'Fixture season']);
        $team = $this->db->table('teams')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => 'Fixture team']);
        $this->team = $this->db->table('team_seasons')->insertGetId(['tenant_id' => $this->tenant->id, 'team_id' => $team, 'season_id' => $season, 'label' => 'Fixture team / season']);
        $this->db->table('team_players')->insert(['tenant_id' => $this->tenant->id, 'team_season_id' => $this->team, 'member_id' => $this->member]);
        $this->db->table('team_staff')->insert(['tenant_id' => $this->tenant->id, 'team_season_id' => $this->team, 'member_id' => $this->otherMember]);
        $this->actingAs($this->admin, 'clubunio');
    }

    private function account(string $role): Account
    {
        $a = new Account;
        $a->forceFill(['username' => bin2hex(random_bytes(8)).'@example.invalid', 'display_name' => 'Fixture '.$role, 'password' => bcrypt('fixture'), 'active' => true])->save();
        $this->db->table('tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $a->id, 'role' => $role, 'portal_enabled' => true]);

        return $a;
    }

    private function input(array $overrides = []): array
    {
        return array_replace(['team_season_id' => $this->team, 'title' => 'Fixture training', 'kind' => 'TRAINING', 'mode' => 'ANMELDEN', 'status' => 'AKTIV', 'capacity' => 18,
            'starts_at' => now('Europe/Zurich')->addDays(5)->setTime(18, 0)->format('Y-m-d\TH:i'),
            'ends_at' => now('Europe/Zurich')->addDays(5)->setTime(20, 0)->format('Y-m-d\TH:i'),
            'deadline_at' => now('Europe/Zurich')->addDays(4)->setTime(18, 0)->format('Y-m-d\TH:i')], $overrides);
    }

    private function absence(array $overrides = []): array
    {
        return array_replace(['member_id' => $this->member, 'starts_on' => now('Europe/Zurich')->addDays(4)->toDateString(), 'ends_on' => now('Europe/Zurich')->addDays(6)->toDateString(), 'kind' => 'KRANK', 'note' => 'PRIVATE-HEALTH-FIXTURE'], $overrides);
    }

    private function participation(int $event): ?object
    {
        return $this->db->table('team_participations')->where('event_id', $event)->where('member_id', $this->member)->first();
    }

    public function test_calendar_views_filters_navigation_and_export(): void
    {
        $input = $this->input(['title' => 'Kalender Äöü, Test; Termin']);
        $id = $this->service->saveEvent($this->admin, $this->tenant, null, $input);
        $date = substr($input['starts_at'], 0, 10);
        $base = '/planning/'.$this->tenant->slug.'/calendar';
        foreach (['month', 'week', 'teams'] as $view) {
            $this->get($base.'?date='.$date.'&view='.$view.'&team='.$this->team.'&q=Kalender')->assertOk()->assertSee('Kalender Äöü')->assertSee('Kalender exportieren')->assertSee('team='.$this->team)->assertSee('q=Kalender');
        }
        $this->get($base.'?date='.$date.'&kind=CUP')->assertOk()->assertDontSee('Kalender Äöü');
        $this->get($base.'?date='.$date.'&season=999999999')->assertOk()->assertDontSee('Kalender Äöü');
        $this->get($base.'?date='.$date.'&team=999999999')->assertOk()->assertDontSee('Kalender Äöü');
        $this->get($base.'?view=invalid')->assertSessionHasErrors('view');
        $response = $this->get($base.'/export?date='.$date.'&view=week');
        $response->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=UTF-8');
        $ics = Reader::read($response->getContent());
        $this->assertSame('Kalender Äöü, Test; Termin', (string) $ics->VEVENT->SUMMARY);
        $this->assertSame('clubora-'.$this->tenant->id.'-'.$id.'@clubora', (string) $ics->VEVENT->UID);
        $this->assertSame(CarbonImmutable::parse($input['starts_at'], 'Europe/Zurich')->utc()->format('Ymd\THis\Z'), (string) $ics->VEVENT->DTSTART);
        $this->assertSame([], $ics->validate());
        $this->assertStringNotContainsString('ATTENDEE', $response->getContent());
    }

    public function test_calendar_conflicts_ignore_display_filter_but_respect_visibility_and_boundaries(): void
    {
        $first = $this->service->saveEvent($this->admin, $this->tenant, null, $this->input(['title' => 'Visible A']));
        $second = $this->service->saveEvent($this->admin, $this->tenant, null, $this->input(['title' => 'Visible B', 'kind' => 'CUP']));
        $this->service->saveEvent($this->admin, $this->tenant, null, $this->input(['title' => 'Cancelled', 'status' => 'ABGESAGT']));
        $boundary = $this->input(['starts_at' => $this->input()['ends_at'], 'ends_at' => CarbonImmutable::parse($this->input()['ends_at'])->addHour()->format('Y-m-d\TH:i')]);
        $this->service->saveEvent($this->admin, $this->tenant, null, $boundary);
        $service = app(PlanningCalendarService::class);
        $filter = ['date' => substr($this->input()['starts_at'], 0, 10), 'q' => 'Visible A'];
        $data = $service->calendar($this->admin, $this->tenant, $filter);
        $this->assertCount(1, $data['events']);
        $this->assertSame([$second], collect($data['conflicts'][$first])->pluck('id')->all());
        $this->db->table('team_players')->where('member_id', $this->member)->delete();
        app(PlanningGuestService::class)->invite($this->admin, $this->tenant, $first, $this->member);
        $data = $service->calendar($this->player, $this->tenant, $filter);
        $this->assertCount(1, $data['events']);
        $this->assertSame([], $data['conflicts']);
        $this->assertStringNotContainsString('Visible B', $service->export($this->player, $this->tenant, $filter));
    }

    public function test_calendar_multiday_dst_and_cancelled_export(): void
    {
        $this->service->saveEvent($this->admin, $this->tenant, null, $this->input(['title' => 'DST', 'status' => 'ABGESAGT',
            'starts_at' => '2026-10-24T23:00', 'ends_at' => '2026-10-25T04:00', 'deadline_at' => '2026-10-23T18:00']));
        $service = app(PlanningCalendarService::class);
        $data = $service->calendar($this->admin, $this->tenant, ['view' => 'week', 'date' => '2026-10-25']);
        $this->assertCount(7, $data['days']);
        $this->assertCount(1, $data['days'][5]['events']);
        $this->assertCount(1, $data['days'][6]['events']);
        $ics = Reader::read($service->export($this->admin, $this->tenant, ['date' => '2026-10-25']));
        $this->assertSame('20261024T210000Z', (string) $ics->VEVENT->DTSTART);
        $this->assertSame('20261025T030000Z', (string) $ics->VEVENT->DTEND);
        $this->assertSame('CANCELLED', (string) $ics->VEVENT->STATUS);
        $this->assertSame([], $ics->validate());
    }

    public function test_guests_capacity_privacy_revision_and_manager_authorization(): void
    {
        $id = $this->service->saveEvent($this->admin, $this->tenant, null, $this->input(['capacity' => 1]));
        $guest = app(PlanningGuestService::class);
        $input = ['first_name' => 'Guest', 'last_name' => 'Fixture', 'status' => 'DABEI', 'attendance' => 'OFFEN', 'comment' => 'PRIVATE-GUEST'];
        $guest->save($this->trainer, $this->tenant, $id, null, $input);
        $row = $this->db->table('team_guests')->where('event_id', $id)->first();
        $this->assertSame(1, $this->service->effectiveCount($this->tenant, $this->service->event($this->admin, $this->tenant, $id)));
        $this->assertStringNotContainsString('PRIVATE-GUEST', $row->comment_encrypted);
        $base = '/planning/'.$this->tenant->slug.'/events/'.$id;
        $this->actingAs($this->player, 'clubunio')->get($base)->assertOk()->assertDontSee('PRIVATE-GUEST');
        $this->post($base.'/guests', $input)->assertNotFound();
        $this->post($base.'/members/'.$this->member, ['status' => 'DABEI'])->assertSessionHasErrors('status');
        $this->actingAs($this->trainer, 'clubunio')->post($base.'/guests/'.$row->id, $input + ['revision' => 0])->assertSessionHasErrors('revision');
        $this->delete($base.'/guests/'.$row->id, ['revision' => 1])->assertRedirect();
        $this->assertNotNull($this->db->table('team_guests')->where('id', $row->id)->value('deleted_at'));
    }

    public function test_invitation_grants_only_one_event_and_reconciles_absences(): void
    {
        $this->db->table('team_players')->where('member_id', $this->member)->delete();
        $id = $this->service->saveEvent($this->admin, $this->tenant, null, $this->input());
        $other = $this->service->saveEvent($this->admin, $this->tenant, null, $this->input());
        $this->service->saveAbsence($this->player, $this->tenant, null, $this->absence());
        $guest = app(PlanningGuestService::class);
        $guest->invite($this->trainer, $this->tenant, $id, $this->member);
        $this->assertSame('ABWESEND', $this->participation($id)->status);
        $base = '/planning/'.$this->tenant->slug;
        $this->actingAs($this->player, 'clubunio')->get($base.'/events/'.$id)->assertOk();
        $this->get($base.'/events/'.$other)->assertNotFound();
        $this->get($base)->assertOk()->assertSee('/events/'.$id)->assertDontSee('/events/'.$other.'"', false);
        $guest->invite($this->trainer, $this->tenant, $id, $this->member, true);
        $this->get($base.'/events/'.$id)->assertNotFound();
    }

    public function test_series_preview_excludes_exceptions_and_detects_concurrent_edit(): void
    {
        $ids = $this->service->createSeries($this->admin, $this->tenant, $this->input(['repeat_count' => 3]));
        $this->service->saveEvent($this->admin, $this->tenant, $ids[1], $this->input(['revision' => 1, 'location' => 'Individual']));
        $series = app(PlanningSeriesService::class);
        $input = ['from' => now('Europe/Zurich')->toDateString(), 'shift_minutes' => 30, 'change_location' => 1, 'location' => 'New place'];
        $preview = $series->preview($this->trainer, $this->tenant, $ids[0], $input);
        $this->assertCount(2, $preview['changes']);
        $this->assertSame(1, $preview['skipped']);
        $this->actingAs($this->trainer, 'clubunio')->post('/planning/'.$this->tenant->slug.'/events/'.$ids[0].'/series', $input)->assertOk()->assertSee('Änderungen bestätigen');
        $this->assertSame(2, $series->apply($this->trainer, $this->tenant, $ids[0], $input, $preview['fingerprint']));
        $this->assertSame('Individual', $this->db->table('team_events')->where('id', $ids[1])->value('location'));
        try {
            $series->apply($this->trainer, $this->tenant, $ids[0], $input, $preview['fingerprint']);
            $this->fail('Stale series');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('revision', $e->errors());
        }
        $this->assertSame(2, $this->db->table('team_events')->where('id', $ids[0])->value('revision'));
    }

    public function test_sfv_live_refresh_import_revision_and_no_automatic_event_overwrite(): void
    {
        foreach (['SFV_APPLICATION_KEY' => 'fixture-key', 'SFV_APPLICATION_PASS' => 'fixture-pass', 'SFV_CLUB_ID' => '506'] as $key => $value) {
            $this->db->table('tenant_settings')->insert(['tenant_id' => $this->tenant->id, 'area' => 'sfv', 'key' => $key, 'label' => $key, 'value_type' => 'string', 'encrypted_value' => Crypt::encryptString($value), 'enabled' => true, 'is_secret' => true]);
        }
        $teamId = $this->db->table('team_seasons')->where('id', $this->team)->value('team_id');
        $this->db->table('teams')->where('id', $teamId)->update(['sfv_team_id' => 123]);
        Http::preventStrayRequests();
        Http::fake([
            '*/api/token' => Http::response('"fixture-token"', 200, ['Content-Type' => 'application/json']),
            '*/api/club/schedule*' => Http::response([['matchId' => 999, 'seasonId' => 2027, 'teamAId' => 123, 'teamBId' => 456, 'teamNameA' => 'Sissach', 'teamNameB' => 'Guest', 'matchDate' => $this->input()['starts_at'], 'stadiumPlaygroundName' => 'Sissach', 'matchStateName' => 'Angesetzt']]),
        ]);
        $sfv = app(SfvPlanningService::class);
        $range = ['from' => now('Europe/Zurich')->toDateString(), 'to' => now('Europe/Zurich')->addMonth()->toDateString()];
        $this->assertSame(1, $sfv->refresh($this->trainer, $this->tenant, $range));
        $match = $this->db->table('sfv_schedule')->where('tenant_id', $this->tenant->id)->first();
        $base = '/planning/'.$this->tenant->slug.'/sfv';
        $this->get($base)->assertOk()->assertSee('Sissach - Guest');
        $this->get($base.'/'.$match->id)->assertOk()->assertSee('sfv_revision');
        $event = $sfv->import($this->trainer, $this->tenant, $match->id, $this->input() + ['sfv_revision' => 1]);
        $this->assertSame($event, $sfv->import($this->trainer, $this->tenant, $match->id, $this->input() + ['sfv_revision' => 1, 'event_revision' => 1]));
        $sfv->refresh($this->trainer, $this->tenant, $range);
        $this->assertSame(1, $this->db->table('team_events')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(2, $this->db->table('team_events')->where('id', $event)->value('revision'));
        $this->post($base.'/'.$match->id, $this->input() + ['sfv_revision' => 1, 'event_revision' => 2])->assertSessionHasErrors('revision');
        $this->actingAs($this->player, 'clubunio')->get($base)->assertForbidden();
        Http::swap(new Factory);
        Http::fake(['*' => Http::response('SECRET-ERROR', 401)]);
        $this->actingAs($this->trainer, 'clubunio')->post($base.'/refresh', $range)->assertSessionHasErrors('sfv');
        $this->assertSame(1, $this->db->table('sfv_schedule')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_series_skips_past_and_deadline_locked_events_and_supports_cancellation(): void
    {
        $ids = $this->service->createSeries($this->admin, $this->tenant, $this->input(['repeat_count' => 3]));
        $this->db->table('team_events')->where('id', $ids[0])->update([
            'starts_at' => now()->subDays(2)->toIso8601String(), 'ends_at' => now()->subDay()->toIso8601String(),
        ]);
        $this->db->table('team_events')->where('id', $ids[1])->update(['deadline_at' => now()->subHour()->toIso8601String()]);
        $series = app(PlanningSeriesService::class);
        $input = ['from' => now('Europe/Zurich')->subWeek()->toDateString(), 'shift_minutes' => 0];
        $this->assertCount(0, $series->preview($this->trainer, $this->tenant, $ids[2], $input)['changes']);
        $input['cancel'] = 1;
        $preview = $series->preview($this->trainer, $this->tenant, $ids[2], $input);
        $this->assertCount(1, $preview['changes']);
        $this->assertSame(2, $preview['skipped']);
        $series->apply($this->trainer, $this->tenant, $ids[2], $input, $preview['fingerprint']);
        $this->assertSame(['AKTIV', 'AKTIV', 'ABGESAGT'], $this->db->table('team_events')->whereIn('id', $ids)->orderBy('id')->pluck('status')->all());
        $this->actingAs($this->player, 'clubunio')->get('/planning/'.$this->tenant->slug.'/events/'.$ids[2].'/series')->assertNotFound();
    }

    protected function tearDown(): void
    {
        if ($this->db) {
            while ($this->db->transactionLevel()) {
                $this->db->rollBack();
            }
        }
        parent::tearDown();
    }

    public function test_planning_pages_forms_filters_and_admin_authorization(): void
    {
        $id = $this->service->saveEvent($this->admin, $this->tenant, null, $this->input());
        $base = '/planning/'.$this->tenant->slug;
        $this->get($base)->assertOk()->assertSee('Fixture training');
        $this->get($base.'?team='.$this->team.'&q=training&status=AKTIV')->assertOk()->assertSee('Fixture training')->assertSee('name="team"', false);
        foreach (['/new', '/events/'.$id, '/events/'.$id.'/edit', '/absences', '/options'] as $path) {
            $this->get($base.$path)->assertOk();
        }
        $this->post($base.'/events/'.$id, $this->input(['revision' => 1, 'title' => 'Updated']))->assertRedirect();
        $this->post($base.'/events/'.$id, $this->input(['revision' => 1]))->assertSessionHasErrors('revision');
        $this->assertSame(2, $this->db->table('team_events')->where('id', $id)->value('revision'));
    }

    public function test_overlapping_absences_restore_prior_state_and_encrypted_comment(): void
    {
        $id = $this->service->saveEvent($this->admin, $this->tenant, null, $this->input());
        $this->service->participate($this->player, $this->tenant, $id, $this->member, ['status' => 'DABEI', 'comment' => 'PRIVATE-COMMENT']);
        $first = $this->service->saveAbsence($this->player, $this->tenant, null, $this->absence());
        $second = $this->service->saveAbsence($this->player, $this->tenant, null, $this->absence());
        $this->assertSame('ABWESEND', $this->participation($id)->status);
        $this->service->saveAbsence($this->player, $this->tenant, $first['id'], ['revision' => 1], true);
        $this->assertSame('ABWESEND', $this->participation($id)->status);
        $this->service->saveAbsence($this->player, $this->tenant, $second['id'], ['revision' => 1], true);
        $row = $this->participation($id);
        $this->assertSame('DABEI', $row->status);
        $this->assertSame('PRIVATE-COMMENT', Crypt::decryptString($row->comment_encrypted));
        $this->assertStringNotContainsString('PRIVATE', $this->db->table('object_changes')->where('tenant_id', $this->tenant->id)->get()->toJson());
    }

    public function test_new_and_moved_events_apply_absences_without_overwriting_trainer(): void
    {
        $absence = $this->service->saveAbsence($this->player, $this->tenant, null, $this->absence());
        $id = $this->service->saveEvent($this->trainer, $this->tenant, null, $this->input());
        $this->assertSame('absence', $this->participation($id)->source);
        $this->service->participate($this->trainer, $this->tenant, $id, $this->member, ['revision' => 1, 'status' => 'DABEI', 'attendance' => 'OFFEN']);
        $this->service->saveAbsence($this->player, $this->tenant, $absence['id'], $this->absence(['revision' => 1]));
        $this->assertSame('trainer', $this->participation($id)->source);
        $this->assertSame('DABEI', $this->participation($id)->status);
        $other = $this->service->saveEvent($this->admin, $this->tenant, null, $this->input());
        $this->service->saveEvent($this->admin, $this->tenant, $other, $this->input(['revision' => 1, 'starts_at' => now('Europe/Zurich')->addDays(10)->format('Y-m-d\TH:i'), 'ends_at' => now('Europe/Zurich')->addDays(10)->addHour()->format('Y-m-d\TH:i')]));
        $this->assertNull($this->participation($other));
    }

    public function test_deadline_blocks_player_but_allows_trainer_and_reports_skipped_absence(): void
    {
        $id = $this->service->saveEvent($this->admin, $this->tenant, null, $this->input(['deadline_at' => now('Europe/Zurich')->subHour()->format('Y-m-d\TH:i')]));
        try {
            $this->service->participate($this->player, $this->tenant, $id, $this->member, ['status' => 'DABEI']);
            $this->fail('Deadline bypass');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
        $result = $this->service->saveAbsence($this->player, $this->tenant, null, $this->absence());
        $this->assertSame(1, $result['skipped']);
        $this->assertNull($this->participation($id));
        $this->service->participate($this->trainer, $this->tenant, $id, $this->member, ['status' => 'DABEI', 'attendance' => 'ANWESEND']);
        $this->assertSame('DABEI', $this->participation($id)->status);
    }

    public function test_absence_permissions_parent_scope_and_privacy(): void
    {
        $result = $this->service->saveAbsence($this->player, $this->tenant, null, $this->absence());
        $this->assertTrue($this->service->absences($this->trainer, $this->tenant)->where('id', $result['id'])->exists());
        try {
            $this->service->saveAbsence($this->trainer, $this->tenant, $result['id'], $this->absence(['revision' => 1]));
            $this->fail('Trainer edited foreign absence');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $parent = $this->account('member');
        $this->db->table('account_member_links')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $parent->id, 'member_id' => $this->member, 'kind' => 'parent', 'state' => 'approved', 'source' => 'test']);
        $this->service->saveAbsence($parent, $this->tenant, $result['id'], $this->absence(['revision' => 1, 'note' => 'Parent correction']));
        $this->assertSame(2, $this->db->table('team_absences')->where('id', $result['id'])->value('revision'));
        $outsider = $this->account('member');
        $this->db->table('account_member_links')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $outsider->id, 'member_id' => $this->otherMember, 'kind' => 'self', 'state' => 'approved', 'source' => 'test']);
        $this->assertSame(0, $this->service->absences($outsider, $this->tenant)->count());
        $this->actingAs($outsider, 'clubunio');
        $this->get('/planning/'.$this->tenant->slug.'/absences')->assertOk()->assertDontSee('Parent correction');
        $this->post('/planning/'.$this->tenant->slug.'/absences/'.$result['id'], $this->absence(['revision' => 2]))->assertForbidden();
    }

    public function test_capacity_stale_participation_and_foreign_tenant_blocked(): void
    {
        $id = $this->service->saveEvent($this->admin, $this->tenant, null, $this->input(['capacity' => 1]));
        $this->service->participate($this->player, $this->tenant, $id, $this->member, ['status' => 'DABEI']);
        try {
            $this->service->participate($this->trainer, $this->tenant, $id, $this->otherMember, ['status' => 'DABEI', 'attendance' => 'OFFEN']);
            $this->fail('Capacity bypass');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
        try {
            $this->service->participate($this->player, $this->tenant, $id, $this->member, ['status' => 'ABWESEND', 'revision' => 0]);
            $this->fail('Stale update');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('revision', $e->errors());
        }
        $other = Tenant::create(['slug' => 'foreign-'.bin2hex(random_bytes(5)), 'name' => 'Foreign']);
        $this->get('/planning/'.$other->slug.'/events/'.$id)->assertForbidden();
    }

    public function test_weekly_events_keep_local_time_across_dst_and_apply_absences(): void
    {
        $ids = $this->service->createSeries($this->admin, $this->tenant, $this->input(['repeat_count' => 3,
            'starts_at' => '2026-10-20T18:00', 'ends_at' => '2026-10-20T19:30', 'deadline_at' => '2026-10-20T16:00']));
        $this->assertCount(3, $ids);
        foreach ($this->db->table('team_events')->whereIn('id', $ids)->get() as $event) {
            $this->assertSame('18:00', CarbonImmutable::parse($event->starts_at)->tz('Europe/Zurich')->format('H:i'));
            $this->assertSame('16:00', CarbonImmutable::parse($event->deadline_at)->tz('Europe/Zurich')->format('H:i'));
        }
    }

    public function test_portal_session_can_participate_but_cannot_manage_team_or_read_other_comments(): void
    {
        $id = $this->service->saveEvent($this->admin, $this->tenant, null, $this->input());
        $this->service->participate($this->trainer, $this->tenant, $id, $this->otherMember, ['status' => 'ABWESEND', 'attendance' => 'OFFEN', 'comment' => 'PRIVATE-STAFF-REASON']);
        auth('clubunio')->logout();
        $this->actingAs($this->player, 'portal');
        $base = '/planning/'.$this->tenant->slug;
        $this->get($base.'/events/'.$id)->assertOk()->assertDontSee('PRIVATE-STAFF-REASON');
        $this->get($base.'/events/'.$id.'/edit')->assertForbidden();
        $this->post($base.'/events/'.$id.'/members/'.$this->member, ['status' => 'DABEI'])->assertRedirect();
        $this->assertSame('DABEI', $this->participation($id)->status);
        $this->get($base.'/options')->assertForbidden();
        auth('portal')->logout();
        $this->get($base)->assertRedirect('/portal/login');
    }

    public function test_reminders_are_internal_idempotent_and_no_absence_reason_leaks(): void
    {
        foreach (['team.created', 'team.reminder', 'team.deadline'] as $key) {
            $this->db->table('message_templates')->insert(['tenant_id' => $this->tenant->id, 'event_key' => $key, 'channel' => 'push', 'locale' => 'de-CH', 'title' => '{{ termin }}', 'body' => '{{ datum }}', 'active' => true, 'revision' => 1]);
        }
        $id = $this->service->saveEvent($this->admin, $this->tenant, null, $this->input(['starts_at' => now('Europe/Zurich')->addHours(3)->format('Y-m-d\TH:i'),
            'ends_at' => now('Europe/Zurich')->addHours(4)->format('Y-m-d\TH:i'), 'deadline_at' => now('Europe/Zurich')->addHour()->format('Y-m-d\TH:i')]));
        $this->assertSame(2, $this->service->reminders($this->tenant));
        $this->assertSame(0, $this->service->reminders($this->tenant));
        $this->assertSame(3, $this->db->table('team_planning_messages')->where('event_id', $id)->count());
        $this->assertSame(6, $this->db->table('notifications')->where('tenant_id', $this->tenant->id)->count());
        $this->assertStringNotContainsString('PRIVATE', $this->db->table('notifications')->where('tenant_id', $this->tenant->id)->get()->toJson());
    }
}
