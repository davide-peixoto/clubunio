<?php

namespace Tests\Feature;

use App\ClubUnio\Models\{Account, Tenant};
use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\{Crypt, DB, Http, Mail};
use Tests\TestCase;

class MobileFacilitiesBusTest extends TestCase
{
    private $db;
    private Tenant $tenant;
    private Account $admin;
    private Account $trainer;
    private Account $outsider;
    private int $member;
    private int $team;
    private int $otherTeam;
    private int $bus;
    private int $field;
    private int $open;
    private int $closed;
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') { $this->markTestSkipped('Opt-in rolled-back DB fixtures'); }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName()); $this->db->beginTransaction();
        config(['clubunio-mobile.enabled' => true, 'clubunio-mobile.native_bus_enabled' => true, 'clubunio-mobile.native_facilities_enabled' => true, 'cache.default' => 'array']);
        Http::preventStrayRequests(); Mail::fake();
        $this->tenant = Tenant::create(['slug' => 'qa-facilities-bus-'.bin2hex(random_bytes(5)), 'name' => 'Synthetic facilities/bus']);
        $this->admin = $this->account('admin'); $this->trainer = $this->account('trainer'); $this->outsider = $this->account('member');
        $this->member = $this->insert('members', ['first_name' => 'Synthetic', 'last_name' => 'Applicant', 'email' => 'PRIVATE-BUS@example.invalid', 'mobile' => 'PRIVATE-BUS-MOBILE']);
        $other = $this->insert('members', ['first_name' => 'Outside', 'last_name' => 'Applicant']);
        foreach ([[$this->trainer, $this->member], [$this->outsider, $other]] as [$actor, $member]) {
            $this->insert('account_member_links', ['account_id' => $actor->id, 'member_id' => $member, 'kind' => 'self', 'state' => 'approved', 'source' => 'test']);
        }
        $season = $this->insert('seasons', ['name' => 'Selected season', 'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->addMonth()->toDateString()]);
        $this->insert('tenant_settings', ['area' => 'general', 'key' => 'SaisonID', 'label' => 'Synthetic season', 'value_type' => 'string',
            'encrypted_value' => Crypt::encryptString((string) $season), 'enabled' => true, 'is_secret' => false]);
        $team = $this->insert('teams', ['name' => 'Synthetic team']);
        $this->team = $this->insert('team_seasons', ['team_id' => $team, 'season_id' => $season, 'label' => 'Selected team']);
        $otherSeason = $this->insert('seasons', ['name' => 'Unselected season', 'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->addMonth()->toDateString()]);
        $this->otherTeam = $this->insert('team_seasons', ['team_id' => $team, 'season_id' => $otherSeason, 'label' => 'Unselected team']);
        foreach ([$this->team, $this->otherTeam] as $team) { $this->insert('team_staff', ['team_season_id' => $team, 'member_id' => $this->member]); }
        $this->bus = $this->insert('buses', ['name' => 'Synthetic bus']);
        foreach (['ANGEFRAGT', 'GENEHMIGT', 'ABGELEHNT', 'STORNIERT', 'UNBEKANNT'] as $status) { $this->insert('bus_statuses', ['code' => $status, 'name' => $status]); }
        $this->open = $this->insert('facility_statuses', ['code' => 'open', 'name' => 'Frei bespielbar', 'blocks_training' => false]);
        $this->closed = $this->insert('facility_statuses', ['code' => 'closed', 'name' => 'Training gesperrt', 'blocks_training' => true]);
        $field = $this->insert('training_options', ['category' => 'field', 'code' => 'qa-field', 'name' => 'Synthetic custom field']);
        $this->field = $this->insert('field_availabilities', ['field_id' => $field, 'status_id' => $this->open, 'note' => 'Synthetic field note']);
    }
    protected function tearDown(): void { if (isset($this->db) && $this->db->transactionLevel()) { $this->db->rollBack(); } parent::tearDown(); }
    private function insert(string $table, array $row): int { return $this->db->table('clubora.'.$table)->insertGetId(['tenant_id' => $this->tenant->id] + $row); }
    private function account(string $role): Account {
        $actor = new Account; $actor->forceFill(['username' => 'qa-facilities-'.bin2hex(random_bytes(6)), 'display_name' => 'Synthetic '.$role,
            'password' => bcrypt('synthetic-password'), 'active' => true, 'api_only' => false])->save();
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $actor->id, 'role' => $role, 'portal_enabled' => true]); return $actor;
    }
    private function token(Account $actor): string { return $this->postJson('/api/mobile/v1/session', ['username' => $actor->username, 'password' => 'synthetic-password', 'device_name' => 'Synthetic facilities test'])->assertOk()->json('data.token'); }
    private function base(): string { return '/api/mobile/v1/clubs/'.$this->tenant->id; }
    private function reservation(array $overrides = []): array {
        return array_replace(['BusID' => $this->bus, 'TeamSaisonID' => $this->team, 'ResVon' => Time::now('Europe/Zurich')->addDays(2)->setTime(18, 0)->toIso8601String(),
            'ResBis' => Time::now('Europe/Zurich')->addDays(2)->setTime(20, 0)->toIso8601String(), 'Ort' => 'Synthetic destination', 'Descr' => 'Synthetic description'], $overrides);
    }

    public function test_field_status_uses_actual_fields_definitions_versions_and_personal_permissions(): void
    {
        $this->withToken($this->token($this->admin))->getJson($this->base().'/facilities/fields')->assertOk()->assertJsonCount(1, 'data.fields')
            ->assertJsonPath('data.fields.0.name', 'Synthetic custom field')->assertJsonPath('data.fields.0.can_edit', true);
        $url = $this->base().'/facilities/fields/'.$this->field;
        $this->patchJson($url, ['revision' => 1, 'status_id' => $this->closed, 'status_revision' => 1])->assertOk()->assertJsonPath('data.fields.0.revision', 2)
            ->assertJsonPath('data.fields.0.note', 'Synthetic field note')->assertJsonPath('data.fields.0.blocks_training', true);
        $this->patchJson($url, ['revision' => 1, 'status_id' => $this->open, 'status_revision' => 1])->assertUnprocessable();
        $this->patchJson($url, ['revision' => 2, 'status_id' => $this->open, 'status_revision' => 99])->assertConflict();
        $this->assertSame($this->closed, $this->db->table('clubora.field_availabilities')->where('id', $this->field)->value('status_id'));
        $this->withToken($this->token($this->trainer))->getJson($this->base().'/facilities/fields')->assertForbidden();
        $this->patchJson($url, ['revision' => 2, 'status_id' => $this->open, 'status_revision' => 1])->assertForbidden();
        config(['clubunio-mobile.native_facilities_enabled' => false]);
        $this->withToken($this->token($this->admin))->getJson($this->base().'/facilities/fields')->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_bus_request_decision_counts_scope_and_keybox_are_personal_and_versioned(): void
    {
        $trainer = $this->token($this->trainer);
        $this->withToken($trainer)->getJson($this->base().'/bus/options')->assertOk()->assertJsonCount(1, 'data.teams')->assertJsonPath('data.teams.0.id', $this->team)
            ->assertJsonPath('data.default_applicant_id', $this->member)->assertJsonPath('data.capabilities.choose_applicant', false);
        $this->getJson($this->base().'/bus/members')->assertOk()->assertJsonCount(1, 'data')->assertDontSee('PRIVATE-BUS');
        $this->postJson($this->base().'/bus/reservations', $this->reservation(['TeamSaisonID' => $this->otherTeam]))->assertForbidden();
        $this->postJson($this->base().'/bus/reservations', $this->reservation(['AntragstellerMitgliederID' => 2147483647]))->assertForbidden();
        $created = $this->postJson($this->base().'/bus/reservations', $this->reservation(['ReservierungsStatus' => 'GENEHMIGT']))->assertOk()
            ->assertJsonPath('data.ReservierungsStatus', 'ANGEFRAGT')->assertJsonPath('data.Revision', 1)->assertJsonPath('data.CanApprove', false)->assertJsonPath('data.CanCancel', true);
        $id = $created->json('data.ResID'); $url = $this->base().'/bus/reservations/'.$id;
        $this->postJson($this->base().'/bus/reservations', $this->reservation())->assertUnprocessable();
        $this->getJson($this->base().'/bus/reservations')->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.counts.ANGEFRAGT', 1);
        $this->postJson($url.'/approve', ['revision' => 1])->assertForbidden();
        $this->withToken($this->token($this->outsider))->getJson($url)->assertNotFound();
        $this->withToken($this->token($this->admin))->postJson($url.'/approve', ['revision' => 1, 'note' => 'Synthetic approval'])->assertOk()
            ->assertJsonPath('data.ReservierungsStatus', 'GENEHMIGT')->assertJsonPath('data.Revision', 2);
        $this->postJson($url.'/reject', ['revision' => 1])->assertUnprocessable();
        $this->getJson($url.'/keybox')->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.issuanceEnabled', false)->assertDontSee('TEST-')->assertJsonMissingPath('data.pin');
        $this->withToken($trainer)->postJson($url.'/cancel', ['revision' => 2])->assertForbidden();
        $this->withToken($this->token($this->admin))->postJson($url.'/cancel', ['revision' => 2])->assertOk()->assertJsonPath('data.Revision', 3)->assertJsonPath('data.ReservierungsStatus', 'STORNIERT');
        $this->assertSame(1, $this->db->table('clubora.bus_reservations')->where('tenant_id', $this->tenant->id)->count());
        config(['clubunio-mobile.native_bus_enabled' => false]); $this->getJson($url)->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_bus_current_season_history_and_owner_visibility_never_expand_other_members_access(): void
    {
        $this->withToken($this->token($this->trainer));
        $created = $this->postJson($this->base().'/bus/reservations', $this->reservation())->assertOk(); $id = $created->json('data.ResID');
        $this->insert('bus_reservations', ['bus_id' => $this->bus, 'team_season_id' => $this->otherTeam, 'member_id' => $this->member,
            'status' => 'ANGEFRAGT', 'starts_at' => Time::now()->addDays(3)->toIso8601String(), 'ends_at' => Time::now()->addDays(3)->addHour()->toIso8601String()]);
        $this->insert('bus_reservations', ['bus_id' => $this->bus, 'team_season_id' => $this->team, 'member_id' => $this->member,
            'status' => 'GENEHMIGT', 'starts_at' => Time::now()->subDays(3)->toIso8601String(), 'ends_at' => Time::now()->subDays(3)->addHour()->toIso8601String()]);
        $this->getJson($this->base().'/bus/reservations')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson($this->base().'/bus/reservations?period=past')->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.reservations.0.CanCancel', false);
        $this->db->table('clubora.team_staff')->where('tenant_id', $this->tenant->id)->update(['active' => false]);
        $this->getJson($this->base().'/bus/reservations/'.$id)->assertOk()->assertJsonPath('data.ResID', $id);
        $foreign = Tenant::create(['slug' => 'qa-bus-foreign-'.bin2hex(random_bytes(4)), 'name' => 'Foreign']);
        $this->getJson(str_replace('/clubs/'.$this->tenant->id.'/', '/clubs/'.$foreign->id.'/', $this->base().'/bus/reservations'))->assertForbidden();
        $this->withToken($this->token($this->outsider))->getJson($this->base().'/bus/reservations')->assertOk()->assertJsonPath('data.total', 0);
        Http::assertNothingSent();
    }
}
