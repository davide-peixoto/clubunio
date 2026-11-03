<?php

namespace Tests\Feature;

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\TeamPlanningService;
use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\{Crypt, DB, Http, Mail};
use Tests\TestCase;

class MobilePlanningFederationTest extends TestCase
{
    private $db;
    private Tenant $tenant;
    private Account $admin;
    private Account $trainer;
    private Account $member;
    private int $team;
    private int $otherTeam;
    private int $match;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') { $this->markTestSkipped('Opt-in rolled-back DB fixtures'); }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        config(['clubunio-mobile.enabled' => true, 'clubunio-mobile.native_planning_enabled' => true, 'cache.default' => 'array']);
        Http::preventStrayRequests(); Mail::fake();
        $this->tenant = Tenant::create(['slug' => 'qa-sfv-mobile-'.bin2hex(random_bytes(5)), 'name' => 'Synthetic SFV fixture']);
        TeamPlanningService::seedOptions($this->tenant->id);
        $this->admin = $this->account('admin'); $this->trainer = $this->account('trainer'); $this->member = $this->account('member');
        $season = $this->db->table('clubora.seasons')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => 'Synthetic season',
            'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->addMonth()->toDateString()]);
        $this->db->table('clubora.tenant_settings')->insert(['tenant_id' => $this->tenant->id, 'area' => 'general', 'key' => 'SaisonID',
            'label' => 'Synthetic season selection', 'value_type' => 'string', 'encrypted_value' => Crypt::encryptString((string) $season), 'enabled' => true, 'is_secret' => false]);
        foreach ([123, 456] as $sfv) {
            $team = $this->db->table('clubora.teams')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => 'Synthetic '.$sfv, 'sfv_team_id' => $sfv]);
            $id = $this->db->table('clubora.team_seasons')->insertGetId(['tenant_id' => $this->tenant->id, 'team_id' => $team, 'season_id' => $season, 'label' => 'Synthetic '.$sfv]);
            if ($sfv === 123) { $this->team = $id; } else { $this->otherTeam = $id; }
        }
        foreach ([[$this->trainer, 'team_staff'], [$this->member, 'team_players']] as [$actor, $table]) {
            $member = $this->db->table('clubora.members')->insertGetId(['tenant_id' => $this->tenant->id, 'first_name' => 'Synthetic', 'last_name' => $table]);
            $this->db->table('clubora.account_member_links')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $actor->id, 'member_id' => $member, 'kind' => 'self', 'state' => 'approved', 'source' => 'test']);
            $this->db->table('clubora.'.$table)->insert(['tenant_id' => $this->tenant->id, 'team_season_id' => $this->team, 'member_id' => $member]);
        }
        $this->match = $this->schedule(901, Time::now('Europe/Zurich')->addDays(2)->setTime(18, 0)->toIso8601String());
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->transactionLevel()) { $this->db->rollBack(); }
        parent::tearDown();
    }

    private function account(string $role): Account
    {
        $actor = new Account;
        $actor->forceFill(['username' => 'qa-sfv-'.bin2hex(random_bytes(7)), 'display_name' => 'Synthetic '.$role,
            'password' => bcrypt('synthetic-password'), 'active' => true, 'api_only' => false])->save();
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $actor->id, 'role' => $role, 'portal_enabled' => true]);
        return $actor;
    }

    private function schedule(int $provider, ?string $start): int
    {
        return $this->db->table('clubora.sfv_schedule')->insertGetId(['tenant_id' => $this->tenant->id, 'match_id' => $provider, 'season_id' => 2027,
            'team_a_id' => 123, 'team_b_id' => 456, 'title' => 'Synthetic home - Synthetic guest', 'host_name' => 'Synthetic home', 'guest_name' => 'Synthetic guest',
            'starts_at' => $start, 'location' => 'Synthetic pitch', 'state' => 'Angesetzt', 'match_type' => 'Cup', 'home' => true]);
    }

    private function token(Account $actor): string
    {
        return $this->postJson('/api/mobile/v1/session', ['username' => $actor->username, 'password' => 'synthetic-password', 'device_name' => 'Synthetic SFV test'])
            ->assertOk()->json('data.token');
    }

    private function base(): string { return '/api/mobile/v1/clubs/'.$this->tenant->id.'/planning/federation-matches'; }
    private function input(array $override = []): array
    {
        return array_replace(['TeamSaisonID' => $this->team, 'SFVRevision' => 1, 'EventRevision' => 0, 'TeilnahmeModus' => 'ANMELDEN', 'MaxTeilnehmer' => 18,
            'Beschreibung' => 'Synthetic confirmation', 'AnmeldeFrist' => Time::now('Europe/Zurich')->addDay()->toIso8601String()], $override);
    }

    public function test_matchcenter_preserves_managed_teams_filters_pagination_and_explicit_team_identity(): void
    {
        $this->schedule(902, Time::now()->subDays(2)->toIso8601String());
        $this->schedule(903, null);
        $this->withToken($this->token($this->trainer))->getJson($this->base())->assertOk()->assertJsonPath('data.totalRecordCount', 1)
            ->assertJsonPath('data.Verbandsspiele.0.TeamSaisonID', $this->team)->assertJsonPath('data.Verbandsspiele.0.SFVRevision', 1)
            ->assertJsonPath('data.Verbandsspiele.0.EventRevision', 0)->assertJsonPath('data.Verbandsspiele.0.CanAccept', true);
        $this->getJson($this->base().'?includeOlder=1')->assertOk()->assertJsonPath('data.totalRecordCount', 3)->assertJsonPath('data.Verbandsspiele.2.CanAccept', false);
        $this->withToken($this->token($this->member))->getJson($this->base().'?includeOlder=1')->assertOk()->assertJsonPath('data.totalRecordCount', 0);
        $this->withToken($this->token($this->admin))->getJson($this->base().'?recperpage=1&start=1')->assertOk()
            ->assertJsonPath('data.totalRecordCount', 2)->assertJsonCount(1, 'data.Verbandsspiele')->assertJsonPath('data.Verbandsspiele.0.TeamSaisonID', $this->otherTeam);
        $this->getJson($this->base().'?TeamSaisonID='.$this->team)->assertOk()->assertJsonPath('data.totalRecordCount', 1);
        $this->getJson($this->base().'?from=invalid')->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_accept_uses_displayed_revisions_and_never_recreates_removed_or_foreign_events(): void
    {
        $token = $this->token($this->trainer);
        $this->withToken($token)->postJson($this->base().'/901/accept', $this->input(['SFVRevision' => 99]))->assertUnprocessable();
        $created = $this->postJson($this->base().'/901/accept', $this->input())->assertOk()->assertJsonPath('data.TeamTermin.Terminart', 'CUP');
        $event = $created->json('data.TeamTermin.TeamTerminID');
        $row = $this->db->table('clubora.team_events')->where('id', $event)->first();
        $this->assertSame(901, $row->sfv_match_id); $this->assertSame($this->team, $row->team_season_id);
        $this->assertSame(120.0, Time::parse($row->starts_at)->diffInMinutes(Time::parse($row->ends_at)));
        $this->getJson($this->base().'?accepted=1')->assertOk()->assertJsonPath('data.totalRecordCount', 1)->assertJsonPath('data.Verbandsspiele.0.EventRevision', 1);
        $this->getJson($this->base().'?accepted=0')->assertOk()->assertJsonPath('data.totalRecordCount', 0);
        $this->postJson($this->base().'/901/accept', $this->input())->assertUnprocessable();
        $this->db->table('clubora.sfv_schedule')->where('id', $this->match)->update(['revision' => 2, 'location' => 'New synthetic pitch']);
        $this->postJson($this->base().'/901/accept', $this->input(['EventRevision' => 1]))->assertUnprocessable();
        $this->assertSame(1, $this->db->table('clubora.team_events')->where('id', $event)->value('revision'));
        $this->postJson($this->base().'/901/accept', $this->input(['SFVRevision' => 2, 'EventRevision' => 1, 'Ort' => 'Explicit synthetic pitch']))
            ->assertOk()->assertJsonPath('data.TeamTermin.TeamTerminID', $event)->assertJsonPath('data.TeamTermin.Revision', 2);
        $this->postJson($this->base().'/901/accept', $this->input(['TeamSaisonID' => $this->otherTeam, 'SFVRevision' => 2]))->assertNotFound();
        $this->postJson($this->base().'/903/accept', $this->input())->assertNotFound();
        $this->db->table('clubora.team_events')->where('id', $event)->update(['deleted_at' => now()->toIso8601String()]);
        $this->getJson($this->base().'?accepted=0')->assertOk()->assertJsonPath('data.Verbandsspiele.0.Removed', true)->assertJsonPath('data.Verbandsspiele.0.CanAccept', false);
        $this->postJson($this->base().'/901/accept', $this->input(['SFVRevision' => 2, 'EventRevision' => 2]))->assertConflict();
        $this->assertSame(1, $this->db->table('clubora.team_events')->where('tenant_id', $this->tenant->id)->count());
        $foreign = Tenant::create(['slug' => 'qa-sfv-foreign-'.bin2hex(random_bytes(4)), 'name' => 'Foreign']);
        $this->postJson(str_replace('/clubs/'.$this->tenant->id.'/', '/clubs/'.$foreign->id.'/', $this->base()).'/901/accept', $this->input())->assertForbidden();
        $this->withToken($this->token($this->member))->postJson($this->base().'/901/accept', $this->input())->assertNotFound();
        Http::assertNothingSent();
    }
}
