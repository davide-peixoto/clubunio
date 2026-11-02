<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\TeamPlanningService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Tests\TestCase;

class WorkspaceBusPlanningTablesTest extends TestCase
{
    private $db;

    private Tenant $tenant;

    private Tenant $foreign;

    private Account $actor;

    private int $team;

    private int $bus;

    private int $member;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Opt-in rolled-back DB tests');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->tenant = Tenant::create(['slug' => 'tables-'.bin2hex(random_bytes(6)), 'name' => 'Table fixture']);
        $this->foreign = Tenant::create(['slug' => 'tables-'.bin2hex(random_bytes(6)), 'name' => 'Other fixture']);
        $this->actor = new Account;
        $this->actor->forceFill(['username' => bin2hex(random_bytes(8)), 'display_name' => 'Table fixture', 'active' => true, 'password' => bcrypt('test')])->save();
        $this->actor->tenants()->attach($this->tenant, ['role' => 'admin', 'portal_enabled' => true]);
        $this->actingAs($this->actor, 'clubunio');
        $season = $this->insert('seasons', ['name' => 'Table season']);
        $team = $this->insert('teams', ['name' => 'Table team', 'sfv_team_id' => 987654]);
        $this->team = $this->insert('team_seasons', ['season_id' => $season, 'team_id' => $team, 'label' => 'Table team / season']);
        $this->bus = $this->insert('buses', ['name' => 'Table bus']);
        $this->member = $this->insert('members', ['first_name' => 'Table', 'last_name' => 'Member']);
        $this->insert('team_players', ['member_id' => $this->member, 'team_season_id' => $this->team]);
        $this->insert('account_member_links', ['account_id' => $this->actor->id, 'member_id' => $this->member, 'kind' => 'self', 'state' => 'approved', 'source' => 'test']);
        $this->insert('bus_statuses', ['code' => 'ANGEFRAGT', 'name' => 'Angefragt']);
        $this->insert('bus_statuses', ['code' => 'GENEHMIGT', 'name' => 'Genehmigt']);
        TeamPlanningService::seedOptions($this->tenant->id);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Filament::setTenant(null);
        if ($this->db?->transactionLevel()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    private function insert(string $table, array $data): int
    {
        if ($table === 'sfv_schedule') {
            $data += ['season_id' => 2027, 'home' => false];
        }

        return $this->db->table('clubora.'.$table)->insertGetId($data + ['tenant_id' => $this->tenant->id]);
    }

    private function snapshot(string $url, string $component): string
    {
        $page = $this->get($url)->assertOk()->assertSee('fi-ta', false)->assertDontSee('class="workspace-content"', false);
        preg_match_all('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);
        foreach ($matches[1] as $encoded) {
            $snapshot = html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5);
            $data = json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR);
            if ($data['memo']['name'] === $component) {
                $this->assertSame(session('clubunio_context.'.$this->actor->id), $data['memo']['clubunioContext']);

                return $snapshot;
            }
        }
        $this->fail('Table snapshot missing: '.$component);
    }

    private function update(string $snapshot, array $updates = [], array $calls = [])
    {
        // Simulate the next HTTP request, without relying on the GET's panel state.
        Filament::setTenant(null);

        $host = json_decode($snapshot, true)['data']['workspaceHost'];

        return $this->postJson('http://'.$host.app(HandleRequests::class)->getUpdateUri(), ['components' => [[
            'snapshot' => $snapshot, 'updates' => $updates, 'calls' => $calls,
        ]]], ['X-Livewire' => '']);
    }

    private function reservation(string $location, array $data = []): int
    {
        return $this->insert('bus_reservations', $data + ['bus_id' => $this->bus, 'team_season_id' => $this->team,
            'member_id' => $this->member, 'location' => $location, 'status' => 'ANGEFRAGT',
            'starts_at' => now()->addDays(2)->toIso8601String(), 'ends_at' => now()->addDays(2)->addHours(2)->toIso8601String()]);
    }

