<?php

namespace Tests\Feature;

use App\ClubUnio\Http\SponsorRunPublicController;
use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\SponsorRunEvent;
use App\ClubUnio\Models\SponsorRunParticipant;
use App\ClubUnio\Models\SponsorRunPledge;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Pages\SponsorRuns;
use App\ClubUnio\Services\AccessProfileService;
use App\ClubUnio\Services\SponsorRunPublicService;
use App\ClubUnio\Services\SponsorRunService;
use App\ClubUnio\Services\TestMailGuard;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SponsorRunTest extends TestCase
{
    private $db;

    private Tenant $tenant;

    private Account $admin;

    private Account $parent;

    private Member $child;

    private int $season;

    private int $team;

    private SponsorRunService $core;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Transactional opt-in required');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        if (! Schema::connection('clubunio')->hasTable('clubora.sponsor_run_events')) {
            $this->markTestSkipped('Reviewed schema 74 must be applied by parent first');
        }
        $this->db->beginTransaction();
        $this->travelTo(now('Europe/Zurich')->setDate(2032, 5, 14)->setTime(12, 0));
        $this->core = app(SponsorRunService::class);
        $this->tenant = Tenant::create(['slug' => 'sponsor-test-'.Str::random(12), 'name' => 'Synthetic Sponsor Run']);
        foreach (['draft', 'open', 'closed', 'cancelled'] as $i => $status) {
            $this->insert('sponsor_run_statuses', ['code' => $status, 'name' => $status, 'sort_order' => $i]);
        }
        $this->admin = $this->account('admin');
        $this->parent = $this->account('member');
        $this->child = $this->member('Child', '2015-05-15');
        $this->link($this->parent, $this->child, 'parent');
        $this->season = $this->insert('seasons', ['name' => 'Synthetic season']);
        $this->team = $this->team('Juniors');
        $this->insert('team_players', ['team_season_id' => $this->team, 'member_id' => $this->child->id]);
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $this->actingAs($this->admin, 'clubunio');
        Filament::setTenant($this->tenant);
        Http::preventStrayRequests();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            while ($this->db->transactionLevel()) {
                $this->db->rollBack();
            }
        }
        $this->travelBack();
        parent::tearDown();
    }

    private function insert(string $table, array $row): int
    {
        return $this->db->table('clubora.'.$table)->insertGetId($row + ['tenant_id' => $this->tenant->id]);
    }

    private function account(string $role, ?int $profileId = null): Account
    {
        $a = new Account;
        $a->forceFill(['username' => Str::random(24), 'display_name' => 'Synthetic '.$role, 'password' => bcrypt('fixture'), 'active' => true])->save();
        $this->insert('tenant_accounts', ['account_id' => $a->id, 'role' => $role, 'portal_enabled' => $role !== 'profile', 'access_profile_id' => $profileId]);

        return $a;
    }

    private function member(string $name, string $birthday = '2000-01-01'): Member
    {
        $m = new Member;
        $m->forceFill(['tenant_id' => $this->tenant->id, 'first_name' => $name, 'last_name' => 'Synthetic', 'birth_date' => $birthday])->save();

        return $m;
    }

    private function link(Account $a, Member $m, string $kind = 'self'): void
    {
        $this->insert('account_member_links', ['account_id' => $a->id, 'member_id' => $m->id, 'kind' => $kind, 'state' => 'approved', 'source' => 'test']);
    }

    private function team(string $name): int
    {
        return $this->insert('team_seasons', ['team_id' => $this->insert('teams', ['name' => $name]), 'season_id' => $this->season, 'label' => $name]);
    }

    private function eventInput(array $changes = []): array
    {
        return $changes + ['season_id' => $this->season, 'title' => 'Synthetic run', 'place' => 'Synthetic field', 'event_date' => '2032-05-14', 'registration_deadline' => '2032-05-14', 'status_code' => 'open', 'revision' => 0, 'team_ids' => [$this->team]];
    }

    private function event(array $changes = []): SponsorRunEvent
    {
        return $this->core->saveEvent($this->admin, $this->tenant, null, $this->eventInput($changes));
    }

    private function participant(SponsorRunEvent $e): SponsorRunParticipant
    {
        return SponsorRunParticipant::where('tenant_id', $this->tenant->id)->where('event_id', $e->id)->where('member_id', $this->child->id)->sole();
    }

    private function pledgeInput(array $changes = []): array
    {
        return $changes + ['request_key' => (string) Str::uuid(), 'sponsor_name' => 'Synthetic sponsor', 'payment_mode' => 'invoice', 'amount_type' => 'per_lap', 'amount_cents' => 125, 'cap_cents' => 500,
            'recipient_type' => 'person', 'company' => '', 'first_name' => 'Private', 'last_name' => 'Recipient', 'street' => 'Secret street', 'house_number' => '9', 'postal_code' => '0000', 'city' => 'Synthetic city', 'country' => 'CH', 'email' => 'sponsor@example.invalid'];
    }

    private function assertHttpStatus(int $code, callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected HTTP '.$code);
        } catch (HttpException $e) {
            $this->assertSame($code, $e->getStatusCode());
        }
    }

    private function publicLink(SponsorRunParticipant $p, ?Account $actor = null): array
    {
        $service = app(SponsorRunPublicService::class);
        $service->configurePublic($this->admin, $this->tenant, true);
        $result = $service->share($actor ?? $this->parent, $this->tenant, $p->id, 'create', ['consent' => true, 'alias' => 'Consented alias']);

        return [$service, parse_url($result['url'], PHP_URL_FRAGMENT)];
    }

    private function confirmation(): string
    {
        $row = $this->db->table('clubora.sponsor_run_public_outbox')->where('tenant_id', $this->tenant->id)->orderByDesc('id')->first();
        $payload = json_decode(Crypt::decryptString($row->payload_encrypted), true);
        $this->assertSame(TestMailGuard::RECIPIENT, $payload['to']);
        $this->assertStringNotContainsString('sponsor@example.invalid', $row->payload_encrypted);
        preg_match('/#confirm=([a-f0-9]{64})/', $payload['body'], $matches);

        return $matches[1];
    }

    public function test_roster_is_deduplicated_and_retirement_preserves_pledges(): void
    {
        $second = $this->team('Second');
        $this->insert('team_players', ['team_season_id' => $second, 'member_id' => $this->child->id]);
        $e = $this->event(['team_ids' => [$second, $this->team]]);
        $p = $this->participant($e);
        $this->assertSame(min($second, $this->team), (int) $p->team_season_id);
        $this->assertSame(1, SponsorRunParticipant::where('event_id', $e->id)->count());
        $g = $this->core->addPledge($this->parent, $this->tenant, $p->id, $this->pledgeInput());
        $this->db->table('clubora.team_players')->where('tenant_id', $this->tenant->id)->update(['active' => false]);
        $this->core->saveEvent($this->admin, $this->tenant, $e->id, $this->eventInput(['revision' => $e->revision, 'reason' => 'Roster refresh', 'team_ids' => [$second, $this->team]]));
        $this->assertFalse($p->fresh()->required);
        $this->assertNotNull($g->fresh());
        $this->assertHttpStatus(409, fn () => $this->core->addPledge($this->parent, $this->tenant, $p->id, $this->pledgeInput()));
    }

    public function test_revision_reason_status_and_foreign_team_are_enforced_atomically(): void
    {
        $e = $this->event();
        $this->assertHttpStatus(409, fn () => $this->core->saveEvent($this->admin, $this->tenant, $e->id, $this->eventInput(['revision' => 0, 'reason' => 'Stale'])));
        try {
            $this->core->saveEvent($this->admin, $this->tenant, $e->id, $this->eventInput(['revision' => 1]));
            $this->fail('Missing reason accepted');
        } catch (ValidationException) {
            $this->assertSame(1, $e->fresh()->revision);
        }
        $otherSeason = $this->insert('seasons', ['name' => 'Other']);
        $otherTeam = $this->insert('team_seasons', ['season_id' => $otherSeason, 'team_id' => $this->insert('teams', ['name' => 'Other']), 'label' => 'Other']);
        $this->assertHttpStatus(422, fn () => $this->event(['team_ids' => [$otherTeam]]));
        $this->assertSame(1, SponsorRunEvent::where('tenant_id', $this->tenant->id)->count());
        $cancelled = $this->core->cancelEvent($this->admin, $this->tenant, $e->id, 1, 'Cancelled explicitly');
        $this->assertHttpStatus(409, fn () => $this->core->saveEvent($this->admin, $this->tenant, $e->id, $this->eventInput(['revision' => $cancelled->revision, 'reason' => 'Reopen'])));
    }

    public function test_idempotence_encryption_integer_cents_and_collector_consent(): void
    {
        $p = $this->participant($this->event());
        $data = $this->pledgeInput();
        $before = Member::where('tenant_id', $this->tenant->id)->count();
        $g = $this->core->addPledge($this->parent, $this->tenant, $p->id, $data);
        $this->assertSame($g->id, $this->core->addPledge($this->parent, $this->tenant, $p->id, $data)->id);
        $this->assertHttpStatus(409, fn () => $this->core->addPledge($this->parent, $this->tenant, $p->id, ['amount_cents' => 200] + $data));
        $this->assertHttpStatus(422, fn () => $this->core->addPledge($this->parent, $this->tenant, $p->id, $this->pledgeInput(['amount_cents' => 12.5])));
        $this->assertHttpStatus(422, fn () => $this->core->addPledge($this->parent, $this->tenant, $p->id, $this->pledgeInput(['payment_mode' => 'collective'])));
        $collective = $this->core->addPledge($this->parent, $this->tenant, $p->id, $this->pledgeInput(['payment_mode' => 'collective', 'collector_confirmed' => true]));
        $this->assertSame($this->parent->id, $collective->collector_account_id);
        $this->assertSame($before, Member::where('tenant_id', $this->tenant->id)->count());
        $this->assertStringNotContainsString('Secret street', $g->recipient_encrypted);
        $this->assertSame('Secret street', json_decode(Crypt::decryptString($g->recipient_encrypted), true)['street']);
        $this->assertArrayNotHasKey('recipient_encrypted', $g->toArray());
    }

    public function test_office_final_approval_and_billing_contract_are_separate_from_draft(): void
    {
        $e = $this->event();
        $p = $this->participant($e);
        $g = $this->core->addPledge($this->parent, $this->tenant, $p->id, $this->pledgeInput());
        $this->assertHttpStatus(409, fn () => $this->core->billingSource($this->admin, $this->tenant, $g->id));
        $draft = $this->core->operations($this->admin, $this->tenant, $p->id, $p->revision, 'laps', 8);
        $this->assertNull($draft->final_laps);
        $this->assertHttpStatus(409, fn () => $this->core->operations($this->admin, $this->tenant, $p->id, $draft->revision, 'approve', 7));
        $final = $this->core->operations($this->admin, $this->tenant, $p->id, $draft->revision, 'approve', 8);
        $source = $this->core->billingSource($this->admin, $this->tenant, $g->id);
        $this->assertSame(500, $source['total_cents']);
        $this->assertSame('person', $source['recipient']['type']);
        $this->assertSame($e->season_id, $source['season_id']);
        $this->assertSame(8, $source['final_laps']);
        $this->assertNotEmpty($source['description']);
        $this->assertSame([['id' => (int) $g->id, 'label' => 'Synthetic sponsor (#'.$g->id.')', 'total_cents' => 500]], $this->core->billablePledges($this->admin, $this->tenant, $e->id)->all());
        $this->assertHttpStatus(409, fn () => $this->core->operations($this->admin, $this->tenant, $p->id, $final->revision, 'laps', 9));
        $this->assertSame(0, $this->db->table('clubora.sponsor_run_invoices')->where('tenant_id', $this->tenant->id)->count());
        Mail::assertNothingSent();
    }

    public function test_helpers_have_separate_expiring_operational_rights_and_no_sponsor_projection(): void
    {
        $e = $this->event();
        $p = $this->participant($e);
        $this->core->addPledge($this->parent, $this->tenant, $p->id, $this->pledgeInput());
        $helper = $this->account('member');
        $this->link($helper, $this->member('Helper'));
        $input = ['account_id' => $helper->id, 'can_checkin' => true, 'can_record_laps' => false, 'valid_from' => now()->subHour()->toIso8601String(), 'valid_until' => now()->addHour()->toIso8601String(), 'revision' => 0];
        $this->core->saveHelper($this->admin, $this->tenant, $e->id, $input);
        $rows = $this->core->participants($helper, $this->tenant, $e->id, ['scope' => 'helper'])->get();
        $this->assertCount(1, $rows);
        $this->assertArrayNotHasKey('email', (array) $rows[0]);
        $this->assertSame([], $this->core->summaries($helper, $this->tenant, [$p->id]));
        $this->assertHttpStatus(403, fn () => $this->core->pledges($helper, $this->tenant, $p->id));
        $checked = $this->core->operations($helper, $this->tenant, $p->id, 1, 'checkin', true);
        $this->assertHttpStatus(403, fn () => $this->core->operations($helper, $this->tenant, $p->id, $checked->revision, 'laps', 7));
        $this->assertHttpStatus(403, fn () => $this->core->operations($helper, $this->tenant, $p->id, $checked->revision, 'approve', 7));
        $this->core->saveHelper($this->admin, $this->tenant, $e->id, ['can_checkin' => false, 'revision' => 1] + $input);
        $this->assertHttpStatus(403, fn () => $this->core->operations($helper, $this->tenant, $p->id, $checked->revision, 'checkin', false));
    }

    public function test_offsetless_helper_windows_end_at_the_local_cutoff_in_summer_and_winter(): void
    {
        $helper = $this->account('member');
        $this->link($helper, $this->member('Local-time helper'));

        foreach (['2032-07-14' => '10:00:00', '2032-01-14' => '11:00:00'] as $date => $utcCutoff) {
            $cutoff = CarbonImmutable::parse($date.' 12:00:00', 'Europe/Zurich');
            $this->travelTo($cutoff->subHours(2));
            $e = $this->event(['event_date' => $date, 'registration_deadline' => $date]);
            $p = $this->participant($e);
            $this->core->saveHelper($this->admin, $this->tenant, $e->id, [
                'account_id' => $helper->id, 'can_checkin' => true, 'can_record_laps' => true,
                'valid_from' => $date.' 11:00:00', 'valid_until' => $date.' 12:00:00', 'revision' => 0,
            ]);
            $stored = $this->db->table('clubora.sponsor_run_helpers')->where('tenant_id', $this->tenant->id)
                ->where('event_id', $e->id)->where('account_id', $helper->id)->first();
            $this->assertSame($date.' '.$utcCutoff, CarbonImmutable::parse($stored->valid_until)->utc()->format('Y-m-d H:i:s'));
            $this->travelTo($cutoff->subHour()->subSecond());
            $this->assertFalse($this->core->capabilities($helper, $this->tenant, $e->id)['checkin']);

            $this->travelTo($cutoff->subSecond());
            $this->assertTrue($this->core->capabilities($helper, $this->tenant, $e->id)['laps']);
            $this->assertSame(1, $this->core->participants($helper, $this->tenant, $e->id, ['scope' => 'helper'])->count());
            $checked = $this->core->operations($helper, $this->tenant, $p->id, $p->revision, 'checkin', true);
            $this->assertSame($cutoff->subSecond()->utc()->format('Y-m-d H:i:s'), $checked->fresh()->checked_in_at->utc()->format('Y-m-d H:i:s'));
            $this->travelTo($cutoff);
            $this->assertTrue($this->core->capabilities($helper, $this->tenant, $e->id)['checkin']);

            $this->travelTo($cutoff->addSecond());
            $this->assertFalse($this->core->capabilities($helper, $this->tenant, $e->id)['checkin']);
            $this->assertFalse($this->core->capabilities($helper, $this->tenant, $e->id)['laps']);
            $this->assertSame(0, $this->core->participants($helper, $this->tenant, $e->id, ['scope' => 'helper'])->count());
            $this->assertHttpStatus(403, fn () => $this->core->operations($helper, $this->tenant, $p->id, $checked->revision, 'checkin', false));
            $this->assertHttpStatus(403, fn () => $this->core->operations($helper, $this->tenant, $p->id, $checked->revision, 'laps', 3));
            $this->assertSame($checked->revision, $p->fresh()->revision);
        }
    }

    public function test_team_staff_reads_are_minimized_but_do_not_allow_foreign_pledges(): void
    {
        $e = $this->event();
        $p = $this->participant($e);
        $trainer = $this->account('trainer');
        $staffMember = $this->member('Trainer');
        $this->link($trainer, $staffMember);
        $this->insert('team_staff', ['member_id' => $staffMember->id, 'team_season_id' => $this->team]);
        $this->core->addPledge($this->parent, $this->tenant, $p->id, $this->pledgeInput());
        $this->assertSame(1, $this->core->participants($trainer, $this->tenant, $e->id, ['scope' => 'teams'])->count());
        $projection = $this->core->pledges($trainer, $this->tenant, $p->id)->first()->toArray();
        $this->assertArrayNotHasKey('recipient_encrypted', $projection);
        $this->assertArrayNotHasKey('collector_account_id', $projection);
        $this->assertHttpStatus(403, fn () => $this->core->addPledge($trainer, $this->tenant, $p->id, $this->pledgeInput()));
        $this->assertHttpStatus(403, fn () => app(SponsorRunPublicService::class)->share($trainer, $this->tenant, $p->id, 'create', ['consent' => true, 'alias' => 'No']));
    }

    public function test_parent_access_and_share_expire_at_the_eighteenth_birthday(): void
    {
        $this->child->forceFill(['birth_date' => '2014-05-15'])->save();
        $p = $this->participant($this->event(['event_date' => '2032-05-16', 'registration_deadline' => '2032-05-16']));
        [$public, $token] = $this->publicLink($p);
        $this->assertSame('Consented alias', $public->info($token)['alias']);
        $this->travelTo(now('Europe/Zurich')->setDate(2032, 5, 15)->startOfDay());
        $this->assertHttpStatus(404, fn () => $public->info($token));
        $this->assertHttpStatus(403, fn () => $this->core->addPledge($this->parent, $this->tenant, $p->id, $this->pledgeInput()));
    }

    public function test_public_pending_is_not_a_pledge_and_confirmation_is_idempotent(): void
    {
        $p = $this->participant($this->event());
        [$public, $token] = $this->publicLink($p);
        $this->assertSame(['alias', 'title', 'event_date', 'registration_deadline'], array_keys($public->info($token)));
        $data = $this->pledgeInput(['consent' => true]);
        $public->submit($token, $data);
        $public->submit($token, $data);
        $this->assertSame(0, SponsorRunPledge::where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(1, $this->db->table('clubora.sponsor_run_public_pending')->where('tenant_id', $this->tenant->id)->count());
        $confirm = $this->confirmation();
        $public->confirm($confirm);
        $public->confirm($confirm);
        $this->assertSame(1, SponsorRunPledge::where('tenant_id', $this->tenant->id)->count());
        $pending = $this->db->table('clubora.sponsor_run_public_pending')->where('tenant_id', $this->tenant->id)->first();
        $this->assertNull($pending->payload_encrypted);
        $this->assertNotNull($pending->confirmed_at);
        $this->assertNull(SponsorRunPledge::where('tenant_id', $this->tenant->id)->sole()->created_by);
        Mail::assertNothingSent();
    }

    public function test_rotation_revocation_public_gate_and_expiry_block_pending_confirmation(): void
    {
        $p = $this->participant($this->event());
        [$public, $token] = $this->publicLink($p);
        $public->submit($token, $this->pledgeInput(['consent' => true]));
        $confirm = $this->confirmation();
        $rotated = $public->share($this->parent, $this->tenant, $p->id, 'rotate', ['consent' => true, 'alias' => 'New alias']);
        $this->assertHttpStatus(404, fn () => $public->info($token));
        $this->assertHttpStatus(404, fn () => $public->confirm($confirm));
        $newToken = parse_url($rotated['url'], PHP_URL_FRAGMENT);
        $public->configurePublic($this->admin, $this->tenant, false);
        $this->assertHttpStatus(404, fn () => $public->info($newToken));
        $public->configurePublic($this->admin, $this->tenant, true);
        $public->submit($newToken, $this->pledgeInput(['consent' => true]));
        $second = $this->confirmation();
        $public->share($this->parent, $this->tenant, $p->id, 'revoke');
        $this->assertHttpStatus(404, fn () => $public->confirm($second));
        $this->assertSame(0, SponsorRunPledge::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_public_limits_are_hashed_and_resistant_to_payload_replay(): void
    {
        $p = $this->participant($this->event());
        [$public, $token] = $this->publicLink($p);
        $data = $this->pledgeInput(['consent' => true]);
        $public->submit($token, $data);
        $this->assertHttpStatus(409, fn () => $public->submit($token, ['amount_cents' => 900] + $data));
        $this->assertFalse($this->db->table('clubora.sponsor_run_public_rates')->where('bucket_hash', 'like', '%example.invalid%')->exists());
        for ($i = 0; $i < 3; $i++) {
            $public->rate('fixture', '192.0.2.100', 3);
        }
        $this->assertHttpStatus(429, fn () => $public->rate('fixture', '192.0.2.100', 3));
        $this->assertSame(64, strlen($this->db->table('clubora.sponsor_run_public_rates')->value('bucket_hash')));
    }

    public function test_bulk_summary_caps_each_pledge_and_does_not_query_per_participant(): void
    {
        $p = $this->participant($this->event());
        $this->core->addPledge($this->parent, $this->tenant, $p->id, $this->pledgeInput());
        $this->core->addPledge($this->parent, $this->tenant, $p->id, $this->pledgeInput(['amount_type' => 'fixed', 'amount_cents' => 900, 'cap_cents' => 400]));
        $this->assertNull($this->core->summaries($this->admin, $this->tenant, [$p->id])[$p->id]['total_cents']);
        $draft = $this->core->operations($this->admin, $this->tenant, $p->id, 1, 'laps', 10);
        $this->core->operations($this->admin, $this->tenant, $p->id, $draft->revision, 'approve', 10);
        $this->db->enableQueryLog();
        $this->db->flushQueryLog();
        $s = $this->core->summaries($this->admin, $this->tenant, [$p->id])[$p->id];
        $pledgeQueries = array_filter($this->db->getQueryLog(), fn ($q) => str_contains($q['query'], 'sponsor_run_pledges'));
        $this->db->disableQueryLog();
        $this->assertCount(1, $pledgeQueries);
        $this->assertSame(2, (int) $s['pledge_count']);
        $this->assertSame(400, (int) $s['fixed_cents']);
        $this->assertSame(900, (int) $s['total_cents']);
        $this->assertSame(2, (int) $s['capped_count']);
    }

    public function test_read_only_profile_can_render_but_cannot_mutate(): void
    {
        $event = $this->event();
        $profile = app(AccessProfileService::class)->save($this->admin, $this->tenant, null, ['name' => 'Read Sponsor Run', 'active' => true, 'permissions' => ['sponsor_runs.view'], 'reason' => 'Synthetic test']);
        $reader = $this->account('profile', $profile->id);
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)->where('account_id', $reader->id)->update(['access_profile_id' => $profile->id]);
        $this->actingAs($reader, 'clubunio');
        $this->assertTrue(SponsorRuns::canAccess());
        Livewire::test(SponsorRuns::class)->assertSuccessful()->assertSee('Synthetic run');
        $this->assertHttpStatus(403, fn () => $this->core->saveEvent($reader, $this->tenant, null, $this->eventInput()));
        $this->assertHttpStatus(403, fn () => $this->core->cancelEvent($reader, $this->tenant, $event->id, 1, 'Not permitted'));
        $profile->forceFill(['permissions' => ['sponsor_runs.view', 'sponsor_runs.cancel']])->save();
        $this->assertSame('cancelled', $this->core->cancelEvent($reader, $this->tenant, $event->id, 1, 'Explicit cancel permission')->status_code);
    }

    public function test_management_page_filters_and_actions_create_real_roster(): void
    {
        Livewire::test(SponsorRuns::class)->callAction('newEvent', data: $this->eventInput())->assertHasNoActionErrors()->assertSee('Synthetic run')->assertSee('Child');
        $this->assertSame(1, SponsorRunParticipant::where('tenant_id', $this->tenant->id)->count());
        Livewire::test(SponsorRuns::class)->set('search', 'not present')->assertDontSee('Synthetic run');
    }

    public function test_public_http_is_no_store_no_referrer_and_get_cannot_confirm(): void
    {
        Route::middleware('web')->get('/sponsor-run-qa', [SponsorRunPublicController::class, 'landing']);
        Route::middleware('web')->post('/sponsor-run-qa/info', [SponsorRunPublicController::class, 'info']);
        $response = $this->get('/sponsor-run-qa');
        $response->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertSee('csrf-token', false);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->postJson('/sponsor-run-qa/info', ['token' => 'invalid'])->assertNotFound()->assertHeader('Referrer-Policy', 'no-referrer');
        $this->get('/sponsor-run-qa/info')->assertStatus(405);
    }

    public function test_web_chf_inputs_preserve_integer_cents_and_existing_displays(): void
    {
        $e = $this->event();
        $p = $this->participant($e);
        $input = $this->pledgeInput() + ['amount_chf' => '1.25', 'cap_chf' => '5.00'];
        Livewire::test(SponsorRuns::class)->call('openEvent', $e->id)->call('openParticipant', $p->id)
            ->callAction('pledge', data: $input)->assertHasNoActionErrors()->assertSee('CHF 1.25')->assertSee('CHF 5.00');
        $row = SponsorRunPledge::where('tenant_id', $this->tenant->id)->sole();
        $this->assertSame(125, $row->amount_cents);
        $this->assertSame(500, $row->cap_cents);
        Livewire::test(SponsorRuns::class)->call('openEvent', $e->id)->call('openParticipant', $p->id)
            ->callAction('pledge', data: array_replace($input, ['amount_chf' => '1.001']))->assertHasActionErrors(['amount_chf']);
        $this->actingAs($this->parent, 'portal');
        $url = route('clubunio.portal.sponsor-run.pledge', [$this->tenant->slug, $p->id]);
        $portal = array_replace($input, ['request_key' => (string) Str::uuid(), 'amount_chf' => '2,35', 'cap_chf' => '', 'amount_cents' => 99999]);
        $this->post($url, $portal)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(235, SponsorRunPledge::where('request_key', $portal['request_key'])->sole()->amount_cents);
        $this->postJson($url, array_replace($portal, ['amount_chf' => '0.001']))->assertUnprocessable()->assertJsonValidationErrors('amount_chf');
        $this->get(route('clubunio.portal.sponsor-run', [$this->tenant->slug, $e->id, 'participant' => $p->id]))->assertOk()->assertSee('CHF 1.25')->assertSee('CHF 2.35')->assertDontSee('Rp.');
    }

    public function test_public_web_chf_is_converted_server_side_before_confirmation(): void
    {
        $p = $this->participant($this->event());
        [$public, $token] = $this->publicLink($p);
        Route::middleware('web')->post('/sponsor-run-chf-qa', [SponsorRunPublicController::class, 'submit']);
        $input = $this->pledgeInput(['consent' => true]) + ['token' => $token, 'amount_chf' => '0.15', 'cap_chf' => '2.50'];
        $this->postJson('/sponsor-run-chf-qa', $input)->assertOk();
        $public->confirm($this->confirmation());
        $row = SponsorRunPledge::where('tenant_id', $this->tenant->id)->sole();
        $this->assertSame(15, $row->amount_cents);
        $this->assertSame(250, $row->cap_cents);
        $this->postJson('/sponsor-run-chf-qa', array_replace($input, ['cap_chf' => '2.501']))->assertUnprocessable()->assertJsonValidationErrors('cap_chf');
        Mail::assertNothingSent();
    }

    public function test_testmail_web_action_is_admin_only_and_transport_guards_prevent_delivery(): void
    {
        $e = $this->event();
        $p = $this->participant($e);
        [$public, $token] = $this->publicLink($p);
        $public->submit($token, $this->pledgeInput(['consent' => true]));
        $id = $this->db->table('clubora.sponsor_run_public_outbox')->where('tenant_id', $this->tenant->id)->value('id');
        config(['clubunio-integrations.test_mail_enabled' => false]);
        $this->assertHttpStatus(403, fn () => $public->dispatchTestOutbox($this->parent, $this->tenant, $id));
        $this->assertHttpStatus(409, fn () => $public->dispatchTestOutbox($this->admin, $this->tenant, $id));
        config(['clubunio-integrations.test_mail_enabled' => true]);
        $this->assertHttpStatus(409, fn () => $public->dispatchTestOutbox($this->admin, $this->tenant, $id));
        config(['clubunio-integrations.test_mail_enabled' => false]);
        Livewire::test(SponsorRuns::class)->call('openEvent', $e->id)->assertSee('Testmail-Outbox')->assertSee('held')->assertActionVisible('testOutbox');
        $this->actingAs($this->account('office'), 'clubunio');
        Livewire::test(SponsorRuns::class)->call('openEvent', $e->id)->assertActionHidden('testOutbox');
        $this->assertSame('held', $this->db->table('clubora.sponsor_run_public_outbox')->where('id', $id)->value('delivery_state'));
        Mail::assertNothingSent();
    }

    public function test_portal_http_and_tenant_boundaries_do_not_expose_recipient_data(): void
    {
        $e = $this->event();
        $p = $this->participant($e);
        $this->core->addPledge($this->parent, $this->tenant, $p->id, $this->pledgeInput());
        $this->actingAs($this->parent, 'portal');
        $this->get(route('clubunio.portal.sponsor-runs', $this->tenant->slug))->assertOk()->assertSee('Synthetic run');
        $this->get(route('clubunio.portal.sponsor-run', [$this->tenant->slug, $e->id, 'participant' => $p->id]))->assertOk()->assertSee('Synthetic sponsor')->assertDontSee('Secret street')->assertDontSee('sponsor@example.invalid')->assertHeader('Referrer-Policy', 'no-referrer');
        $stranger = $this->account('member');
        $this->link($stranger, $this->member('Stranger'));
        $this->actingAs($stranger, 'portal');
        $this->get(route('clubunio.portal.sponsor-run', [$this->tenant->slug, $e->id]))->assertNotFound();
        $foreign = Tenant::create(['slug' => 'sponsor-foreign-'.Str::random(10), 'name' => 'Foreign']);
        $this->actingAs($this->parent, 'portal');
        $this->get(route('clubunio.portal.sponsor-run', [$foreign->slug, $e->id]))->assertNotFound();
    }

    public function test_future_operations_expired_confirmation_and_csrf_are_blocked(): void
    {
        $p = $this->participant($this->event(['event_date' => '2032-05-17', 'registration_deadline' => '2032-05-17']));
        $this->assertHttpStatus(409, fn () => $this->core->operations($this->admin, $this->tenant, $p->id, $p->revision, 'laps', 5));
        [$public, $token] = $this->publicLink($p);
        $public->submit($token, $this->pledgeInput(['consent' => true]));
        $confirm = $this->confirmation();
        $this->travelTo(now()->addHours(25));
        $this->assertHttpStatus(404, fn () => $public->confirm($confirm));
        $this->get('/sponsor-run/confirm')->assertStatus(405);
        $this->app->instance('env', 'local');
        try {
            $this->postJson('/sponsor-run/confirm', ['token' => $confirm])->assertStatus(419);
        } finally {
            $this->app->instance('env', 'testing');
        }
        $this->assertSame(0, SponsorRunPledge::where('tenant_id', $this->tenant->id)->count());
    }
}
