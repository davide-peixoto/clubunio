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
use Tests\Concerns\WorkspaceForm;
use Tests\TestCase;

class TeamPlanningTest extends TestCase
{
    use WorkspaceForm;
    use \Tests\Concerns\PlanningTableRequests;
    use \Tests\Concerns\TemporaryPlanningRemovalSchema;

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
        config(['clubunio-mobile.native_planning_enabled' => true]);
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Opt-in rolled-back DB tests');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->db->statement('SET LOCAL search_path TO clubunio');
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
        $season = $this->db->table('seasons')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => 'Fixture season',
            'starts_on' => now('Europe/Zurich')->subMonth()->toDateString(), 'ends_on' => now('Europe/Zurich')->addMonths(2)->toDateString()]);
        $this->db->table('tenant_settings')->insert(['tenant_id' => $this->tenant->id, 'area' => 'general', 'key' => 'SaisonID', 'label' => 'Synthetic season',
            'value_type' => 'integer', 'encrypted_value' => Crypt::encryptString((string) $season), 'is_secret' => false]);
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

    public function test_capacity_count_matches_roster_reference_with_two_scalar_queries(): void
    {
        $id = $this->service->saveEvent($this->admin, $this->tenant, null, $this->input());
        $members = [$this->member, $this->otherMember];
        foreach (['ABWESEND', 'OFFEN', 'NICHT_AUFGEBOT', 'DABEI'] as $status) {
            $member = $this->db->table('members')->insertGetId(['tenant_id' => $this->tenant->id, 'first_name' => 'Count', 'last_name' => 'Fixture']);
            $members[] = $member;
            $this->db->table('team_players')->insert(['tenant_id' => $this->tenant->id, 'team_season_id' => $this->team, 'member_id' => $member]);
            $this->db->table('team_participations')->insert(['tenant_id' => $this->tenant->id, 'event_id' => $id, 'member_id' => $member, 'status' => $status]);
        }
        // Staff plus player must still count once; non-roster participation must not count.
        $this->db->table('team_players')->insert(['tenant_id' => $this->tenant->id, 'team_season_id' => $this->team, 'member_id' => $this->otherMember]);
        $outside = $this->db->table('members')->insertGetId(['tenant_id' => $this->tenant->id, 'first_name' => 'Outside', 'last_name' => 'Fixture']);
        $this->db->table('team_participations')->insert(['tenant_id' => $this->tenant->id, 'event_id' => $id, 'member_id' => $outside, 'status' => 'DABEI']);
        foreach ([['DABEI', null], ['OFFEN', null], ['DABEI', now()]] as [$status, $deleted]) {
            $this->db->table('team_guests')->insert(['tenant_id' => $this->tenant->id, 'event_id' => $id, 'first_name' => 'Guest', 'last_name' => 'Fixture', 'status' => $status, 'deleted_at' => $deleted]);
        }
        $event = $this->service->event($this->admin, $this->tenant, $id);
        foreach (['ANMELDEN', 'ABMELDEN'] as $mode) {
            $event->mode = $mode;
            foreach ([null, ...$members] as $except) {
                $rows = $this->db->table('team_participations')->where('event_id', $id)->get()->keyBy('member_id');
                $expected = $this->service->roster($this->tenant, $event)->pluck('id')->filter(fn ($member) => $member !== $except && ($rows[$member]->status ?? ($mode === 'ABMELDEN' ? 'DABEI' : 'OFFEN')) === 'DABEI')->count() + 1;
                $this->db->enableQueryLog();
                $this->db->flushQueryLog();
                try {
                    $actual = $this->service->effectiveCount($this->tenant, $event, $except);
                    $queries = $this->db->getQueryLog();
                } finally {
                    $this->db->disableQueryLog();
                }
                $this->assertSame($expected, $actual);
                $this->assertCount(2, $queries);
                foreach ($queries as $query) {
                    $this->assertStringContainsString('count(*)', $query['query']);
                }
            }
        }
    }

    public function test_absence_reuses_periods_and_scopes_participation_reads(): void
    {
        $ids = [];
        for ($i = 0; $i < 4; $i++) {
            $ids[] = $this->service->saveEvent($this->admin, $this->tenant, null, $this->input());
        }
        $locked = $this->service->saveEvent($this->admin, $this->tenant, null, $this->input(['deadline_at' => now('Europe/Zurich')->subHour()->format('Y-m-d\TH:i')]));
        $this->service->participate($this->trainer, $this->tenant, $ids[0], $this->member, ['status' => 'DABEI', 'attendance' => 'OFFEN']);
        $this->db->enableQueryLog();
        $this->db->flushQueryLog();
        try {
            $result = $this->service->saveAbsence($this->player, $this->tenant, null, $this->absence());
            $reads = collect($this->db->getQueryLog())->pluck('query')->filter(fn ($sql) => str_starts_with($sql, 'select'));
        } finally {
            $this->db->disableQueryLog();
        }
        $this->assertSame(1, $result['skipped']);
        $this->assertCount(2, $reads->filter(fn ($sql) => str_contains($sql, 'from "clubunio"."team_absences"')));
        $participations = $reads->filter(fn ($sql) => str_starts_with($sql, 'select * from "clubunio"."team_participations"'));
        $this->assertCount(4, $participations);
        foreach ($participations as $sql) {
            $this->assertStringContainsString('"member_id" = ?', $sql);
        }
        $this->assertNull($this->participation($locked));
        $this->assertSame('trainer', $this->participation($ids[0])->source);
        foreach (array_slice($ids, 1) as $id) {
            $this->assertSame('absence', $this->participation($id)->source);
        }
        $this->assertStringNotContainsString('PRIVATE-HEALTH', $this->db->table('object_changes')->where('tenant_id', $this->tenant->id)->get()->toJson());
    }

    public function test_reused_calendar_team_labels_do_not_expand_invited_player_permissions(): void
    {
        $input = $this->input(['title' => 'Invited fixture']);
        $event = $this->service->saveEvent($this->admin, $this->tenant, null, $input);
        $hidden = $this->service->saveEvent($this->admin, $this->tenant, null, $this->input(['title' => 'Hidden fixture']));
        $this->db->table('team_players')->where('member_id', $this->member)->delete();
        app(PlanningGuestService::class)->invite($this->admin, $this->tenant, $event, $this->member);
        auth('clubunio')->logout();
        $this->actingAs($this->player, 'portal');
        $base = '/planning/'.$this->tenant->slug;
        $this->get($base.'/calendar?view=teams&date='.substr($input['starts_at'], 0, 10))
            ->assertOk()->assertSee('Fixture team / season')->assertSee('Invited fixture')
            ->assertDontSee('Hidden fixture')->assertDontSee('Termin erstellen');
        $this->get($base.'/events/'.$event)->assertOk()->assertSee('Fixture team / season');
        $this->get($base.'/events/'.$event.'/edit')->assertForbidden();
        $this->get($base.'/events/'.$hidden)->assertNotFound();
        $this->db->table('team_invitations')->where('event_id', $event)->where('member_id', $this->member)->delete();
        $this->get($base.'/events/'.$event)->assertNotFound();
        $this->get($base.'/calendar?view=teams&date='.substr($input['starts_at'], 0, 10))
            ->assertOk()->assertDontSee('Invited fixture');
    }

    public function test_calendar_views_filters_navigation_and_export(): void
    {
        $input = $this->input(['title' => 'Kalender Äöü, Test; Termin']);
        $id = $this->service->saveEvent($this->admin, $this->tenant, null, $input);
        $date = substr($input['starts_at'], 0, 10);
        $base = '/planning/calendar';
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
        $context = $this->workspaceForm();
        $base = '/planning/sfv';
        $this->get($base)->assertOk()->assertSee('Sissach - Guest');
        $this->get($base.'/'.$match->id)->assertOk()->assertSee('sfv_revision');
        $event = $sfv->import($this->trainer, $this->tenant, $match->id, $this->input() + ['sfv_revision' => 1]);
        $this->assertSame($event, $sfv->import($this->trainer, $this->tenant, $match->id, $this->input() + ['sfv_revision' => 1, 'event_revision' => 1]));
        $sfv->refresh($this->trainer, $this->tenant, $range);
        $this->assertSame(1, $this->db->table('team_events')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(2, $this->db->table('team_events')->where('id', $event)->value('revision'));
        $this->post($base.'/'.$match->id, $context + $this->input() + ['sfv_revision' => 1, 'event_revision' => 2])->assertSessionHasErrors('revision');
        $base = '/planning/'.$this->tenant->slug.'/sfv';
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
        $context = $this->workspaceForm();
        $id = $this->service->saveEvent($this->admin, $this->tenant, null, $this->input());
        $base = '/planning';
        $this->get($base)->assertOk()->assertSee('Fixture training');
        $this->get($base.'?team='.$this->team.'&q=training&status=AKTIV')->assertOk()->assertSee('Fixture training')->assertSee('tableFilters.team.value', false)->assertSee('tableSearch', false);
        foreach (['/new', '/events/'.$id, '/events/'.$id.'/edit', '/absences', '/options'] as $path) {
            $this->get($base.$path)->assertOk();
        }
        $this->post($base.'/events/'.$id, $context + $this->input(['revision' => 1, 'title' => 'Updated']))->assertRedirect();
        $this->post($base.'/events/'.$id, $context + $this->input(['revision' => 1]))->assertSessionHasErrors('revision');
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
        $this->service->saveAbsence($this->trainer, $this->tenant, $result['id'], $this->absence(['revision' => 1]));
        $parent = $this->account('member');
        $this->db->table('account_member_links')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $parent->id, 'member_id' => $this->member, 'kind' => 'parent', 'state' => 'approved', 'source' => 'test']);
        $this->service->saveAbsence($parent, $this->tenant, $result['id'], $this->absence(['revision' => 2, 'note' => 'Parent correction']));
        $this->assertSame(3, $this->db->table('team_absences')->where('id', $result['id'])->value('revision'));
        $outsider = $this->account('member');
        $this->db->table('account_member_links')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $outsider->id, 'member_id' => $this->otherMember, 'kind' => 'self', 'state' => 'approved', 'source' => 'test']);
        $this->assertSame(0, $this->service->absences($outsider, $this->tenant)->count());
        $this->actingAs($outsider, 'clubunio');
        $this->get('/planning/'.$this->tenant->slug.'/absences')->assertOk()->assertDontSee('Parent correction');
        $this->post('/planning/'.$this->tenant->slug.'/absences/'.$result['id'], $this->absence(['revision' => 3]))->assertForbidden();
    }

    public function test_absence_managers_can_create_and_edit_only_their_responsible_members(): void
    {
        $lead = $this->account('technical_lead');
        $this->db->table('account_member_links')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $lead->id,
            'member_id' => $this->otherMember, 'kind' => 'self', 'state' => 'approved', 'source' => 'test']);
        $this->db->table('technical_leads')->insert(['tenant_id' => $this->tenant->id, 'member_id' => $this->otherMember, 'team_season_id' => $this->team]);
        $unassigned = $this->db->table('members')->insertGetId(['tenant_id' => $this->tenant->id, 'first_name' => 'Unassigned']);
        $foreignTenant = Tenant::create(['slug' => 'absence-other-'.bin2hex(random_bytes(6)), 'name' => 'Other']);
        $foreign = $this->db->table('members')->insertGetId(['tenant_id' => $foreignTenant->id, 'first_name' => 'Foreign']);
        $outsideAbsence = $this->service->saveAbsence($this->admin, $this->tenant, null, $this->absence(['member_id' => $unassigned]));
        foreach ([$this->trainer, $lead] as $manager) {
            $created = $this->service->saveAbsence($manager, $this->tenant, null, $this->absence());
            $this->service->saveAbsence($manager, $this->tenant, $created['id'], $this->absence(['revision' => 1, 'note' => 'Manager correction']));
            $this->assertSame(2, $this->db->table('team_absences')->where('id', $created['id'])->value('revision'));
            foreach ([$unassigned, $foreign] as $member) {
                try {
                    $this->service->saveAbsence($manager, $this->tenant, null, $this->absence(['member_id' => $member]));
                    $this->fail('Unrelated member accepted');
                } catch (HttpException $error) {
                    $this->assertSame(403, $error->getStatusCode());
                }
            }
            $this->actingAs($manager, 'clubunio')->get('/planning/'.$this->tenant->slug.'/absences?edit='.$created['id'])
                ->assertOk()->assertSee('Manager correction');
            try {
                $this->service->saveAbsence($manager, $this->tenant, $outsideAbsence['id'], $this->absence(['revision' => 1]));
                $this->fail('Unrelated absence edit accepted');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
            try {
                $this->service->saveAbsence($manager, $this->tenant, $created['id'], ['revision' => 2], true);
                $this->fail('Team-manager delete was unexpectedly expanded');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
        $this->db->table('technical_leads')->where('tenant_id', $this->tenant->id)->where('member_id', $this->otherMember)->update(['active' => false]);
        $this->assertFalse($this->service->absenceMembers($lead, $this->tenant, 'update')->whereKey($this->member)->exists());
        try {
            $this->service->saveAbsence($lead, $this->tenant, $created['id'], $this->absence(['revision' => 2]));
            $this->fail('Revoked team assignment retained edit access');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
    }

    public function test_admin_without_self_link_can_manage_an_unassigned_absence_in_native_actions(): void
    {
        $this->assertFalse($this->db->table('account_member_links')->where('account_id', $this->admin->id)->exists());
        $member = $this->db->table('members')->insertGetId(['tenant_id' => $this->tenant->id, 'first_name' => 'Unassigned Admin Fixture']);
        $page = $this->get('/planning/absences')->assertOk()->assertSee('Absenz erfassen');
        $snapshot = $this->tableSnapshot($page->getContent());
        $mounted = $this->updatePlanningTable($snapshot, [], [['method' => 'mountAction', 'params' => ['create', [], ['table' => true]]]])->assertOk();
        $this->assertStringContainsString('"name":"create"', $mounted->json('components.0.snapshot'));
        $data = $this->absence(['member_id' => $member, 'revision' => 0]);
        $updates = [];
        foreach ($data as $field => $value) {
            $updates['mountedActions.0.data.'.$field] = $value;
        }
        $this->updatePlanningTable($mounted->json('components.0.snapshot'), $updates,
            [['method' => 'callMountedAction', 'params' => []]])->assertOk();
        $record = $this->db->table('team_absences')->where('tenant_id', $this->tenant->id)->where('member_id', $member)->sole();
        $this->assertSame(1, $record->revision);
        $page = $this->get('/planning/absences')->assertOk()->assertSee('Unassigned Admin Fixture');
        $mounted = $this->updatePlanningTable($this->tableSnapshot($page->getContent()), [],
            [['method' => 'mountAction', 'params' => ['edit', [], ['table' => true, 'recordKey' => (string) $record->id]]]])->assertOk();
        $this->assertStringContainsString('PRIVATE-HEALTH-FIXTURE', $mounted->json('components.0.snapshot'));
        $this->assertArrayHasKey('clubunioContext', json_decode($mounted->json('components.0.snapshot'), true)['memo']);
        $this->updatePlanningTable($mounted->json('components.0.snapshot'), ['mountedActions.0.data.note' => 'Edited by admin'],
            [['method' => 'callMountedAction', 'params' => []]])->assertOk();
        $this->assertSame(2, $this->db->table('team_absences')->where('id', $record->id)->value('revision'));
        Http::assertNothingSent();
    }

    public function test_read_only_planning_profile_cannot_use_absence_write_scope(): void
    {
        $profile = app(\App\ClubUnio\Services\AccessProfileService::class)->save($this->admin, $this->tenant, null,
            ['name' => 'Absence reader', 'permissions' => ['planning.view'], 'active' => true, 'reason' => 'Synthetic test']);
        $reader = $this->account('member');
        $this->db->table('tenant_accounts')->where('tenant_id', $this->tenant->id)->where('account_id', $reader->id)->update(['role' => 'profile', 'access_profile_id' => $profile->id, 'portal_enabled' => false]);
        $this->actingAs($reader, 'clubunio')->get('/planning/absences')->assertOk()->assertDontSee('Absenz erfassen');
        foreach (['create', 'update'] as $function) {
            try {
                $this->service->absenceMembers($reader, $this->tenant, $function);
                $this->fail('Read-only profile obtained write scope');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
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

    public function test_personal_planning_keeps_unregistered_players_and_staff_counters_separate_without_creating_replies(): void
    {
        $event = $this->service->saveEvent($this->admin, $this->tenant, null, $this->input());
        $read = fn () => app(\App\ClubUnio\Mobile\MobilePlanningData::class)->event($this->admin, $this->tenant, $event);
        $dto = $read();
        $this->assertSame([$this->member], array_column($dto['NichtAngemeldet'], 'MitgliederID'));
        $this->assertSame(1, $dto['OffenCount']);
        $this->assertSame(0, $dto['StaffDabeiCount']);
        $this->assertSame(0, $this->db->table('team_participations')->where('event_id', $event)->count());
        foreach ([$this->member, $this->otherMember] as $member) {
            $this->service->participate($this->admin, $this->tenant, $event, $member, ['status' => 'DABEI', 'attendance' => 'OFFEN', 'revision' => 0]);
        }
        $dto = $read();
        $this->assertSame([], $dto['NichtAngemeldet']);
        $this->assertSame(1, $dto['DabeiCount']);
        $this->assertSame(1, $dto['StaffDabeiCount']);
        $this->assertSame(0, $dto['OffenCount']);
    }

    public function test_native_module_catalog_requires_individual_feature_activation_and_personal_access(): void
    {
        // This synthetic tenant needs the same explicit menu setup as the real admin form.
        foreach (['Teams','Teamplanung','Spesen'] as $key) {
            $this->db->table('clubunio.mobile_module_options')->insert(['tenant_id' => $this->tenant->id, 'key' => $key,
                'name' => \App\ClubUnio\Services\MobileModuleSettings::MODULES[$key], 'active' => true, 'sort_order' => 0]);
        }
        config(['clubunio-mobile.enabled' => true, 'cache.default' => 'array', 'clubunio-mobile.native_my_teams_enabled' => false,
            'clubunio-mobile.native_expenses_enabled' => false]);
        $token = $this->postJson('/api/mobile/v1/session', ['username' => $this->trainer->username, 'password' => 'fixture', 'device_name' => 'Module fixture'])->assertOk()->json('data.token');
        $url = '/api/mobile/v1/clubs/'.$this->tenant->id.'/modules';
        $this->withToken($token)->getJson($url)->assertOk()->assertJsonFragment(['key' => 'Teams', 'available' => false, 'native' => false]);
        config(['clubunio-mobile.native_my_teams_enabled' => true, 'clubunio-mobile.native_expenses_enabled' => true]);
        $this->getJson($url)->assertOk()->assertJsonFragment(['key' => 'Teams', 'available' => false, 'native' => false]);
        $this->withHeader('X-ClubUnio-Mobile-Features', 'svs-planning-v1,my-teams-v1,expenses-v1');
        $this->getJson($url)->assertOk()->assertJsonFragment(['key' => 'Teams', 'available' => true, 'native' => true])
            ->assertJsonFragment(['key' => 'Teamplanung', 'available' => true, 'native' => true])
            ->assertJsonFragment(['key' => 'Spesen', 'available' => true, 'native' => true]);
        $foreign = Tenant::create(['slug' => 'module-foreign-'.bin2hex(random_bytes(4)), 'name' => 'Foreign']);
        $this->getJson('/api/mobile/v1/clubs/'.$foreign->id.'/modules')->assertForbidden();
    }

    public function test_personal_planning_capabilities_keep_operation_rights_distinct_without_numeric_roles(): void
    {
        config(['clubunio-mobile.enabled' => true, 'cache.default' => 'array']);
        $event = $this->service->saveEvent($this->admin, $this->tenant, null, $this->input(['kind' => 'MEISTERSCHAFT']));
        $profile = $this->db->table('access_profiles')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => 'Synthetic mobile capabilities',
            'permissions' => json_encode(['planning.view', 'planning.update']), 'active' => true]);
        $actor = $this->account('member');
        $this->db->table('tenant_accounts')->where('tenant_id', $this->tenant->id)->where('account_id', $actor->id)->update(['role' => 'profile', 'access_profile_id' => $profile, 'portal_enabled' => false]);
        $login = fn ($account) => $this->postJson('/api/mobile/v1/session', ['username' => $account->username, 'password' => 'fixture', 'device_name' => 'Capabilities fixture'])->assertOk()->json('data.token');
        // Backoffice-only profiles must not gain portal/mobile login from presentation hints.
        $this->postJson('/api/mobile/v1/session', ['username' => $actor->username, 'password' => 'fixture', 'device_name' => 'Capabilities fixture'])->assertUnauthorized();
        $base = '/api/mobile/v1/clubs/'.$this->tenant->id.'/planning/';
        $read = fn () => app(\App\ClubUnio\Mobile\MobilePlanningData::class)->event($actor, $this->tenant, $event);
        $response = $read();
        foreach (['CanEdit' => true, 'CanAddPeople' => false, 'CanCancel' => false, 'CanRemove' => false, 'CanShareSquad' => false, 'CanSendSquadPush' => false] as $key => $value) {
            $this->assertSame($value, $response[$key]);
        }
        $teams = app(\App\ClubUnio\Mobile\MobilePlanningData::class)->teams(\Illuminate\Http\Request::create('/'), $actor, $this->tenant);
        $this->assertTrue($teams['Teams'][0]['CanManage']); $this->assertFalse($teams['Teams'][0]['CanCreate']);
        $this->db->table('access_profiles')->where('id', $profile)->update(['permissions' => json_encode(['planning.view', 'planning.create', 'planning.cancel', 'planning.deactivate'])]);
        $response = $read();
        foreach (['CanEdit' => false, 'CanAddPeople' => true, 'CanCancel' => true, 'CanRemove' => true, 'CanShareSquad' => false] as $key => $value) {
            $this->assertSame($value, $response[$key]);
        }
        $teams = app(\App\ClubUnio\Mobile\MobilePlanningData::class)->teams(\Illuminate\Http\Request::create('/'), $actor, $this->tenant);
        $this->assertFalse($teams['Teams'][0]['CanManage']); $this->assertTrue($teams['Teams'][0]['CanCreate']);
        $this->withToken($login($this->trainer))->getJson($base.'events/'.$event)->assertOk()->assertJsonPath('data.TeamTermin.CanShareSquad', true)->assertJsonPath('data.TeamTermin.CanSendSquadPush', false);
    }

    public function test_personal_statistics_preserves_player_history_scope_and_completed_event_counts(): void
    {
        config(['clubunio-mobile.enabled' => true]);
        $from = CarbonImmutable::now('Europe/Zurich')->subDays(3)->startOfDay();
        $event = $this->service->saveEvent($this->admin, $this->tenant, null, $this->input());
        $this->db->table('team_events')->where('id', $event)->update(['starts_at' => $from->addHours(18)->toIso8601String(), 'ends_at' => $from->addHours(20)->toIso8601String()]);
        $history = $this->db->table('members')->insertGetId(['tenant_id' => $this->tenant->id, 'first_name' => 'Historical', 'last_name' => 'Player']);
        $quiet = $this->db->table('members')->insertGetId(['tenant_id' => $this->tenant->id, 'first_name' => 'Zero', 'last_name' => 'Player']);
        $this->db->table('team_players')->insert(['tenant_id' => $this->tenant->id, 'team_season_id' => $this->team, 'member_id' => $quiet]);
        foreach ([$this->member, $this->otherMember, $history] as $member) {
            $this->db->table('team_participations')->updateOrInsert(['tenant_id' => $this->tenant->id, 'event_id' => $event, 'member_id' => $member],
                ['status' => 'DABEI', 'attendance' => 'ANWESEND', 'comment_encrypted' => Crypt::encryptString('PRIVATE-STATS-REASON')]);
        }
        // Staff who also have a player assignment still never enter player statistics.
        $this->db->table('team_players')->insert(['tenant_id' => $this->tenant->id, 'team_season_id' => $this->team, 'member_id' => $this->otherMember]);
        $this->db->table('team_guests')->insert(['tenant_id' => $this->tenant->id, 'event_id' => $event, 'first_name' => 'Guest', 'last_name' => 'Excluded', 'status' => 'DABEI', 'attendance' => 'ANWESEND']);
        $copy = (array) $this->service->event($this->admin, $this->tenant, $event); unset($copy['id']);
        foreach ([['status' => 'ABGESAGT'], ['starts_at' => now()->subHour(), 'ends_at' => now()->addHour()], ['deleted_at' => now()]] as $change) {
            $id = $this->db->table('team_events')->insertGetId(array_replace($copy, $change));
            $this->db->table('team_participations')->insert(['tenant_id' => $this->tenant->id, 'event_id' => $id, 'member_id' => $this->member, 'status' => 'ABWESEND', 'attendance' => 'FEHLT']);
        }
        $login = fn ($actor) => $this->postJson('/api/mobile/v1/session', ['username' => $actor->username, 'password' => 'fixture', 'device_name' => 'Stats fixture'])->assertOk()->json('data.token');
        $url = '/api/mobile/v1/clubs/'.$this->tenant->id.'/planning/statistics?'.http_build_query(['from' => $from->toIso8601String(), 'to' => now('Europe/Zurich')->startOfDay()->toIso8601String()]);
        $adminToken = $login($this->admin);
        $result = $this->withToken($adminToken)->getJson($url)->assertOk()->assertDontSee('PRIVATE-STATS-REASON')->assertJsonPath('data.totalRecordCount', 3)->json('data.SpielerStatistiken');
        $this->assertEqualsCanonicalizing([$this->member, $history, $quiet], array_column($result, 'MitgliederID'));
        $rows = collect($result)->keyBy('MitgliederID');
        $this->assertSame(['Zusagen' => 1, 'Absagen' => 0, 'Anwesend' => 1, 'Gefehlt' => 0, 'Entschuldigt' => 0], $rows[$this->member]['Kategorien']['TRAINING']);
        $this->assertSame(0, $rows[$quiet]['Kategorien']['TRAINING']['Zusagen']);
        $this->withToken($adminToken)->getJson($url.'&start=1&recperpage=1')->assertOk()->assertJsonCount(1, 'data.SpielerStatistiken')->assertJsonPath('data.totalRecordCount', 3);
        $this->withToken($login($this->player))->getJson($url)->assertOk()->assertJsonPath('data.totalRecordCount', 1)->assertJsonPath('data.SpielerStatistiken.0.MitgliederID', $this->member);
        $this->withToken($login($this->trainer))->getJson($url)->assertOk()->assertJsonPath('data.totalRecordCount', 3);
        $foreign = Tenant::create(['slug' => 'stats-other-'.bin2hex(random_bytes(4)), 'name' => 'Foreign']);
        $this->withToken($adminToken)->getJson(str_replace('/clubs/'.$this->tenant->id.'/', '/clubs/'.$foreign->id.'/', $url))->assertForbidden();
        $this->withToken($adminToken)->getJson($url.'&TeamSaisonID=2147483647')->assertOk()->assertJsonPath('data.totalRecordCount', 0);
        $this->withToken($adminToken)->getJson($url.'&from=invalid')->assertStatus(422);
        $this->db->table('team_events')->insert(array_replace($copy, ['mode' => 'ABMELDEN']));
        $rows = collect($this->withToken($adminToken)->getJson($url)->assertOk()->json('data.SpielerStatistiken'))->keyBy('MitgliederID');
        $this->assertSame(2, $rows[$this->member]['Kategorien']['TRAINING']['Zusagen']);
        $this->assertSame(1, $rows[$quiet]['Kategorien']['TRAINING']['Zusagen']);
        $this->assertSame(0, $rows[$quiet]['Kategorien']['TRAINING']['Anwesend']);
        $this->assertSame(1, $rows[$history]['Kategorien']['TRAINING']['Zusagen']);
    }

    public function test_native_planning_real_updates_preserve_scope_dates_filters_and_pagination(): void
    {
        $id = $this->service->saveEvent($this->admin, $this->tenant, null, $this->input());
        $row = (array) $this->service->event($this->admin, $this->tenant, $id);
        unset($row['id']);
        $row['title'] = 'ZZZ Fixture';
        $this->db->table('team_events')->insert($row);
        $row['title'] = 'Past fixture';
        $row['starts_at'] = now()->subDays(5);
        $this->db->table('team_events')->insert($row);
        $row['starts_at'] = now()->addDays(20);
        $row['ends_at'] = now()->addDays(20)->addHours(2);
        for ($i = 1; $i <= 12; $i++) {
            $row['title'] = 'Page '.sprintf('%02d', $i);
            $this->db->table('team_events')->insert($row);
        }
        $page = $this->get('/planning')->assertOk()->assertSee('Fixture training')->assertDontSee('Past fixture')->assertSee('fi-ta-table', false);
        $snapshot = $this->tableSnapshot($page->getContent());
        $response = $this->updatePlanningTable($snapshot, ['tableRecordsPerPage' => 10])->assertOk();
        $this->assertStringNotContainsString('Page 12', $response->json('components.0.effects.html'));
        $response = $this->updatePlanningTable($response->json('components.0.snapshot'), [], [['method' => 'setPage', 'params' => [2]]])->assertOk();
        $this->assertStringContainsString('Page 12', $response->json('components.0.effects.html'));
        $response = $this->updatePlanningTable($snapshot, ['tableSearch' => 'Fixture training', 'tableFilters.team.value' => (string) $this->team])->assertOk();
        $html = $response->json('components.0.effects.html');
        $this->assertStringContainsString('Fixture training', $html);
        $this->assertStringNotContainsString('ZZZ Fixture', $html);
        $this->assertStringContainsString('/planning/events/'.$id, $html);
        $response = $this->updatePlanningTable($snapshot, ['tableFilters.dates.from' => null, 'tableSort' => 'title:desc', 'tableRecordsPerPage' => 25])->assertOk();
        $html = $response->json('components.0.effects.html');
        $this->assertStringContainsString('Past fixture', $html);
        $this->assertLessThan(strpos($html, 'Fixture training'), strpos($html, 'ZZZ Fixture'));
        $response = $this->updatePlanningTable($snapshot, ['tableFilters.status.value' => 'ABGESAGT'])->assertOk();
        $this->assertStringNotContainsString('Fixture training', $response->json('components.0.effects.html'));
    }

    public function test_native_planning_snapshot_rejects_workspace_revision_and_tenant_switch(): void
    {
        $page = $this->get('/planning')->assertOk();
        $snapshot = $this->tableSnapshot($page->getContent());
        $key = 'clubunio_context.'.$this->admin->id;
        $context = session($key);
        session()->put($key.'.revision', 'changed-revision');
        $this->updatePlanningTable($snapshot)->assertStatus(419);
        session()->put($key, $context);
        $other = Tenant::create(['slug' => 'table-other-'.bin2hex(random_bytes(4)), 'name' => 'Other']);
        $this->admin->tenants()->attach($other, ['role' => 'admin']);
        app(\App\ClubUnio\Services\ActiveTenant::class)->select(request(), $other);
        $this->updatePlanningTable($snapshot, [], [['method' => 'sortTable', 'params' => ['title', 'asc']]])->assertStatus(419);
    }

    public function test_native_planning_keeps_invited_inactive_team_without_expanding_scope(): void
    {
        $id = $this->service->saveEvent($this->admin, $this->tenant, null, $this->input());
        $hidden = $this->service->saveEvent($this->admin, $this->tenant, null, $this->input(['title' => 'Hidden inactive event']));
        $this->db->table('account_member_links')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $this->admin->id, 'member_id' => $this->member, 'kind' => 'self', 'state' => 'approved', 'source' => 'test']);
        $this->db->table('team_invitations')->insert(['tenant_id' => $this->tenant->id, 'event_id' => $id, 'member_id' => $this->member]);
        $this->db->table('team_seasons')->where('id', $this->team)->update(['active' => false]);
        $page = $this->get('/planning')->assertOk()->assertSee('Fixture training')->assertSee('Fixture team / season')->assertDontSee('Hidden inactive event');
        $snapshot = $this->tableSnapshot($page->getContent());
        $this->assertStringNotContainsString('Hidden inactive event', $this->updatePlanningTable($snapshot)->assertOk()->json('components.0.effects.html'));
        $this->assertNotSame($id, $hidden);
        auth('clubunio')->logout();
        $this->actingAs($this->player, 'portal');
        $this->get('/planning/'.$this->tenant->slug)->assertOk()->assertSee('Fixture training')->assertDontSee('fi-ta-table', false);
    }

    public function test_native_planning_domain_change_rejects_real_update(): void
    {
        $other = Tenant::create(['slug' => 'table-domain-'.bin2hex(random_bytes(4)), 'name' => 'Other domain']);
        $this->admin->tenants()->attach($other, ['role' => 'admin']);
        config(['clubunio-domains.hosts' => ['planning-one.test' => $this->tenant->slug, 'planning-two.test' => $other->slug]]);
        $page = $this->get('https://planning-one.test/planning')->assertOk();
        $snapshot = $this->tableSnapshot($page->getContent());
        $this->updatePlanningTable($snapshot, [], [], 'https://planning-one.test')->assertOk();
        $this->updatePlanningTable($snapshot, [], [], 'https://planning-two.test')->assertStatus(419);
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
    public function test_native_mobile_writes_require_revisions_and_preserve_two_private_comments(): void
    {
        config(['clubunio-mobile.enabled' => true, 'cache.default' => 'array']);
        $this->db->statement("SET LOCAL lock_timeout TO '5s'");
        $this->assertTrue($this->db->getSchemaBuilder()->hasColumn('clubunio.team_participations', 'attendance_comment_encrypted'));
        $login = function ($actor) {
            return $this->postJson('/api/mobile/v1/session', ['username' => $actor->username, 'password' => 'fixture', 'device_name' => 'Native fixture'])
                ->assertOk()->json('data.token');
        };
        $admin = $login($this->admin);
        $url = '/api/mobile/v1/clubs/'.$this->tenant->id.'/planning/';
        $input = $this->input();
        $native = ['TeamSaisonID' => $this->team, 'Titel' => 'Native fixture event', 'Terminart' => 'TRAINING', 'TeilnahmeModus' => 'ANMELDEN',
            'DatumVon' => $input['starts_at'], 'DatumBis' => $input['ends_at'], 'AnmeldeFrist' => $input['deadline_at']];
        $id = $this->withToken($admin)->postJson($url.'events', $native)->assertOk()->json('data.TeamTermin.TeamTerminID');
        $this->patchJson($url.'events/'.$id, ['Titel' => 'stale'])->assertUnprocessable();
        $this->patchJson($url.'events/'.$id, ['Titel' => 'Native updated', 'Revision' => 1])->assertOk()->assertJsonPath('data.TeamTermin.Revision', 2);
        $this->patchJson($url.'events/'.$id, ['Titel' => 'stale', 'Revision' => 1])->assertUnprocessable();
        $player = $login($this->player);
        $this->withToken($player)->postJson($url.'events', $native)->assertUnprocessable();
        $this->assertSame(1, $this->db->table('team_events')->where('tenant_id', $this->tenant->id)->count());
        $this->patchJson($url.'events/'.$id, ['Titel' => 'forbidden', 'Revision' => 2])->assertNotFound();
        $this->withToken($player)->postJson($url.'events/'.$id.'/participation', ['MitgliederID' => $this->member, 'TeilnahmeStatus' => 'DABEI', 'Kommentar' => 'PRIVATE-PARTICIPATION', 'Revision' => 0])->assertOk();
        $this->postJson($url.'events/'.$id.'/attendance', ['MitgliederID' => $this->member, 'AnwesenheitsStatus' => 'ANWESEND', 'Revision' => 1])->assertNotFound();
        $this->postJson($url.'events/'.$id.'/participation', ['MitgliederID' => $this->otherMember, 'TeilnahmeStatus' => 'DABEI', 'Revision' => 0])->assertForbidden();
        $this->withToken($admin)->postJson($url.'events/'.$id.'/attendance', ['MitgliederID' => $this->member, 'AnwesenheitsStatus' => 'ANWESEND', 'Revision' => 1])->assertConflict();
        $this->travelTo(\Carbon\CarbonImmutable::parse($input['starts_at'])->addMinute());
        try {
            $this->withToken($player)->postJson($url.'events/'.$id.'/participation', ['MitgliederID' => $this->member, 'TeilnahmeStatus' => 'ABWESEND', 'Revision' => 1])->assertUnprocessable();
            $this->withToken($admin)->postJson($url.'events/'.$id.'/attendance', ['MitgliederID' => $this->member, 'AnwesenheitsStatus' => 'ANWESEND', 'AnwesenheitsKommentar' => 'PRIVATE-ATTENDANCE', 'Revision' => 1])->assertOk();
            $row = $this->db->table('team_participations')->where('event_id', $id)->where('member_id', $this->member)->first();
            $this->assertSame('PRIVATE-PARTICIPATION', Crypt::decryptString($row->comment_encrypted));
            $this->assertSame('PRIVATE-ATTENDANCE', Crypt::decryptString($row->attendance_comment_encrypted));
            $this->assertSame(2, $row->revision);
            $this->postJson($url.'events/'.$id.'/attendance', ['MitgliederID' => $this->member, 'AnwesenheitsStatus' => 'FEHLT', 'Revision' => 1])->assertUnprocessable();
        } finally { $this->travelBack(); }
        $this->assertStringNotContainsString('PRIVATE', $this->db->table('object_changes')->where('tenant_id', $this->tenant->id)->get()->toJson());
        $this->withToken($admin)->postJson($url.'events/'.$id.'/cancel', ['Revision' => 2, 'scope' => 'single'])
            ->assertOk()->assertJsonPath('data.TeamTermin.Status', 'ABGESAGT')->assertJsonPath('data.TeamTermin.Revision', 3);
        $this->postJson($url.'events/'.$id.'/cancel', ['Revision' => 2, 'scope' => 'single'])->assertUnprocessable();

    }

    public function test_personal_attendance_batch_is_atomic_versioned_and_preserves_absence_provenance(): void
    {
        config(['clubunio-mobile.enabled' => true, 'cache.default' => 'array']);
        $event = $this->service->saveEvent($this->admin, $this->tenant, null, $this->input());
        $private = Crypt::encryptString('PRIVATE-ABSENCE');
        $original = Crypt::encryptString('PRIVATE-ORIGINAL');
        $this->db->table('team_participations')->insert(['tenant_id' => $this->tenant->id, 'event_id' => $event, 'member_id' => $this->member,
            'status' => 'ABWESEND', 'source' => 'absence', 'comment_encrypted' => $private, 'original_status' => 'DABEI', 'original_comment_encrypted' => $original]);
        $this->db->table('team_participations')->insert(['tenant_id' => $this->tenant->id, 'event_id' => $event, 'member_id' => $this->otherMember, 'status' => 'DABEI']);
        $login = fn ($actor) => $this->postJson('/api/mobile/v1/session', ['username' => $actor->username, 'password' => 'fixture', 'device_name' => 'Attendance fixture'])->assertOk()->json('data.token');
        $token = $login($this->admin);
        $url = '/api/mobile/v1/clubs/'.$this->tenant->id.'/planning/events/'.$event.'/attendance';
        $input = ['Revision' => 1, 'suggestions' => [
            ['MitgliederID' => $this->member, 'TeilnahmeStatus' => 'ABWESEND', 'Revision' => 1],
            ['MitgliederID' => $this->otherMember, 'TeilnahmeStatus' => 'DABEI', 'Revision' => 1],
        ]];
        $this->withToken($token)->postJson($url.'/confirm-suggestions', $input)->assertConflict();
        $this->db->table('team_events')->where('id', $event)->update(['starts_at' => now()->subHour()->toIso8601String()]);
        $this->withToken($login($this->player))->postJson($url.'/confirm-suggestions', $input)->assertNotFound();
        $bad = $input; $bad['suggestions'][1]['Revision'] = 99;
        $this->withToken($token)->postJson($url.'/confirm-suggestions', $bad)->assertUnprocessable();
        $this->assertSame('OFFEN', $this->participation($event)->attendance);
        $this->assertSame(1, $this->participation($event)->revision);
        $bad = $input; $bad['suggestions'][1]['MitgliederID'] = $this->member;
        $this->postJson($url.'/confirm-suggestions', $bad)->assertUnprocessable();
        $bad = $input; $bad['suggestions'][1]['TeilnahmeStatus'] = 'ABWESEND';
        $this->postJson($url.'/confirm-suggestions', $bad)->assertConflict();
        $this->assertSame('OFFEN', $this->participation($event)->attendance);
        $this->postJson($url.'/confirm-suggestions', $input)->assertOk()->assertJsonPath('data.confirmedCount', 2);
        $row = $this->participation($event);
        $this->assertSame('ENTSCHULDIGT', $row->attendance);
        $this->assertSame(2, $row->revision);
        $this->assertSame('absence', $row->source);
        $this->assertSame($private, $row->comment_encrypted);
        $this->assertSame('DABEI', $row->original_status);
        $this->assertSame($original, $row->original_comment_encrypted);
        $this->assertNotNull($row->attendance_updated_at);
        $this->postJson($url.'/confirm-suggestions', $input)->assertUnprocessable();
        $this->postJson($url, ['MitgliederID' => $this->member, 'Revision' => 2, 'AnwesenheitsStatus' => 'ANWESEND'])->assertOk();
        $this->assertSame('absence', $this->participation($event)->source);
        $this->assertSame($private, $this->participation($event)->comment_encrypted);
        $this->assertSame($original, $this->participation($event)->original_comment_encrypted);
        $this->assertStringNotContainsString('PRIVATE', $this->db->table('object_changes')->where('tenant_id', $this->tenant->id)->get()->toJson());
    }

    public function test_native_mobile_guests_keep_scope_revision_and_separate_notes(): void
    {
        config(['clubunio-mobile.enabled' => true, 'cache.default' => 'array']);
        $this->db->statement("SET LOCAL lock_timeout TO '5s'");
        $this->assertTrue($this->db->getSchemaBuilder()->hasColumn('clubunio.team_guests', 'attendance_comment_encrypted'));
        $login = fn ($actor) => $this->postJson('/api/mobile/v1/session', ['username' => $actor->username, 'password' => 'fixture', 'device_name' => 'Guest fixture'])->assertOk()->json('data.token');
        $admin = $login($this->admin);
        $event = $this->service->saveEvent($this->admin, $this->tenant, null, $this->input());
        $otherEvent = $this->service->saveEvent($this->admin, $this->tenant, null, $this->input(['title' => 'Other fixture']));
        $url = '/api/mobile/v1/clubs/'.$this->tenant->id.'/planning/events/'.$event.'/guests';
        $this->withToken($admin)->postJson($url, ['Vorname' => 'Rejected', 'Nachname' => 'Guest', 'AnwesenheitsStatus' => 'ANWESEND'])->assertUnprocessable();
        $this->assertSame(0, $this->db->table('team_guests')->where('event_id', $event)->count());
        $response = $this->withToken($admin)->postJson($url, ['Vorname' => 'Guest', 'Nachname' => 'Fixture'])->assertOk();
        $guest = collect($response->json('data.TeamTermin.Teilnahmen'))->first(fn ($row) => isset($row['GastID']))['GastID'];
        $this->patchJson($url.'/'.$guest, ['Revision' => 1, 'TeilnahmeStatus' => 'DABEI', 'Kommentar' => 'PRIVATE-GUEST'])->assertOk();
        $this->patchJson($url.'/'.$guest, ['Revision' => 1, 'TeilnahmeStatus' => 'ABWESEND'])->assertUnprocessable();
        $this->patchJson('/api/mobile/v1/clubs/'.$this->tenant->id.'/planning/events/'.$otherEvent.'/guests/'.$guest,
            ['Revision' => 2, 'TeilnahmeStatus' => 'ABWESEND'])->assertNotFound();
        $this->patchJson($url.'/'.$guest, ['Revision' => 2, 'AnwesenheitsStatus' => 'ANWESEND'])->assertConflict();
        $this->withToken($login($this->player))->postJson($url, ['Vorname' => 'Forbidden', 'Nachname' => 'Guest'])->assertNotFound();
        $this->travelTo(\Carbon\CarbonImmutable::parse($this->input()['starts_at'])->addMinute());
        try {
            $this->withToken($admin)->patchJson($url.'/'.$guest, ['Revision' => 2, 'AnwesenheitsStatus' => 'ANWESEND', 'AnwesenheitsKommentar' => 'PRIVATE-GUEST-ATTENDANCE'])->assertOk();
            $row = $this->db->table('team_guests')->where('id', $guest)->first();
            $this->assertSame('PRIVATE-GUEST', Crypt::decryptString($row->comment_encrypted));
            $this->assertSame('PRIVATE-GUEST-ATTENDANCE', Crypt::decryptString($row->attendance_comment_encrypted));
            $view = $this->withToken($login($this->player))->getJson('/api/mobile/v1/clubs/'.$this->tenant->id.'/planning/events/'.$event)->assertOk();
            $this->assertStringNotContainsString('PRIVATE-GUEST', $view->getContent());
            $this->withToken($admin)->deleteJson($url.'/'.$guest, ['Revision' => 3])->assertOk();
            $this->deleteJson($url.'/'.$guest, ['Revision' => 3])->assertNotFound();
        } finally { $this->travelBack(); }
        $this->assertStringNotContainsString('PRIVATE-GUEST', $this->db->table('object_changes')->where('tenant_id', $this->tenant->id)->get()->toJson());
    }

    public function test_native_mobile_absences_preserve_exact_windows_scope_and_real_sync_counts(): void
    {
        config(['clubunio-mobile.enabled' => true, 'cache.default' => 'array']);
        $this->db->statement("SET LOCAL lock_timeout TO '5s'");
        // Test the additive DDL and real PostgreSQL service on an empty temporary table.
        // Do not demand an exclusive lock on the shared DEV absence table.
        $this->db->statement('CREATE TEMP TABLE team_absences (LIKE clubunio.team_absences INCLUDING ALL) ON COMMIT DROP');
        $this->db->statement('ALTER TABLE pg_temp.team_absences DROP COLUMN IF EXISTS starts_at CASCADE, DROP COLUMN IF EXISTS ends_at CASCADE');
        $ddl = $this->db->pretend(fn () => (require database_path('clubunio/2026_11_03_000085_planning_absence_windows.php'))->up());
        $this->assertCount(1, $ddl);
        $this->db->statement(str_replace('clubunio.team_absences', 'pg_temp.team_absences', $ddl[0]['query']));
        $this->db->setQueryGrammar(new class($this->db) extends \Illuminate\Database\Query\Grammars\PostgresGrammar {
            public function wrapTable($table, $prefix = null) {
                return parent::wrapTable($table === 'clubunio.team_absences' ? 'pg_temp.team_absences' : $table, $prefix);
            }
        });
        $login = fn ($actor) => $this->postJson('/api/mobile/v1/session', ['username' => $actor->username, 'password' => 'fixture', 'device_name' => 'Absence fixture'])->assertOk()->json('data.token');
        $event = $this->service->saveEvent($this->admin, $this->tenant, null, $this->input());
        $start = CarbonImmutable::parse($this->input()['starts_at'], 'Europe/Zurich');
        $earlier = $this->service->saveEvent($this->admin, $this->tenant, null, $this->input([
            'starts_at' => $start->setTime(10, 0)->format('Y-m-d\TH:i'), 'ends_at' => $start->setTime(11, 0)->format('Y-m-d\TH:i')]));
        $locked = $this->service->saveEvent($this->admin, $this->tenant, null, $this->input(['deadline_at' => now('Europe/Zurich')->subMinute()->format('Y-m-d\TH:i')]));
        $this->service->participate($this->player, $this->tenant, $event, $this->member, ['status' => 'DABEI', 'comment' => 'PRIVATE-PRIOR']);
        $player = $login($this->player);
        $url = '/api/mobile/v1/clubs/'.$this->tenant->id.'/planning/absences';
        $input = ['MitgliederID' => $this->member, 'DatumVon' => $start->subMinutes(15)->toIso8601String(),
            'DatumBis' => $start->addMinutes(30)->toIso8601String(), 'Absenzart' => 'ARBEIT', 'Bemerkung' => 'PRIVATE-ABSENCE'];
        $response = $this->withToken($player)->postJson($url, $input)->assertOk()
            ->assertJsonPath('data.sync.autoApplied', 1)->assertJsonPath('data.sync.autoRestored', 0)->assertJsonPath('data.sync.skippedLocked', 1);
        $id = $response->json('data.Absenz.AbsenzID');
        $this->assertSame('absence', $this->participation($event)->source);
        $this->assertNull($this->participation($earlier));
        $this->assertNull($this->participation($locked));
        $this->assertTrue(CarbonImmutable::parse($response->json('data.Absenz.DatumVon'))->equalTo($start->subMinutes(15)));
        $this->getJson($url.'?MitgliederID='.$this->member)->assertOk()->assertJsonPath('data.TeamAbsenzen.0.CanEdit', true)->assertJsonPath('data.TeamAbsenzen.0.CanDelete', true);
        $this->getJson($url.'?recperpage=101')->assertUnprocessable();
        $this->patchJson($url.'/'.$id, $input)->assertUnprocessable();
        $this->patchJson($url.'/'.$id, array_replace($input, ['Revision' => 1, 'MitgliederID' => $this->otherMember]))->assertUnprocessable();
        $this->postJson($url, array_replace($input, ['MitgliederID' => $this->otherMember]))->assertForbidden();
        $this->withToken($login($this->trainer))->getJson($url)->assertOk()
            ->assertJsonPath('data.TeamAbsenzen.0.CanEdit', true)->assertJsonPath('data.TeamAbsenzen.0.CanDelete', false);
        $this->deleteJson($url.'/'.$id, ['Revision' => 1])->assertForbidden();
        $outsideAccount = $this->account('member');
        $outsideMember = $this->db->table('members')->insertGetId(['tenant_id' => $this->tenant->id, 'first_name' => 'Outside', 'last_name' => 'Fixture', 'birth_date' => '2012-01-01']);
        $this->db->table('account_member_links')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $outsideAccount->id,
            'member_id' => $outsideMember, 'kind' => 'parent', 'state' => 'approved', 'source' => 'test']);
        $outsider = $login($outsideAccount);
        $this->withToken($outsider)->getJson($url.'?MitgliederID='.$this->member)->assertOk()->assertJsonPath('data.TeamAbsenzen', []);
        $this->patchJson($url.'/'.$id, $input + ['Revision' => 1])->assertNotFound();
        // Date-only web edits can change a note without erasing precise times.
        $this->service->saveAbsence($this->admin, $this->tenant, $id, $this->absence([
            'revision' => 1, 'starts_on' => $start->toDateString(), 'ends_on' => $start->toDateString(), 'note' => 'PRIVATE-WEB']));
        $this->assertTrue(CarbonImmutable::parse($this->db->table('team_absences')->where('id', $id)->value('starts_at'))->equalTo($start->subMinutes(15)));
        $this->withToken($player)->patchJson($url.'/'.$id, $input + ['Revision' => 1])->assertUnprocessable();
        $this->deleteJson($url.'/'.$id, ['Revision' => 2])->assertOk()
            ->assertJsonPath('data.sync.autoApplied', 0)->assertJsonPath('data.sync.autoRestored', 1)->assertJsonPath('data.sync.skippedLocked', 1);
        $this->assertSame('DABEI', $this->participation($event)->status);
        $this->assertSame('PRIVATE-PRIOR', Crypt::decryptString($this->participation($event)->comment_encrypted));
        $this->deleteJson($url.'/'.$id, ['Revision' => 2])->assertNotFound();
        $this->assertStringNotContainsString('PRIVATE', $this->db->table('object_changes')->where('tenant_id', $this->tenant->id)->get()->toJson());
    }

    public function test_native_mobile_series_batch_is_atomic_idempotent_and_actor_scoped(): void
    {
        config(['clubunio-mobile.enabled' => true, 'cache.default' => 'array']);
        $this->db->statement("SET LOCAL lock_timeout TO '5s'");
        $ddl = $this->db->pretend(fn () => (require database_path('clubunio/2026_11_03_000086_mobile_planning_commands.php'))->up());
        $this->assertCount(1, $ddl);
        $this->db->statement('CREATE TEMP TABLE batch_test_tenants (id bigint PRIMARY KEY) ON COMMIT DROP');
        $this->db->statement('CREATE TEMP TABLE batch_test_accounts (id bigint PRIMARY KEY) ON COMMIT DROP');
        $this->db->table('pg_temp.batch_test_tenants')->insert(['id' => $this->tenant->id]);
        $this->db->table('pg_temp.batch_test_accounts')->insert(array_map(fn ($actor) => ['id' => $actor->id], [$this->admin, $this->player, $this->trainer]));
        $this->db->statement(str_replace(['CREATE TABLE clubunio.mobile_planning_commands', 'REFERENCES clubunio.tenants', 'REFERENCES clubunio.accounts'],
            ['CREATE TEMP TABLE mobile_planning_commands', 'REFERENCES pg_temp.batch_test_tenants', 'REFERENCES pg_temp.batch_test_accounts'], $ddl[0]['query']).' ON COMMIT DROP');
        $this->db->setQueryGrammar(new class($this->db) extends \Illuminate\Database\Query\Grammars\PostgresGrammar {
            public function wrapTable($table, $prefix = null) {
                return parent::wrapTable($table === 'clubunio.mobile_planning_commands' ? 'pg_temp.mobile_planning_commands' : $table, $prefix);
            }
        });
        $login = fn ($actor) => $this->postJson('/api/mobile/v1/session', ['username' => $actor->username, 'password' => 'fixture', 'device_name' => 'Series fixture'])->assertOk()->json('data.token');
        $admin = $login($this->admin);
        $url = '/api/mobile/v1/clubs/'.$this->tenant->id.'/planning/event-batches';
        $input = $this->input();
        $first = ['TeamSaisonID' => $this->team, 'Titel' => 'Batch fixture', 'Terminart' => 'TRAINING', 'TeilnahmeModus' => 'ANMELDEN',
            'DatumVon' => $input['starts_at'], 'DatumBis' => $input['ends_at']]; // Omitted SVS deadline closes at start.
        $second = array_replace($first, ['DatumVon' => CarbonImmutable::parse($input['starts_at'])->addWeek()->format('Y-m-d\TH:i'),
            'DatumBis' => CarbonImmutable::parse($input['ends_at'])->addWeek()->format('Y-m-d\TH:i')]);
        $key = (string) \Illuminate\Support\Str::uuid();
        $body = ['command_key' => $key, 'events' => [$first, $second]];
        $created = $this->withToken($admin)->postJson($url, $body)->assertOk()->assertJsonPath('data.replayed', false)->json('data.TeamTermine');
        $this->assertCount(2, $created);
        $this->assertNotEmpty($created[0]['SerieID']);
        $this->assertSame($created[0]['SerieID'], $created[1]['SerieID']);
        $this->assertTrue(CarbonImmutable::parse($created[0]['DatumVon'])->equalTo(CarbonImmutable::parse($created[0]['AnmeldeFrist'])));
        $again = $this->postJson($url, $body)->assertOk()->assertJsonPath('data.replayed', true)->json('data.TeamTermine');
        $this->assertSame(array_column($created, 'TeamTerminID'), array_column($again, 'TeamTerminID'));
        $this->assertSame(2, $this->db->table('team_events')->where('tenant_id', $this->tenant->id)->count());
        $changed = $body;
        $changed['events'][0]['Titel'] = 'Changed after uncertain response';
        $this->postJson($url, $changed)->assertConflict();
        $bad = ['command_key' => (string) \Illuminate\Support\Str::uuid(), 'events' => [$first, array_replace($second, ['DatumBis' => $second['DatumVon']])]];
        $this->postJson($url, $bad)->assertUnprocessable();
        $this->assertSame(2, $this->db->table('team_events')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(1, $this->db->table('pg_temp.mobile_planning_commands')->count());
        $this->assertSame(2, $this->db->table('team_planning_messages')->where('tenant_id', $this->tenant->id)->count());
        $this->postJson($url, ['command_key' => (string) \Illuminate\Support\Str::uuid(), 'events' => [$first, $first]])->assertUnprocessable();
        $this->postJson($url, ['command_key' => (string) \Illuminate\Support\Str::uuid(), 'events' => array_fill(0, 105, $first)])->assertUnprocessable();
        $this->withToken($login($this->player))->postJson($url, $body)->assertForbidden();
        $this->withToken($login($this->trainer))->postJson($url, $body)->assertOk()->assertJsonPath('data.replayed', false);
        $this->assertSame(4, $this->db->table('team_events')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(2, $this->db->table('pg_temp.mobile_planning_commands')->count());
    }

    public function test_native_mobile_future_series_checks_full_snapshot_and_preserves_exceptions(): void
    {
        config(['clubunio-mobile.enabled' => true, 'cache.default' => 'array']);
        $ids = $this->service->createSeries($this->admin, $this->tenant, $this->input(['repeat_count' => 5]));
        $exception = $this->service->event($this->admin, $this->tenant, $ids[1], true);
        $exceptionInput = array_intersect_key((array) $exception, array_flip(['team_season_id', 'title', 'kind', 'mode', 'status', 'capacity', 'location', 'description', 'revision']));
        foreach (['starts_at', 'ends_at', 'deadline_at'] as $field) { $exceptionInput[$field] = CarbonImmutable::parse($exception->$field)->tz('Europe/Zurich')->format('Y-m-d\TH:i'); }
        $this->service->saveEvent($this->admin, $this->tenant, $ids[1], array_replace($exceptionInput, ['title' => 'Keep individual exception']));
        $this->db->table('team_events')->where('id', $ids[3])->update(['deadline_at' => now()->subMinute()->toIso8601String()]);
        foreach ([$this->member, $this->otherMember] as $member) {
            $this->service->participate($this->trainer, $this->tenant, $ids[2], $member, ['status' => 'DABEI', 'attendance' => 'OFFEN']);
        }
        $token = $this->postJson('/api/mobile/v1/session', ['username' => $this->admin->username, 'password' => 'fixture', 'device_name' => 'Series update fixture'])->assertOk()->json('data.token');
        $base = '/api/mobile/v1/clubs/'.$this->tenant->id.'/planning/events/';
        $event = $this->withToken($token)->getJson($base.$ids[0])->assertOk()->json('data.TeamTermin');
        $from = CarbonImmutable::parse($event['DatumVon'])->tz('Europe/Zurich')->addDay()->setTime(19, 0);
        $input = ['Revision' => 1, 'scope' => 'future', 'Titel' => 'Updated future series', 'DatumVon' => $from->toIso8601String(),
            'DatumBis' => $from->addMinutes(90)->toIso8601String(), 'AnmeldeFrist' => $from->subHours(2)->toIso8601String(), 'Ort' => '', 'Beschreibung' => '', 'MaxTeilnehmer' => 3];
        $this->patchJson($base.$ids[0], $input)->assertUnprocessable();
        $input['SeriesRevision'] = $event['SeriesRevision'];
        $this->patchJson($base.$ids[0], array_replace($input, ['TeamSaisonID' => $this->team + 100000]))->assertUnprocessable();
        $this->db->table('team_events')->where('id', $ids[4])->increment('revision');
        $this->patchJson($base.$ids[0], $input)->assertConflict();
        $event = $this->getJson($base.$ids[0])->assertOk()->json('data.TeamTermin');
        $input['SeriesRevision'] = $event['SeriesRevision'];
        $this->patchJson($base.$ids[0], array_replace($input, ['MaxTeilnehmer' => 1]))->assertUnprocessable();
        $this->assertSame('Fixture training', $this->db->table('team_events')->where('id', $ids[0])->value('title'));
        $this->assertSame(1, $this->db->table('team_events')->where('id', $ids[0])->value('revision'));
        $response = $this->patchJson($base.$ids[0], $input)->assertOk()->assertJsonPath('data.series.changed', 3)->assertJsonPath('data.series.skipped', 2)->assertJsonPath('data.series.unchanged', 0);
        foreach ([$ids[0], $ids[2], $ids[4]] as $id) {
            $row = $this->db->table('team_events')->where('id', $id)->first();
            $start = CarbonImmutable::parse($row->starts_at)->tz('Europe/Zurich');
            $this->assertSame('19:00', $start->format('H:i'));
            $this->assertSame(90.0, $start->diffInMinutes(CarbonImmutable::parse($row->ends_at)));
            $this->assertSame(120.0, CarbonImmutable::parse($row->deadline_at)->diffInMinutes($start));
            $this->assertFalse($row->series_exception);
        }
        $this->assertSame(CarbonImmutable::parse($event['DatumVon'])->tz('Europe/Zurich')->toDateString(), CarbonImmutable::parse($response->json('data.TeamTermin.DatumVon'))->tz('Europe/Zurich')->toDateString());
        $this->assertSame('Keep individual exception', $this->db->table('team_events')->where('id', $ids[1])->value('title'));
        $this->assertSame('Fixture training', $this->db->table('team_events')->where('id', $ids[3])->value('title'));
        $this->patchJson($base.$ids[0], $input)->assertUnprocessable();
    }

    public function test_personal_my_teams_preserves_team_scope_contacts_current_season_and_training_variant(): void
    {
        config(['clubunio-mobile.enabled' => true, 'cache.default' => 'array']);
        $this->db->table('members')->where('id', $this->member)->update(['email' => 'child@example.invalid', 'mother_name' => 'PRIVATE-CHILD-MOTHER']);
        $this->db->table('members')->where('id', $this->otherMember)->update(['email' => 'coach@example.invalid', 'mother_name' => 'PRIVATE-COACH-MOTHER', 'ahv_encrypted' => Crypt::encryptString('PRIVATE-AHV')]);
        $other = $this->db->table('teams')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => 'Other team']);
        $season = $this->db->table('team_seasons')->where('id', $this->team)->value('season_id');
        $otherTeam = $this->db->table('team_seasons')->insertGetId(['tenant_id' => $this->tenant->id, 'team_id' => $other, 'season_id' => $season, 'label' => 'Foreign team fixture']);
        $past = $this->db->table('seasons')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => 'Past', 'starts_on' => now()->subYears(2)->toDateString(), 'ends_on' => now()->subYear()->toDateString()]);
        $pastTeam = $this->db->table('team_seasons')->insertGetId(['tenant_id' => $this->tenant->id, 'team_id' => $other, 'season_id' => $past, 'label' => 'Past assigned team']);
        $this->db->table('team_players')->insert(['tenant_id' => $this->tenant->id, 'team_season_id' => $pastTeam, 'member_id' => $this->member]);
        foreach ([[$this->team, true, false], [$this->team, false, true], [$otherTeam, false, true]] as [$team, $all, $synthetic]) {
            $this->db->table('training_slots')->insert(['tenant_id' => $this->tenant->id, 'team_season_id' => $team, 'starts_at' => '18:00', 'ends_at' => '19:30', 'all_fields' => $all, 'synthetic_only' => $synthetic]);
        }
        $login = fn ($a) => $this->postJson('/api/mobile/v1/session', ['username' => $a->username, 'password' => 'fixture', 'device_name' => 'Teams fixture'])->assertOk()->json('data.token');
        $base = '/api/mobile/v1/clubs/'.$this->tenant->id.'/my-teams';
        $parent = $this->account('member');
        $this->db->table('account_member_links')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $parent->id, 'member_id' => $this->member, 'kind' => 'parent', 'state' => 'approved', 'source' => 'test']);
        $this->withToken($login($parent))->getJson($base)->assertOk()->assertJsonPath('data.totalRecordCount', 1)->assertJsonPath('data.Teams.0.TeamSaisonID', $this->team);
        $details = $this->getJson($base.'/'.$this->team)->assertOk()->assertJsonPath('data.Team.CanAddAssignments', false)->assertJsonPath('data.Team.CanRemoveAssignments', false)->assertJsonCount(1, 'data.Trainings');
        // My Teams uses the scoped roster contract, not the personal child dossier.
        $details->assertJsonPath('data.Players.0.CanOpenDossier', false)
            ->assertJsonPath('data.Players.0.ContactOnly', true)
            ->assertJsonPath('data.Players.0.CanRequestChanges', false)
            ->assertJsonMissingPath('data.Players.0.Email')
            ->assertJsonMissingPath('data.Players.0.Mutter')
            ->assertJsonPath('data.Staff.0.CanOpenDossier', true)
            ->assertJsonPath('data.Staff.0.ContactOnly', true)
            ->assertJsonPath('data.Staff.0.Email', 'coach@example.invalid');
        $this->assertStringNotContainsString('PRIVATE-CHILD-MOTHER', $details->getContent());
        $this->assertStringNotContainsString('child@example.invalid', $details->getContent());
        $this->assertStringNotContainsString('PRIVATE-COACH-MOTHER', $details->getContent());
        $this->assertStringNotContainsString('PRIVATE-AHV', $details->getContent());
        $this->getJson($base.'/'.$otherTeam)->assertNotFound();
        $this->getJson($base.'/'.$pastTeam)->assertNotFound();
        $this->getJson($base.'?recperpage=101')->assertUnprocessable();
        $this->getJson($base.'/members?q=Player')->assertForbidden();
        // A global trainer role without a classified staff function grants no full roster access.
        $helper = $this->withToken($login($this->trainer))->getJson($base.'/'.$this->team)->assertOk()
            ->assertJsonPath('data.Players.0.CanOpenDossier', false)
            ->assertJsonPath('data.Staff.0.IsTrainer', false);
        $this->assertStringNotContainsString('PRIVATE-CHILD-MOTHER', $helper->getContent());
        $this->assertStringNotContainsString('PRIVATE-COACH-MOTHER', $helper->getContent());
        $function = $this->db->table('staff_functions')->insertGetId(['tenant_id' => $this->tenant->id,
            'code' => 'TRAINER', 'name' => 'Fixture trainer', 'active' => true]);
        $this->db->table('team_staff')->where('tenant_id', $this->tenant->id)->where('team_season_id', $this->team)
            ->where('member_id', $this->otherMember)->update(['staff_function_id' => $function]);
        $trainer = $this->getJson($base.'/'.$this->team)->assertOk()
            ->assertJsonPath('data.Players.0.CanOpenDossier', true)
            ->assertJsonPath('data.Players.0.ContactOnly', false)
            ->assertJsonPath('data.Players.0.CanRequestChanges', true)
            ->assertJsonPath('data.Staff.0.IsTrainer', true);
        $this->assertStringContainsString('child@example.invalid', $trainer->getContent());
        $this->assertStringContainsString('PRIVATE-CHILD-MOTHER', $trainer->getContent());
        $this->assertStringContainsString('PRIVATE-COACH-MOTHER', $trainer->getContent());
        $this->assertStringNotContainsString('PRIVATE-AHV', $trainer->getContent());
        $this->withToken($login($this->admin))->getJson($base.'/'.$this->team)->assertOk()->assertJsonPath('data.Team.CanAddAssignments', true)->assertJsonPath('data.Team.CanRemoveAssignments', true);
    }

    public function test_personal_my_teams_assignments_preserve_versions_audit_and_current_permissions(): void
    {
        config(['clubunio-mobile.enabled' => true, 'cache.default' => 'array']);
        $token = $this->postJson('/api/mobile/v1/session', ['username' => $this->admin->username, 'password' => 'fixture', 'device_name' => 'Assignment fixture'])->assertOk()->json('data.token');
        $base = '/api/mobile/v1/clubs/'.$this->tenant->id.'/my-teams';
        $function = $this->db->table('staff_functions')->insertGetId(['tenant_id' => $this->tenant->id, 'code' => 'TRAINER', 'name' => 'Fixture coach']);
        $this->withToken($token)->getJson($base.'/staff-functions')->assertOk()->assertJsonPath('data.Functions.0.TeamSatffFunktionID', $function);
        $this->db->table('members')->where('id', $this->member)->update(['email' => 'lookup@example.invalid']);
        $search = $this->getJson($base.'/members?q=Player')->assertOk();
        $this->assertSame('lookup@example.invalid', $search->json('data.0.Email'));
        $player = $this->postJson($base.'/'.$this->team.'/players', ['MitgliederID' => $this->otherMember])->assertOk()->json('data.Assignment');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $player['_version']);
        $this->postJson($base.'/'.$this->team.'/players', ['MitgliederID' => $this->otherMember])->assertUnprocessable();
        $staff = $this->postJson($base.'/'.$this->team.'/staff', ['MitgliederID' => $this->member, 'TeamSatffFunktionID' => $function])->assertOk()->json('data.Assignment');
        $staffUrl = $base.'/'.$this->team.'/staff/'.$staff['TeamStaffID'];
        $this->deleteJson($staffUrl, [])->assertUnprocessable();
        $this->db->table('team_staff')->where('id', $staff['TeamStaffID'])->update(['updated_at' => now()->addMinute()->toIso8601String()]);
        $this->deleteJson($staffUrl, ['_version' => $staff['_version']])->assertUnprocessable();
        $fresh = $this->getJson($base.'/'.$this->team)->assertOk()->json('data.Staff');
        $this->assertTrue(collect($fresh)->firstWhere('TeamStaffID', $staff['TeamStaffID'])['IsTrainer']);
        $version = collect($fresh)->firstWhere('TeamStaffID', $staff['TeamStaffID'])['_version'];
        $this->deleteJson($staffUrl, ['_version' => $version])->assertOk()->assertJsonPath('data.removed', true);
        $this->deleteJson($staffUrl, ['_version' => $version])->assertNotFound();
        $this->assertFalse($this->db->table('team_staff')->where('id', $staff['TeamStaffID'])->value('active'));
        $this->deleteJson($base.'/'.$this->team.'/players/'.$player['TeamlistID'], ['_version' => $player['_version']])->assertOk();
        $this->assertFalse($this->db->table('team_players')->where('id', $player['TeamlistID'])->value('active'));
        $this->assertGreaterThanOrEqual(4, $this->db->table('object_changes')->where('tenant_id', $this->tenant->id)->count());
        $this->db->table('account_member_links')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $this->admin->id,
            'member_id' => $this->member, 'kind' => 'self', 'state' => 'approved', 'source' => 'test']);
        $this->db->table('tenant_accounts')->where('tenant_id', $this->tenant->id)->where('account_id', $this->admin->id)->update(['role' => 'member']);
        $this->getJson($base.'/'.$this->team)->assertOk()->assertJsonPath('data.Team.CanAddAssignments', false)->assertJsonPath('data.Team.CanRemoveAssignments', false);
        $this->postJson($base.'/'.$this->team.'/players', ['MitgliederID' => $this->otherMember])->assertForbidden();
        $this->getJson($base.'/staff-functions')->assertForbidden();
    }

    public function test_personal_my_teams_registration_details_require_actual_review_scope(): void
    {
        config(['clubunio-mobile.enabled' => true, 'cache.default' => 'array']);
        $status = $this->db->table('registration_statuses')->insertGetId(['tenant_id' => $this->tenant->id, 'code' => 'REV', 'name' => 'Review', 'workflow_key' => 'review']);
        $this->db->table('registrations')->insert(['tenant_id' => $this->tenant->id, 'legacy_id' => 900001, 'team_season_id' => $this->team,
            'first_name' => 'Applicant', 'last_name' => 'Fixture', 'email' => 'applicant@example.invalid', 'birth_date' => '2012-01-01',
            'status_id' => $status, 'active' => true, 'applicant_encrypted' => Crypt::encryptString(json_encode([
                'mother_name' => 'PRIVATE-APPLICANT-MOTHER', 'notes' => 'PRIVATE-APPLICANT-NOTE', 'ahv' => 'PRIVATE-NOT-FOR-TEAM-DTO']))]);
        $login = fn ($a) => $this->postJson('/api/mobile/v1/session', ['username' => $a->username, 'password' => 'fixture', 'device_name' => 'Registration fixture'])->assertOk()->json('data.token');
        $url = '/api/mobile/v1/clubs/'.$this->tenant->id.'/my-teams/'.$this->team;
        $this->withToken($login($this->player))->getJson($url)->assertOk()->assertJsonPath('data.Registrations', []);
        $this->withToken($login($this->trainer))->getJson($url)->assertOk()->assertJsonPath('data.Registrations', []);
        $leader = $this->account('technical_lead');
        $this->db->table('account_member_links')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $leader->id, 'member_id' => $this->otherMember, 'kind' => 'self', 'state' => 'approved', 'source' => 'test']);
        $this->db->table('technical_leads')->insert(['tenant_id' => $this->tenant->id, 'team_season_id' => $this->team, 'member_id' => $this->otherMember]);
        $response = $this->withToken($login($leader))->getJson($url)->assertOk()->assertJsonPath('data.Registrations.0.StatusText', 'Review');
        $this->assertStringContainsString('PRIVATE-APPLICANT-MOTHER', $response->getContent());
        $this->assertStringContainsString('PRIVATE-APPLICANT-NOTE', $response->getContent());
        $this->assertStringNotContainsString('PRIVATE-NOT-FOR-TEAM-DTO', $response->getContent());
        $this->db->table('technical_leads')->where('tenant_id', $this->tenant->id)->update(['active' => false]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.Registrations', []); // Still own staff team, no review grant.
    }

    public function test_native_mobile_removal_hides_series_without_deleting_history_or_sending_messages(): void
    {
        config(['clubunio-mobile.enabled' => true, 'cache.default' => 'array']);
        $start = CarbonImmutable::now('Europe/Zurich')->subDay()->setTime(18, 0);
        $ids = $this->service->createSeries($this->admin, $this->tenant, $this->input(['repeat_count' => 3,
            'starts_at' => $start->format('Y-m-d\TH:i'), 'ends_at' => $start->addHour()->format('Y-m-d\TH:i'),
            'deadline_at' => $start->subHour()->format('Y-m-d\TH:i')]));
        $this->service->participate($this->trainer, $this->tenant, $ids[1], $this->member,
            ['status' => 'DABEI', 'attendance' => 'OFFEN', 'comment' => 'PRIVATE-RETAINED']);
        $this->db->table('team_events')->where('id', $ids[1])->update(['series_exception' => true]);
        $this->db->table('team_events')->where('id', $ids[2])->update(['deadline_at' => now()->subMinute()->toIso8601String()]);
        $this->service = $this->temporaryPlanningRemovalSchema($this->db, $this->tenant->id);
        $login = fn ($actor) => $this->postJson('/api/mobile/v1/session', ['username' => $actor->username, 'password' => 'fixture', 'device_name' => 'Removal fixture'])->assertOk()->json('data.token');
        $admin = $login($this->admin);
        $base = '/api/mobile/v1/clubs/'.$this->tenant->id.'/planning/events';
        $event = $this->withToken($admin)->getJson($base.'/'.$ids[0])->assertOk()->json('data.TeamTermin');
        $input = ['Revision' => 1, 'scope' => 'future', 'SeriesRevision' => $event['SeriesRevision']];
        $this->withToken($login($this->player))->deleteJson($base.'/'.$ids[1], ['Revision' => 1, 'scope' => 'single'])->assertNotFound();
        $this->withToken($admin)->deleteJson($base.'/'.$ids[0], ['Revision' => 1, 'scope' => 'future'])->assertUnprocessable();
        $this->db->table('team_events')->where('id', $ids[2])->increment('revision');
        $this->deleteJson($base.'/'.$ids[0], $input)->assertConflict();
        $input['SeriesRevision'] = $this->getJson($base.'/'.$ids[0])->assertOk()->json('data.TeamTermin.SeriesRevision');
        $messages = $this->db->table('team_planning_messages')->where('tenant_id', $this->tenant->id)->count();
        $this->deleteJson($base.'/'.$ids[0], $input)->assertOk()->assertJsonPath('data.removed_count', 2)->assertJsonPath('data.pushWarning', false);
        $this->assertSame(2, $this->db->table('pg_temp.team_events')->whereNotNull('deleted_at')->count());
        $this->getJson($base.'/'.$ids[1])->assertNotFound();
        $this->getJson($base)->assertOk()->assertJsonPath('data.totalRecordCount', 1);
        $this->assertSame('PRIVATE-RETAINED', Crypt::decryptString($this->db->table('team_participations')->where('event_id', $ids[1])->value('comment_encrypted')));
        $this->assertSame($messages, $this->db->table('team_planning_messages')->where('tenant_id', $this->tenant->id)->count());
        $snapshot = $this->getJson($base.'/'.$ids[0])->assertOk()->json('data.TeamTermin.SeriesRevision');
        $this->deleteJson($base.'/'.$ids[0], array_replace($input, ['SeriesRevision' => $snapshot]))->assertConflict();
        $this->deleteJson($base.'/'.$ids[0], ['Revision' => 1, 'scope' => 'all', 'SeriesRevision' => $snapshot])->assertOk()->assertJsonPath('data.removed_count', 1);
        $this->getJson($base)->assertOk()->assertJsonPath('data.totalRecordCount', 0);
        $this->deleteJson($base.'/'.$ids[0], ['Revision' => 1, 'scope' => 'single'])->assertNotFound();
        $this->get('/planning/calendar?date='.$start->toDateString())->assertOk()->assertDontSee('Fixture training');
        $this->assertSame(0, $this->service->reminders($this->tenant));
        $this->assertSame($messages, $this->db->table('team_planning_messages')->where('tenant_id', $this->tenant->id)->count());
        $team = $this->db->table('team_seasons')->where('id', $this->team)->value('team_id');
        $this->db->table('teams')->where('id', $team)->update(['sfv_team_id' => 123]);
        $schedule = $this->db->table('sfv_schedule')->insertGetId(['tenant_id' => $this->tenant->id, 'match_id' => 987654,
            'season_id' => 2027, 'team_a_id' => 123, 'team_b_id' => 456, 'title' => 'Removed SFV fixture', 'home' => true]);
        $this->db->table('team_events')->where('id', $ids[1])->update(['sfv_match_id' => 987654]);
        try {
            app(SfvPlanningService::class)->import($this->admin, $this->tenant, $schedule, $this->input() + ['sfv_revision' => 1]);
            $this->fail('Removed SFV event was recreated');
        } catch (HttpException $e) { $this->assertSame(409, $e->getStatusCode()); }
        $this->assertSame(3, $this->db->table('pg_temp.team_events')->count());
        $this->assertStringNotContainsString('PRIVATE-RETAINED', $this->db->table('object_changes')->where('tenant_id', $this->tenant->id)->get()->toJson());
    }

}