    public function test_bus_real_updates_preserve_filters_search_scope_and_clean_links(): void
    {
        $id = $this->reservation('VISIBLE-BUS');
        $this->reservation('OTHER-STATUS', ['status' => 'GENEHMIGT']);
        $foreignBus = $this->insert('buses', ['tenant_id' => $this->foreign->id, 'name' => 'Foreign bus']);
        $this->insert('bus_statuses', ['tenant_id' => $this->foreign->id, 'code' => 'ANGEFRAGT', 'name' => 'Angefragt']);
        $this->reservation('FOREIGN-BUS', ['tenant_id' => $this->foreign->id, 'bus_id' => $foreignBus, 'team_season_id' => null, 'member_id' => null]);
        $this->reservation('INACTIVE-BUS', ['active' => false]);
        $snapshot = $this->snapshot('/bus', 'club-unio.bus-reservations-table');
        $response = $this->update($snapshot, ['tableSearch' => 'VISIBLE', 'tableFilters.status.value' => 'ANGEFRAGT'])->assertOk();
        $html = $response->json('components.0.effects.html');
        $this->assertStringContainsString('VISIBLE-BUS', $html);
        $this->assertStringContainsString('/bus/'.$id, $html);
        $this->assertStringNotContainsString('FOREIGN-BUS', $html);
        $this->assertStringNotContainsString('OTHER-STATUS', $html);
        $this->assertStringNotContainsString('INACTIVE-BUS', $html);
        $next = json_decode($response->json('components.0.snapshot'), true);
        $this->assertSame('VISIBLE', $next['data']['tableSearch']);
        $this->assertSame('ANGEFRAGT', $next['data']['tableFilters'][0]['status'][0]['value']);
        $changed = $this->update($response->json('components.0.snapshot'), ['tableFilters.status.value' => 'GENEHMIGT'])->assertOk();
        $this->assertStringNotContainsString('VISIBLE-BUS', $changed->json('components.0.effects.html'));
        $this->assertSame('VISIBLE', json_decode($changed->json('components.0.snapshot'), true)['data']['tableSearch']);
        Http::assertNothingSent();
    }

