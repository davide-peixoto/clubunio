<?php

namespace Tests\Feature;

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\{PlanningGuestService, TeamPlanningService};
use Illuminate\Support\Facades\{Crypt, DB, Http, Mail};
use Tests\TestCase;

class MobilePlanningInvitationsTest extends TestCase
{
    private $db;
    private Tenant $tenant;
    private Account $admin;
    private Account $trainer;
    private Account $player;
    private int $member;
    private int $candidate;
    private int $event;
    private int $team;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') { $this->markTestSkipped('Opt-in rolled-back DB fixtures'); }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        config(['clubunio-mobile.enabled' => true, 'clubunio-mobile.native_planning_enabled' => true, 'cache.default' => 'array']);
        Http::preventStrayRequests(); Mail::fake();
        $this->tenant = Tenant::create(['slug' => 'qa-mobile-invite-'.bin2hex(random_bytes(5)), 'name' => 'Synthetic invitations']);
        TeamPlanningService::seedOptions($this->tenant->id);
        $this->admin = $this->account('admin'); $this->trainer = $this->account('trainer'); $this->player = $this->account('member');
        $season = $this->db->table('clubora.seasons')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => 'Synthetic season', 'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->addMonth()->toDateString()]);
        $this->db->table('clubora.tenant_settings')->insert(['tenant_id' => $this->tenant->id, 'area' => 'general', 'key' => 'SaisonID', 'label' => 'Synthetic season',
            'value_type' => 'string', 'encrypted_value' => Crypt::encryptString((string) $season), 'enabled' => true, 'is_secret' => false]);
        $team = $this->db->table('clubora.teams')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => 'Synthetic team']);
        $this->team = $this->db->table('clubora.team_seasons')->insertGetId(['tenant_id' => $this->tenant->id, 'team_id' => $team, 'season_id' => $season, 'label' => 'Synthetic team']);
        $staff = $this->createMember('Coach'); $this->member = $this->createMember('Roster'); $this->candidate = $this->createMember('External');
        foreach ([[$this->trainer, $staff, 'team_staff'], [$this->player, $this->member, 'team_players']] as [$actor, $member, $table]) {
            $this->db->table('clubora.account_member_links')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $actor->id, 'member_id' => $member, 'kind' => 'self', 'state' => 'approved', 'source' => 'test']);
            $this->db->table('clubora.'.$table)->insert(['tenant_id' => $this->tenant->id, 'team_season_id' => $this->team, 'member_id' => $member]);
        }
        $this->event = app(TeamPlanningService::class)->saveEvent($this->admin, $this->tenant, null, ['team_season_id' => $this->team, 'title' => 'Synthetic invitation event',
            'kind' => 'TRAINING', 'mode' => 'ABMELDEN', 'status' => 'AKTIV', 'capacity' => 18,
            'starts_at' => now('Europe/Zurich')->addDays(2)->setTime(18, 0)->format('Y-m-d\TH:i'),
            'ends_at' => now('Europe/Zurich')->addDays(2)->setTime(20, 0)->format('Y-m-d\TH:i'),
            'deadline_at' => now('Europe/Zurich')->addDay()->setTime(18, 0)->format('Y-m-d\TH:i')]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->transactionLevel()) { $this->db->rollBack(); }
        parent::tearDown();
    }
    private function account(string $role): Account
    {
        $actor = new Account;
        $actor->forceFill(['username' => 'qa-invite-'.bin2hex(random_bytes(7)), 'display_name' => 'Synthetic '.$role, 'password' => bcrypt('synthetic-password'), 'active' => true, 'api_only' => false])->save();
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $actor->id, 'role' => $role, 'portal_enabled' => true]);
        return $actor;
    }
    private function createMember(string $name): int
    {
        return $this->db->table('clubora.members')->insertGetId(['tenant_id' => $this->tenant->id, 'first_name' => $name, 'last_name' => 'Synthetic',
            'email' => 'PRIVATE-CONTACT@example.invalid', 'mobile' => 'PRIVATE-MOBILE', 'street' => 'PRIVATE-ADDRESS']);
    }
    private function token(Account $actor): string
    {
        return $this->postJson('/api/mobile/v1/session', ['username' => $actor->username, 'password' => 'synthetic-password', 'device_name' => 'Synthetic invitation test'])->assertOk()->json('data.token');
    }
    private function base(): string { return '/api/mobile/v1/clubs/'.$this->tenant->id.'/planning/events/'.$this->event; }
    private function selection(): array
    {
        return $this->getJson($this->base().'/member-options?q=External')->assertOk()->assertJsonCount(1, 'data.records')
            ->assertDontSee('PRIVATE-CONTACT')->assertDontSee('PRIVATE-MOBILE')->assertDontSee('PRIVATE-ADDRESS')->json('data.records.0');
    }
    private function payload(array $row): array { return array_intersect_key($row, array_flip(['EventRevision', 'InvitationSnapshot'])); }

    public function test_personal_selection_registers_additional_member_atomically_and_removes_only_the_invitation(): void
    {
        $trainer = $this->token($this->trainer);
        $this->withToken($trainer)->getJson($this->base().'/member-options?q=E')->assertOk()->assertJsonPath('data.totalRecordCount', 0);
        for ($i = 0; $i < 21; $i++) { $this->createMember('Pagination'.sprintf('%02d', $i)); }
        $inactive = $this->createMember('PaginationArchived');
        $this->db->table('clubora.members')->where('id', $inactive)->update(['archived_at' => now()->toIso8601String()]);
        $this->getJson($this->base().'/member-options?q=Pagination')->assertOk()->assertJsonPath('data.totalRecordCount', 21)->assertJsonCount(20, 'data.records')->assertDontSee('PaginationArchived');
        $this->getJson($this->base().'/member-options?q=Pagination&start=20')->assertOk()->assertJsonPath('data.totalRecordCount', 21)->assertJsonCount(1, 'data.records');
        $this->getJson($this->base().'/member-options?q=Roster')->assertOk()->assertJsonPath('data.totalRecordCount', 0);
        $choice = $this->selection();
        $this->postJson($this->base().'/invitations/'.$this->candidate, $this->payload($choice))->assertOk()->assertJsonPath('data.operation', 'invited');
        $part = $this->db->table('clubora.team_participations')->where('event_id', $this->event)->where('member_id', $this->candidate)->first();
        $this->assertSame('DABEI', $part->status);
        $this->assertSame('trainer', $part->source);
        $this->assertSame(1, $this->db->table('clubora.team_invitations')->where('event_id', $this->event)->count());
        $this->postJson($this->base().'/invitations/'.$this->candidate, $this->payload($choice))->assertConflict();
        $details = $this->getJson($this->base())->assertOk()->json('data.TeamTermin');
        $person = collect($details['Teilnahmen'])->firstWhere('MitgliederID', $this->candidate);
        $this->assertTrue($person['IsInvited']); $this->assertTrue($person['CanRemoveInvitation']);
        $this->deleteJson($this->base().'/invitations/'.$this->candidate, ['EventRevision' => $details['Revision'], 'InvitationSnapshot' => $person['InvitationSnapshot']])
            ->assertOk()->assertJsonPath('data.operation', 'removed');
        $this->assertSame(0, $this->db->table('clubora.team_invitations')->where('event_id', $this->event)->count());
        $this->assertSame('DABEI', $this->db->table('clubora.team_participations')->where('id', $part->id)->value('status'));
        $this->assertSame(2, $this->db->table('clubora.object_changes')->where('tenant_id', $this->tenant->id)->where('object_type', 'team_invitations')->count());
        $foreign = Tenant::create(['slug' => 'qa-invite-foreign-'.bin2hex(random_bytes(4)), 'name' => 'Foreign']);
        $this->getJson(str_replace('/clubs/'.$this->tenant->id.'/', '/clubs/'.$foreign->id.'/', $this->base()).'/member-options?q=External')->assertForbidden();
        $this->withToken($this->token($this->player))->getJson($this->base().'/member-options?q=External')->assertNotFound();
        $this->postJson($this->base().'/invitations/'.$this->candidate, $this->payload($choice))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_snapshots_bind_actor_state_and_lifetime_and_full_capacity_rolls_back_the_invitation(): void
    {
        $trainer = $this->token($this->trainer); $this->withToken($trainer);
        $choice = $this->selection();
        $this->postJson($this->base().'/invitations/2147483647', $this->payload($choice))->assertConflict();
        $this->withToken($this->token($this->admin))->postJson($this->base().'/invitations/'.$this->candidate, $this->payload($choice))->assertConflict();
        $this->withToken($trainer);
        $this->db->table('clubora.team_events')->where('id', $this->event)->update(['revision' => 2]);
        $this->postJson($this->base().'/invitations/'.$this->candidate, $this->payload($choice))->assertConflict();
        $choice = $this->selection();
        $this->travel(16)->minutes();
        try { $this->postJson($this->base().'/invitations/'.$this->candidate, $this->payload($choice))->assertConflict(); }
        finally { $this->travelBack(); }
        $choice = $this->selection();
        $this->db->table('clubora.team_events')->where('id', $this->event)->update(['capacity' => 1]);
        $this->postJson($this->base().'/invitations/'.$this->candidate, $this->payload($choice))->assertConflict();
        $choice = $this->selection();
        $this->postJson($this->base().'/invitations/'.$this->candidate, $this->payload($choice))->assertUnprocessable();
        $this->assertSame(0, $this->db->table('clubora.team_invitations')->where('event_id', $this->event)->count());
        $this->assertSame(0, $this->db->table('clubora.team_participations')->where('event_id', $this->event)->where('member_id', $this->candidate)->count());
        $this->assertSame(0, $this->db->table('clubora.object_changes')->where('tenant_id', $this->tenant->id)->where('object_type', 'team_invitations')->count());
        $this->db->table('clubora.team_events')->where('id', $this->event)->update(['capacity' => 18]);
        $choice = $this->selection();
        $this->postJson($this->base().'/invitations/'.$this->candidate, $this->payload($choice))->assertOk();
        $details = $this->getJson($this->base())->assertOk()->json('data.TeamTermin');
        $person = collect($details['Teilnahmen'])->firstWhere('MitgliederID', $this->candidate);
        // Real web service remove/reinvite cycle: a tuple without its own revision must still invalidate old claims.
        app(PlanningGuestService::class)->invite($this->trainer, $this->tenant, $this->event, $this->candidate, true);
        app(PlanningGuestService::class)->invite($this->trainer, $this->tenant, $this->event, $this->candidate);
        $this->deleteJson($this->base().'/invitations/'.$this->candidate, ['EventRevision' => 2, 'InvitationSnapshot' => $person['InvitationSnapshot']])->assertConflict();
        $this->assertSame(1, $this->db->table('clubora.team_invitations')->where('event_id', $this->event)->count());
        Http::assertNothingSent();
    }
}
