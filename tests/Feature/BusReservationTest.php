<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\BusKeyboxAccessService;
use App\ClubUnio\Services\BusReservationService;
use App\ClubUnio\Services\MessageTemplateService;
use App\ClubUnio\Services\PlanningCalendarService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BusReservationTest extends TestCase
{
    private $db;

    private Tenant $tenant;

    private Account $office;

    private Account $trainer;

    private int $member;

    private int $bus;

    private int $team;

    private BusReservationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Isolated database opt-in required');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->db->statement('SET LOCAL search_path TO clubora');
        $this->tenant = Tenant::create(['slug' => 'bus-'.Str::random(10), 'name' => 'Bus fixture']);
        $this->office = $this->account('office');
        $this->trainer = $this->account('trainer');
        $this->member = $this->insert('members', ['first_name' => 'Bus', 'last_name' => 'Applicant']);
        $this->insert('account_member_links', ['account_id' => $this->trainer->id, 'member_id' => $this->member, 'kind' => 'self', 'state' => 'approved', 'source' => 'test']);
        $season = $this->insert('seasons', ['name' => 'Fixture season']);
        $team = $this->insert('teams', ['name' => 'Fixture team']);
        $this->team = $this->insert('team_seasons', ['season_id' => $season, 'team_id' => $team, 'label' => 'Fixture / Season']);
        $this->insert('team_staff', ['member_id' => $this->member, 'team_season_id' => $this->team]);
        $this->bus = $this->insert('buses', ['name' => 'Fixture bus']);
        foreach (['ANGEFRAGT', 'GENEHMIGT', 'ABGELEHNT', 'STORNIERT', 'UNBEKANNT'] as $status) {
            $this->insert('bus_statuses', ['code' => $status, 'name' => $status]);
        }
        app(MessageTemplateService::class)->seed($this->tenant);
        $this->service = app(BusReservationService::class);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        if ($this->db?->transactionLevel()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    private function insert(string $table, array $data): int
    {
        return $this->db->table($table)->insertGetId(['tenant_id' => $this->tenant->id] + $data);
    }

    private function account(string $role): Account
    {
        $a = new Account;
        $a->forceFill(['username' => Str::random(15).'@example.invalid', 'display_name' => 'Fixture', 'password' => bcrypt('test'), 'active' => true])->save();
        $this->db->table('tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $a->id, 'role' => $role, 'portal_enabled' => true]);

        return $a;
    }

    private function input(array $extra = []): array
    {
        return $extra + ['bus_id' => $this->bus, 'team_season_id' => $this->team, 'member_id' => $this->member, 'starts_at' => now('Europe/Zurich')->addDays(3)->setTime(10, 0)->format('Y-m-d\TH:i'), 'ends_at' => now('Europe/Zurich')->addDays(3)->setTime(12, 0)->format('Y-m-d\TH:i'), 'status' => 'GENEHMIGT', 'location' => 'Test destination', 'description' => 'Fixture only'];
    }

    public function test_request_approval_audit_and_notifications_are_internal_and_once(): void
    {
        $id = $this->service->create($this->trainer, $this->tenant, $this->input());
        $this->assertSame('ANGEFRAGT', $this->db->table('bus_reservations')->where('id', $id)->value('status'));
        $this->assertSame([$this->office->id], $this->db->table('notifications')->where('tenant_id', $this->tenant->id)->pluck('account_id')->all());
        $this->service->decide($this->office, $this->tenant, $id, 1, 'GENEHMIGT', 'Approved fixture');
        $this->assertSame('GENEHMIGT', $this->db->table('bus_reservations')->where('id', $id)->value('status'));
        $this->assertSame(2, $this->db->table('object_changes')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(1, $this->db->table('notifications')->where('account_id', $this->trainer->id)->count());
        try {
            $this->service->decide($this->office, $this->tenant, $id, 1, 'GENEHMIGT', null);
            $this->fail('Stale decision accepted');
        } catch (ValidationException) {
        }
        $this->assertSame(2, $this->db->table('notifications')->where('tenant_id', $this->tenant->id)->count());
        Http::assertNothingSent();
    }

    public function test_historical_assignment_is_office_only_audited_and_does_not_notify(): void
    {
        $id = $this->insert('bus_reservations', ['legacy_id' => 900001, 'bus_id' => $this->bus, 'status' => 'GENEHMIGT',
            'starts_at' => '2020-01-01 10:00:00+01', 'ends_at' => '2020-01-01 12:00:00+01']);
        $url = '/bus/'.$this->tenant->slug.'/'.$id.'/assignment';
        $data = ['revision' => 1, 'member_id' => $this->member, 'team_season_id' => $this->team, 'reason' => 'Verified original application'];
        $this->actingAs($this->trainer, 'clubunio')->get($url)->assertForbidden();
        $this->post($url, $data)->assertForbidden();
        $this->actingAs($this->office, 'clubunio')->get($url)->assertOk();
        $this->post($url, $data + [])->assertRedirect();
        $row = $this->db->table('bus_reservations')->find($id);
        $this->assertSame($this->team, $row->team_season_id);
        $this->assertSame($this->member, $row->member_id);
        $this->assertSame('GENEHMIGT', $row->status);
        $this->assertSame(2, $row->revision);
        $this->post($url, $data)->assertNotFound();
        $this->assertSame(0, $this->db->table('notifications')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(1, $this->db->table('object_changes')->where('tenant_id', $this->tenant->id)->count());
        Http::assertNothingSent();
    }

    public function test_historical_assignment_rejects_stale_and_overwritten_links(): void
    {
        $id = $this->insert('bus_reservations', ['legacy_id' => 900002, 'bus_id' => $this->bus, 'member_id' => $this->member, 'status' => 'GENEHMIGT',
            'starts_at' => '2020-01-01 10:00:00+01', 'ends_at' => '2020-01-01 12:00:00+01', 'revision' => 2]);
        $url = '/bus/'.$this->tenant->slug.'/'.$id.'/assignment';
        $this->actingAs($this->office, 'clubunio');
        $this->post($url, ['revision' => 1, 'member_id' => $this->member, 'team_season_id' => $this->team, 'reason' => 'Test'])->assertSessionHasErrors('revision');
        $this->post($url, ['revision' => 2, 'member_id' => $this->member + 1000000, 'team_season_id' => $this->team, 'reason' => 'Test'])->assertSessionHasErrors('member_id');
        $this->post($url, ['revision' => 2, 'member_id' => $this->member, 'team_season_id' => $this->team + 1000000, 'reason' => 'Test'])->assertStatus(422);
        $this->assertNull($this->db->table('bus_reservations')->where('id', $id)->value('team_season_id'));
    }

    public function test_overlaps_block_but_adjacent_intervals_are_allowed(): void
    {
        $input = $this->input();
        $this->service->create($this->trainer, $this->tenant, $input);
        try {
            $this->service->create($this->trainer, $this->tenant, $input);
            $this->fail('Overlap accepted');
        } catch (ValidationException) {
        }
        $next = $this->service->create($this->trainer, $this->tenant, $this->input(['starts_at' => $input['ends_at'], 'ends_at' => now('Europe/Zurich')->addDays(3)->setTime(13, 0)->format('Y-m-d\TH:i')]));
        $this->assertGreaterThan(0, $next);
        $this->assertSame(2, $this->db->table('bus_reservations')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_trainer_cannot_impersonate_or_approve_and_can_cancel_own_request(): void
    {
        $other = $this->insert('members', ['first_name' => 'Other', 'last_name' => 'Fixture']);
        $this->actingAs($this->trainer, 'portal');
        $this->post(route('clubunio.bus.store', $this->tenant->slug), $this->input(['member_id' => $other]))->assertForbidden();
        $id = $this->service->create($this->trainer, $this->tenant, $this->input());
        $this->post(route('clubunio.bus.decide', [$this->tenant->slug, $id]), ['revision' => 1, 'status' => 'GENEHMIGT'])->assertForbidden();
        $this->post(route('clubunio.bus.decide', [$this->tenant->slug, $id]), ['revision' => 1, 'status' => 'STORNIERT'])->assertRedirect();
        $this->assertSame('STORNIERT', $this->db->table('bus_reservations')->where('id', $id)->value('status'));
    }

    public function test_foreign_team_and_other_members_reservations_are_not_accessible(): void
    {
        $foreign = $this->insert('team_seasons', ['season_id' => $this->db->table('team_seasons')->where('id', $this->team)->value('season_id'), 'team_id' => $this->insert('teams', ['name' => 'Other']), 'label' => 'Other team']);
        $other = $this->insert('members', ['first_name' => 'Other', 'last_name' => 'Fixture']);
        $id = $this->service->create($this->office, $this->tenant, $this->input(['team_season_id' => $foreign, 'member_id' => $other]));
        $this->actingAs($this->trainer, 'portal');
        $this->get(route('clubunio.bus.show', [$this->tenant->slug, $id]))->assertNotFound();
        $this->post(route('clubunio.bus.store', $this->tenant->slug), $this->input(['team_season_id' => $foreign]))->assertForbidden();
        $alien = Tenant::create(['slug' => 'alien-'.Str::random(10), 'name' => 'Other club']);
        $this->get(route('clubunio.bus.index', $alien->slug))->assertForbidden();
    }

    public function test_office_web_forms_filters_details_and_notification_link(): void
    {
        $id = $this->service->create($this->office, $this->tenant, $this->input());
        $this->actingAs($this->office, 'clubunio');
        $this->get(route('clubunio.bus.create', $this->tenant->slug))->assertOk()->assertSee('Fixture / Season');
        $this->get(route('clubunio.bus.index', $this->tenant->slug).'?status=GENEHMIGT&q=Test')->assertOk()->assertSee('Test destination')->assertSee('name="status" value="GENEHMIGT"', false);
        $this->get(route('clubunio.bus.index', $this->tenant->slug).'?status=ABGELEHNT&q=Test')->assertOk()->assertDontSee('Test destination');
        $this->get(route('clubunio.bus.show', [$this->tenant->slug, $id]))->assertOk()->assertSee('Applicant');
        $this->actingAs($this->trainer, 'portal');
        $this->get(route('clubunio.portal.notifications', $this->tenant->slug))->assertOk()->assertSee('Reservation öffnen');
    }

    public function test_lookup_edits_are_office_only_audited_and_preserve_codes(): void
    {
        $this->actingAs($this->trainer, 'portal');
        $this->get(route('clubunio.bus.options', $this->tenant->slug))->assertForbidden();
        $status = $this->db->table('bus_statuses')->where('tenant_id', $this->tenant->id)->where('code', 'GENEHMIGT')->first();
        $input = ['kind' => 'status', 'id' => $status->id, 'name' => 'Genehmigt', 'active' => 1, 'version' => BusReservationService::optionVersion($status), 'code' => 'STORNIERT'];
        $this->service->saveOption($this->office, $this->tenant, $input);
        $this->assertSame('GENEHMIGT', $this->db->table('bus_statuses')->where('id', $status->id)->value('code'));
        try {
            $this->service->saveOption($this->office, $this->tenant, $input);
            $this->fail('Stale setting accepted');
        } catch (ValidationException) {
        }
        $this->service->saveOption($this->office, $this->tenant, ['kind' => 'bus', 'name' => 'Second bus', 'active' => 1]);
        $this->assertSame(2, $this->db->table('buses')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(2, $this->db->table('object_changes')->where('tenant_id', $this->tenant->id)->count());
        $this->actingAs($this->office, 'clubunio');
        $this->get(route('clubunio.bus.options', $this->tenant->slug))->assertOk()->assertSee('Second bus');
    }

    public function test_ended_seasons_are_blocked_and_tl_can_request_only_assigned_team(): void
    {
        $this->db->table('tenant_accounts')->where('account_id', $this->trainer->id)->update(['role' => 'technical_lead']);
        $this->insert('technical_leads', ['member_id' => $this->member, 'team_season_id' => $this->team]);
        $id = $this->service->create($this->trainer, $this->tenant, $this->input());
        $this->assertGreaterThan(0, $id);
        $season = $this->db->table('team_seasons')->where('id', $this->team)->value('season_id');
        $this->db->table('seasons')->where('id', $season)->update(['ends_on' => now()->subDay()->toDateString()]);
        $this->assertSame(0, $this->service->reservableTeams($this->trainer, $this->tenant)->count());
        $this->expectException(ValidationException::class);
        $this->service->decide($this->office, $this->tenant, $id, 1, 'GENEHMIGT', null);
    }

    public function test_approval_rechecks_active_bus_and_does_not_send_on_failure(): void
    {
        $id = $this->service->create($this->trainer, $this->tenant, $this->input());
        $this->db->table('buses')->where('id', $this->bus)->update(['active' => false]);
        try {
            $this->service->decide($this->office, $this->tenant, $id, 1, 'GENEHMIGT', null);
            $this->fail('Inactive bus accepted');
        } catch (ValidationException) {
        }
        $this->assertSame('ANGEFRAGT', $this->db->table('bus_reservations')->where('id', $id)->value('status'));
        $this->assertSame(1, $this->db->table('notifications')->where('tenant_id', $this->tenant->id)->count());
    }

    private function rescheduleInput(array $extra = []): array
    {
        return $extra + array_intersect_key($this->input(), array_flip(['bus_id', 'starts_at', 'ends_at', 'location', 'description'])) + ['revision' => 1, 'reason' => 'Fixture change'];
    }

    public function test_reschedule_preserves_status_audits_reason_and_rejects_stale_revision(): void
    {
        $id = $this->service->create($this->trainer, $this->tenant, $this->input());
        $input = $this->rescheduleInput(['location' => 'Changed destination']);
        $this->service->reschedule($this->trainer, $this->tenant, $id, $input);
        $row = $this->db->table('bus_reservations')->where('id', $id)->first();
        $this->assertSame('ANGEFRAGT', $row->status);
        $this->assertSame(2, $row->revision);
        $this->assertSame('Changed destination', $row->location);
        $this->assertSame(2, $this->db->table('notifications')->where('tenant_id', $this->tenant->id)->count());
        $audit = $this->db->table('object_changes')->where('tenant_id', $this->tenant->id)->orderByDesc('id')->value('after_value');
        $this->assertSame('Fixture change', json_decode($audit, true)['reason']);
        $this->service->reschedule($this->trainer, $this->tenant, $id, array_replace($input, ['revision' => 2]));
        $this->assertSame(2, $this->db->table('notifications')->where('tenant_id', $this->tenant->id)->count());
        try {
            $this->service->reschedule($this->trainer, $this->tenant, $id, $input);
            $this->fail('Stale reschedule accepted');
        } catch (ValidationException) {
        }
        $this->actingAs($this->trainer, 'portal')->get(route('clubunio.bus.edit', [$this->tenant->slug, $id]))->assertOk()->assertSee('Umbuchung');
        $this->service->decide($this->office, $this->tenant, $id, 2, 'GENEHMIGT', null);
        $this->post(route('clubunio.bus.reschedule', [$this->tenant->slug, $id]), array_replace($input, ['revision' => 3]))->assertForbidden();
        $this->service->reschedule($this->office, $this->tenant, $id, array_replace($input, ['revision' => 3, 'location' => 'Office destination']));
        $this->assertSame('GENEHMIGT', $this->db->table('bus_reservations')->where('id', $id)->value('status'));
        $this->assertSame(2, $this->db->table('notifications')->where('tenant_id', $this->tenant->id)->where('account_id', $this->trainer->id)->count());
        Http::assertNothingSent();
    }

    public function test_reschedule_conflict_and_keybox_preview_are_safe(): void
    {
        $id = $this->service->create($this->office, $this->tenant, $this->input());
        $next = $this->input(['starts_at' => now('Europe/Zurich')->addDays(4)->setTime(10, 0)->format('Y-m-d\TH:i'), 'ends_at' => now('Europe/Zurich')->addDays(4)->setTime(12, 0)->format('Y-m-d\TH:i')]);
        $this->service->create($this->office, $this->tenant, $next);
        try {
            $this->service->reschedule($this->office, $this->tenant, $id, $this->rescheduleInput(['starts_at' => $next['starts_at'], 'ends_at' => $next['ends_at']]));
            $this->fail('Reschedule overlap accepted');
        } catch (ValidationException) {
        }
        $this->assertSame(1, $this->db->table('bus_reservations')->where('id', $id)->value('revision'));
        $keybox = app(BusKeyboxAccessService::class);
        $preview = $keybox->inspect($this->trainer, $this->tenant, $id);
        $this->assertTrue($preview['eligible']);
        $this->assertFalse($preview['issuanceEnabled']);
        $this->assertFalse($preview['inWindow']);
        $this->assertSame('06:00', $preview['from']->format('H:i'));
        $this->travelTo($preview['from']);
        $this->assertTrue($keybox->inspect($this->trainer, $this->tenant, $id)['inWindow']);
        $this->travelBack();
        $this->service->decide($this->office, $this->tenant, $id, 1, 'STORNIERT', null);
        $this->assertFalse($keybox->inspect($this->trainer, $this->tenant, $id)['eligible']);
        Http::assertNothingSent();
    }

    public function test_calendar_bus_opt_in_filter_export_and_privacy(): void
    {
        $id = $this->service->create($this->trainer, $this->tenant, $this->input());
        $calendar = app(PlanningCalendarService::class);
        $filters = ['date' => now('Europe/Zurich')->addDays(3)->toDateString(), 'view' => 'week'];
        $this->assertCount(0, $calendar->calendar($this->trainer, $this->tenant, $filters)['busReservations']);
        $filters['buses'] = 1;
        $this->assertSame([$id], $calendar->calendar($this->trainer, $this->tenant, $filters)['busReservations']->pluck('id')->all());
        $this->assertCount(0, $calendar->calendar($this->trainer, $this->tenant, $filters + ['q' => 'Not matching'])['busReservations']);
        $ics = $calendar->export($this->trainer, $this->tenant, $filters);
        $this->assertStringContainsString('clubora-bus-'.$this->tenant->id.'-'.$id.'@clubora', $ics);
        $this->assertStringContainsString('STATUS:TENTATIVE', $ics);
        $this->assertStringNotContainsString('Fixture only', $ics);
        $this->assertStringNotContainsString('Applicant', $ics);
        $this->actingAs($this->trainer, 'portal')->get(route('clubunio.planning.calendar', [$this->tenant->slug] + $filters))->assertOk()->assertSee('Busreservierungen (1)')->assertSee('Fixture bus');
        $other = $this->account('member');
        $otherMember = $this->insert('members', ['first_name' => 'Other', 'last_name' => 'Calendar']);
        $this->insert('account_member_links', ['account_id' => $other->id, 'member_id' => $otherMember, 'kind' => 'self', 'state' => 'approved', 'source' => 'test']);
        $this->assertCount(0, $calendar->calendar($other, $this->tenant, $filters)['busReservations']);
    }
}