    public function test_all_three_real_updates_reject_stale_context_api_accounts_and_revocation(): void
    {
        $this->actor->tenants()->attach($this->foreign, ['role' => 'admin']);
        foreach (['/bus' => 'club-unio.bus-reservations-table', '/planning/absences' => 'club-unio.planning-absences-table', '/planning/sfv' => 'club-unio.planning-sfv-table'] as $url => $component) {
            $snapshot = $this->snapshot($url, $component);
            $this->update($snapshot)->assertOk();
            $this->db->table('clubora.accounts')->where('id', $this->actor->id)->update(['api_only' => true]);
            $this->update($snapshot)->assertForbidden();
            $this->db->table('clubora.accounts')->where('id', $this->actor->id)->update(['api_only' => false]);
            $key = 'clubunio_context.'.$this->actor->id;
            $context = session($key);
            session()->put($key, ['tenant' => $this->foreign->id, 'revision' => 'changed']);
            $this->update($snapshot)->assertStatus(419);
            session()->put($key, $context);
            $this->db->table('clubora.accounts')->where('id', $this->actor->id)->update(['active' => false]);
            $this->update($snapshot)->assertForbidden();
            $this->db->table('clubora.accounts')->where('id', $this->actor->id)->update(['active' => true]);
            $this->actor->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'trainer']);
            $this->update($snapshot)->assertForbidden();
            $this->actor->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'admin']);
        }
        Http::assertNothingSent();
    }

    public function test_absence_notes_ownership_and_controller_links_survive_real_update(): void
    {
        $id = $this->insert('team_absences', ['member_id' => $this->member, 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-05', 'kind' => 'KRANK', 'note_encrypted' => Crypt::encryptString('PRIVATE-OWN-NOTE')]);
        $other = $this->insert('members', ['first_name' => 'Other', 'last_name' => 'Player']);
        $this->insert('team_players', ['member_id' => $other, 'team_season_id' => $this->team]);
        $otherId = $this->insert('team_absences', ['member_id' => $other, 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-05', 'kind' => 'KRANK']);
        $snapshot = $this->snapshot('/planning/absences', 'club-unio.planning-absences-table');
        $response = $this->update($snapshot)->assertOk();
        $html = $response->json('components.0.effects.html');
        $this->assertStringContainsString('PRIVATE-OWN-NOTE', $html);
        $this->assertStringContainsString('Table Member', $html);
        $this->assertStringContainsString('/planning/absences?edit='.$id, $html);
        $this->assertStringContainsString('/planning/absences/'.$id, $html);
        $this->assertStringNotContainsString('/planning/absences?edit='.$otherId, $html);
        $this->get('/planning/absences?edit='.$otherId)->assertNotFound();
        $this->get('/planning/absences?edit='.$id)->assertOk()->assertSee('PRIVATE-OWN-NOTE');
        Http::assertNothingSent();
    }

    public function test_sfv_date_filters_and_search_are_combined_and_import_links_stay_scoped(): void
    {
        $id = $this->insert('sfv_schedule', ['match_id' => 76543, 'team_a_id' => 987654, 'title' => 'VISIBLE-SFV', 'starts_at' => now()->addDays(3)->toIso8601String(), 'home' => true]);
        $this->insert('sfv_schedule', ['match_id' => 76544, 'team_a_id' => 11111, 'title' => 'OTHER-TEAM-SFV', 'starts_at' => now()->addDays(3)->toIso8601String()]);
        $this->insert('sfv_schedule', ['match_id' => 76545, 'team_a_id' => 987654, 'title' => 'FOREIGN-SFV', 'starts_at' => now()->addDays(3)->toIso8601String(), 'tenant_id' => $this->foreign->id]);
        $event = app(TeamPlanningService::class)->saveEvent($this->actor, $this->tenant, null, [
            'team_season_id' => $this->team, 'title' => 'Imported fixture', 'kind' => 'MEISTERSCHAFT', 'mode' => 'ANMELDEN', 'status' => 'AKTIV',
            'capacity' => 18,
            'deadline_at' => now('Europe/Zurich')->addDays(2)->setTime(18, 0)->format('Y-m-d\\TH:i'),
            'starts_at' => now('Europe/Zurich')->addDays(3)->setTime(18, 0)->format('Y-m-d\\TH:i'),
            'ends_at' => now('Europe/Zurich')->addDays(3)->setTime(20, 0)->format('Y-m-d\\TH:i'),
        ]);
        $this->db->table('clubora.team_events')->where('id', $event)->update(['sfv_match_id' => 76543]);
        $snapshot = $this->snapshot('/planning/sfv', 'club-unio.planning-sfv-table');
        $response = $this->update($snapshot, ['tableSearch' => 'VISIBLE'])->assertOk();
        $html = $response->json('components.0.effects.html');
        $this->assertStringContainsString('VISIBLE-SFV', $html);
        $this->assertStringContainsString('/planning/sfv/'.$id, $html);
        $this->assertStringContainsString('/planning/events/'.$event, $html);
        $this->assertStringContainsString('Änderungen prüfen', $html);
        $this->assertStringNotContainsString('OTHER-TEAM-SFV', $html);
        $this->assertStringNotContainsString('FOREIGN-SFV', $html);
        $filtered = $this->update($response->json('components.0.snapshot'), ['tableFilters.period.to' => now()->toDateString()])->assertOk();
        $this->assertStringNotContainsString('VISIBLE-SFV', $filtered->json('components.0.effects.html'));
        $this->assertSame('VISIBLE', json_decode($filtered->json('components.0.snapshot'), true)['data']['tableSearch']);
        Http::assertNothingSent();
    }

    public function test_fresh_domain_binding_rejects_old_workspace_snapshot(): void
    {
        $this->actor->tenants()->attach($this->foreign, ['role' => 'admin']);
        config(['clubunio-domains.central_hosts' => ['tables.example.test']]);
        $snapshot = $this->snapshot('http://tables.example.test/bus', 'club-unio.bus-reservations-table');
        $this->assertSame('tables.example.test', json_decode($snapshot, true)['data']['workspaceHost']);
        config(['clubunio-domains.hosts' => ['tables.example.test' => $this->foreign->slug]]);
        $this->update($snapshot)->assertStatus(419);
        Http::assertNothingSent();
    }

    public function test_portal_routes_keep_legacy_renderer_without_admin_livewire_components(): void
    {
        $this->actor->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'trainer']);
        $this->insert('team_staff', ['team_season_id' => $this->team, 'member_id' => $this->member]);
        foreach (['/bus/'.$this->tenant->slug, '/planning/'.$this->tenant->slug.'/absences', '/planning/'.$this->tenant->slug.'/sfv'] as $url) {
            $this->get($url)->assertOk()->assertSee('table-scroll')->assertDontSee('wire:snapshot', false)->assertDontSee('fi-main-sidebar');
        }
        Http::assertNothingSent();
    }
}
