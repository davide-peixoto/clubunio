<?php
namespace Tests\Feature;

use App\ClubUnio\Models\{Account, Tenant};
use Illuminate\Support\Facades\{Crypt, DB, Http, Mail};
use Tests\TestCase;

final class MobileTodayCalendarTest extends TestCase
{
    private $db;
    private Tenant $tenant;
    private Account $actor;
    private int $member, $team, $otherTeam;
    private string $base;
    protected function setUp(): void {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') $this->markTestSkipped('Explicit synthetic PostgreSQL rollback required');
        $this->db = DB::connection('clubunio');
        self::assertSame('pgsql', $this->db->getDriverName()); self::assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction(); Http::preventStrayRequests(); Mail::fake();
        config(['clubunio-mobile.enabled' => true, 'clubunio-mobile.native_planning_enabled' => true, 'cache.default' => 'array']);
        $this->tenant = Tenant::create(['slug' => 'today-'.bin2hex(random_bytes(6)), 'name' => 'Synthetic today']);
        $this->actor = new Account; $this->actor->forceFill(['username' => 'today-'.bin2hex(random_bytes(6)), 'display_name' => 'Synthetic',
            'password' => bcrypt('fixture'), 'active' => true, 'api_only' => false])->save();
        $this->db->table('clubunio.tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $this->actor->id, 'role' => 'member', 'portal_enabled' => true]);
        $this->member = $this->insert('members', ['first_name' => 'Synthetic', 'last_name' => 'Invited']);
        $this->insert('account_member_links', ['account_id' => $this->actor->id, 'member_id' => $this->member, 'kind' => 'self', 'state' => 'approved', 'source' => 'test']);
        $season = $this->insert('seasons', ['name' => 'Synthetic current season']);
        $this->insert('tenant_settings', ['area' => 'general', 'key' => 'SaisonID', 'label' => 'Synthetic', 'value_type' => 'integer', 'encrypted_value' => Crypt::encryptString((string) $season)]);
        $this->team = $this->insert('team_seasons', ['team_id' => $this->insert('teams', ['name' => 'Synthetic A']), 'season_id' => $season, 'label' => 'Synthetic A']);
        $this->otherTeam = $this->insert('team_seasons', ['team_id' => $this->insert('teams', ['name' => 'Synthetic B']), 'season_id' => $season, 'label' => 'Synthetic B']);
        $token = $this->postJson('/api/mobile/v1/session', ['username' => $this->actor->username, 'password' => 'fixture', 'device_name' => 'Synthetic Today'])->assertOk()->json('data.token');
        $this->withToken($token);
        $this->base = '/api/mobile/v1/clubs/'.$this->tenant->id.'/planning/events';
    }
    protected function tearDown(): void { if (isset($this->db)) while ($this->db->transactionLevel()) $this->db->rollBack(); parent::tearDown(); }
    private function insert(string $table, array $values): int { return $this->db->table('clubunio.'.$table)->insertGetId($values + ['tenant_id' => $this->tenant->id]); }
    private function event(string $starts, string $status = 'AKTIV', ?int $team = null): int {
        return $this->insert('team_events', ['team_season_id' => $team ?? $this->team, 'title' => 'Synthetic event', 'kind' => 'TRAINING', 'mode' => 'ANMELDEN',
            'status' => $status, 'starts_at' => $starts, 'ends_at' => \Carbon\CarbonImmutable::parse($starts)->addHour()->toIso8601String()]);
    }
    private function url(array $extra = []): string { return $this->base.'?'.http_build_query($extra + ['from' => '2026-11-04T00:00:00Z', 'to' => '2026-11-18T00:00:00Z', 'active_only' => 1, 'start' => 0, 'recperpage' => 4]); }
    public function test_today_sees_only_invited_event_without_widening_team_or_custom_tenant_rights(): void {
        $id = $this->event('2026-11-06T18:00:00Z');
        $this->db->table('clubunio.team_invitations')->insert(['tenant_id' => $this->tenant->id, 'event_id' => $id, 'member_id' => $this->member]);
        $this->event('2026-11-07T18:00:00Z', 'AKTIV', $this->otherTeam);
        $this->getJson($this->url())->assertOk()->assertJsonCount(1, 'data.TeamTermine')->assertJsonPath('data.TeamTermine.0.TeamTerminID', $id)
            ->assertJsonPath('data.TeamTermine.0.CanManage', false)->assertJsonPath('data.totalRecordCount', 1);
        $this->getJson($this->url(['TeamSaisonID' => $this->otherTeam]))->assertOk()->assertJsonPath('data.totalRecordCount', 0);
        $foreign = Tenant::create(['slug' => 'today-foreign-'.bin2hex(random_bytes(5)), 'name' => 'Synthetic foreign']);
        config(['clubunio-domains.hosts' => ['fixed.example' => $this->tenant->slug]]);
        $this->getJson('https://fixed.example/api/mobile/v1/clubs/'.$foreign->id.'/planning/events')->assertNotFound();
        $this->db->table('clubunio.team_invitations')->where('tenant_id', $this->tenant->id)->where('event_id', $id)->delete();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.totalRecordCount', 0); Http::assertNothingSent(); Mail::assertNothingSent();
    }
    public function test_today_filters_cancelled_before_limit_and_observes_exact_fourteen_day_window(): void {
        $this->insert('team_players', ['team_season_id' => $this->team, 'member_id' => $this->member]);
        for ($i = 0; $i < 60; $i++) $this->event(\Carbon\CarbonImmutable::parse('2026-11-04T00:01:00Z')->addMinutes($i)->toIso8601String(), 'ABGESAGT');
        $ids = [$this->event('2026-11-04T00:00:00Z')];
        for ($i = 1; $i <= 3; $i++) $ids[] = $this->event('2026-11-0'.($i + 4).'T18:00:00Z');
        $this->event('2026-11-18T00:00:00Z'); $this->event('2026-11-03T23:59:00Z');
        $this->getJson($this->url())->assertOk()->assertJsonCount(4, 'data.TeamTermine')->assertJsonPath('data.totalRecordCount', 4)
            ->assertJsonPath('data.TeamTermine.0.TeamTerminID', $ids[0])->assertJsonPath('data.TeamTermine.3.TeamTerminID', $ids[3]);
        $this->getJson($this->url(['recperpage' => 1]))->assertOk()->assertJsonCount(1, 'data.TeamTermine')->assertJsonPath('data.totalRecordCount', 4);
        $this->getJson($this->url(['recperpage' => 101]))->assertUnprocessable();
        $this->getJson($this->url(['active_only' => 'invalid']))->assertUnprocessable(); Http::assertNothingSent();
    }
}
