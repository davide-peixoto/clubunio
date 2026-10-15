<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\MemberDocumentService;
use App\ClubUnio\WorkspaceRoutes;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Tests\TestCase;

class TenantNavigationTest extends TestCase
{
    private $db;

    private Account $actor;

    private Tenant $one;

    private Tenant $two;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Opt-in rolled-back DB tests');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->one = Tenant::create(['slug' => 'nav-'.bin2hex(random_bytes(6)), 'name' => 'First club']);
        $this->two = Tenant::create(['slug' => 'nav-'.bin2hex(random_bytes(6)), 'name' => 'Second club']);
        $this->actor = new Account;
        $this->actor->forceFill(['username' => bin2hex(random_bytes(8)), 'display_name' => 'Navigation test', 'active' => true, 'password' => bcrypt('test')])->save();
        $this->actor->tenants()->attach($this->one, ['role' => 'admin']);
        $this->actingAs($this->actor, 'clubunio');
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Filament::setTenant(null);
        if ($this->db) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    private function context(): array
    {
        return session('clubunio_context.'.$this->actor->id);
    }

    public function test_document_links_use_active_tenant_and_old_urls_redirect(): void
    {
        $this->get('/members')->assertOk();
        foreach (WorkspaceRoutes::DOCUMENTS as $name => $uri) {
            $route = app('router')->getRoutes()->getByName($name);
            $values = ['tenant' => $this->one->slug];
            foreach ($route->parameterNames() as $parameter) {
                if ($parameter !== 'tenant') {
                    $values[$parameter] = $parameter === 'source' ? 'local' : 999999999;
                }
            }
            $expected = '/'.$uri;
            $legacy = '/'.$route->uri();
            foreach ($values as $key => $value) {
                $expected = str_replace('{'.$key.'}', $value, $expected);
                $legacy = str_replace(['{'.$key.'}', '{'.$key.':slug}'], $value, $legacy);
            }
            $this->assertSame($expected, route($name, $values, false));
            $this->get($legacy.'?download=1')->assertRedirect($expected.'?download=1');
            $this->get($expected)->assertNotFound();
        }
        $this->actor->tenants()->attach($this->two, ['role' => 'admin']);
        $this->get('/clubora-documents/'.$this->two->slug.'/999999999')->assertForbidden();
        $this->actor->tenants()->detach($this->one);
        $this->get('/documents/members/999999999')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_bexio_uses_normal_layout_and_rejects_stale_connection_form(): void
    {
        $page = $this->get('/integrations/bexio')->assertOk()->assertSee('fi-sidebar', false);
        preg_match('/name="_workspace" value="([^"]+)"/', $page->getContent(), $match);
        $this->assertNotEmpty($match);
        $this->post('/integrations/bexio/connect', ['tenant' => $this->one->slug])->assertStatus(419);
        $this->actor->tenants()->attach($this->two, ['role' => 'admin']);
        $this->post('/tenant/switch', ['tenant' => $this->two->id, 'revision' => $this->context()['revision']])->assertRedirect('/');
        $this->post('/integrations/bexio/connect', ['tenant' => $this->one->slug, '_workspace' => html_entity_decode($match[1])])->assertStatus(419);
        $this->get('/integrations/bexio')->assertOk()->assertSee('Second club');
        Http::assertNothingSent();
    }

    public function test_clean_document_controller_preserves_id_and_download_arguments(): void
    {
        $this->get('/members')->assertOk();
        $this->mock(MemberDocumentService::class, function ($mock) {
            $mock->shouldReceive('open')->once()->withArgs(fn ($actor, $tenant, $id, $download) => $actor->is($this->actor) && $tenant->is($this->one) && $id === 123 && $download === true)
                ->andReturn(response('%PDF-synthetic', 200, ['Content-Type' => 'application/pdf']));
        });
        $this->get('/documents/members/123?download=1')->assertOk()->assertContent('%PDF-synthetic')
            ->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_clean_routes_and_automatic_tenant_preserve_scoping(): void
    {
        $own = $this->db->table('clubora.members')->insertGetId(['tenant_id' => $this->one->id, 'first_name' => 'VISIBLE-FIXTURE', 'last_name' => 'Test']);
        $foreign = $this->db->table('clubora.members')->insertGetId(['tenant_id' => $this->two->id, 'first_name' => 'FOREIGN-FIXTURE', 'last_name' => 'Test']);
        $this->get('/members')->assertOk()->assertSee('First club')->assertSee('VISIBLE-FIXTURE')->assertDontSee('FOREIGN-FIXTURE');
        $this->get('/members/'.$own)->assertOk();
        $this->get('/members/'.$foreign)->assertNotFound();
        $this->assertSame($this->one->id, $this->context()['tenant']);
        $this->assertSame($this->one->id, Filament::getTenant()->id);
        $this->assertSame('/members', route('filament.clubunio.resources.members.index', ['tenant' => $this->one], false));
        $this->assertSame('/login', route('filament.clubunio.auth.login', [], false));
        $this->get('/')->assertOk();
        $this->get('/clubunio/'.$this->one->slug.'/members')->assertRedirect('/members');
    }

    public function test_switch_is_authorized_and_rejects_stale_forms(): void
    {
        $this->get('/members')->assertOk();
        $before = $this->context();
        $this->post('/tenant/switch', ['tenant' => $this->two->id, 'revision' => $before['revision']])->assertForbidden();
        $this->assertSame($before, $this->context());
        $this->actor->tenants()->attach($this->two, ['role' => 'office']);
        $this->get('/members')->assertOk()->assertSee('Second club');
        $this->post('/tenant/switch', ['tenant' => $this->two->id, 'revision' => $before['revision']])->assertRedirect('/');
        $this->assertSame($this->two->id, $this->context()['tenant']);
        $this->assertNotSame($before['revision'], $this->context()['revision']);
        $this->post('/tenant/switch', ['tenant' => $this->one->id, 'revision' => $before['revision']])->assertStatus(419);
        $this->get('/members')->assertOk();
        $this->assertSame($this->two->id, Filament::getTenant()->id);
    }

    public function test_revoked_membership_fails_closed(): void
    {
        $this->get('/members')->assertOk();
        $this->actor->tenants()->detach($this->one);
        $this->get('/members')->assertForbidden();
    }

    public function test_old_livewire_snapshot_rejected_after_switch(): void
    {
        $this->actor->tenants()->attach($this->two, ['role' => 'admin']);
        $response = $this->get('/members')->assertOk();
        preg_match('/wire:snapshot="([^"]+)"/', $response->getContent(), $match);
        $this->assertNotEmpty($match);
        $snapshot = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
        $decoded = json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($this->context(), $decoded['memo']['clubunioContext']);
        $update = app(HandleRequests::class)->getUpdateUri();
        $this->postJson($update, ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => []]]], ['X-Livewire' => ''])->assertOk();
        $this->post('/tenant/switch', ['tenant' => $this->two->id, 'revision' => $this->context()['revision']])->assertRedirect('/');
        $this->postJson($update, ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => []]]], ['X-Livewire' => ''])->assertStatus(419);
    }

    public function test_bus_and_planning_share_the_panel_and_clean_links(): void
    {
        $season = $this->db->table('clubora.seasons')->insertGetId(['tenant_id' => $this->one->id, 'name' => 'Fixture', 'starts_on' => now()->subYear()->toDateString(), 'ends_on' => now()->addYear()->toDateString()]);
        $team = $this->db->table('clubora.teams')->insertGetId(['tenant_id' => $this->one->id, 'name' => 'Fixture']);
        $this->db->table('clubora.team_seasons')->insert(['tenant_id' => $this->one->id, 'season_id' => $season, 'team_id' => $team, 'label' => 'Fixture']);
        foreach (['/bus', '/bus/options', '/planning', '/planning/calendar', '/planning/absences', '/planning/options', '/planning/sfv', '/planning/training-plans'] as $url) {
            $this->get($url)->assertOk()->assertSee('fi-main-sidebar')->assertSee('First club');
        }
        $this->get('/bus')->assertDontSee('>Teamkalender</a>', false)->assertSee('/bus/options');
        $this->get('/planning')->assertSee('>Teamkalender</a>', false);
        $this->get('/bus/'.$this->one->slug.'?q=fixture')->assertRedirect('/bus?q=fixture');
        $this->get('/planning/'.$this->one->slug.'/calendar')->assertRedirect('/planning/calendar');
        $this->assertSame('/planning/events/123', route('clubunio.planning.show', [$this->one->slug, 123], false));
        $this->assertSame('/bus/456', route('clubunio.bus.show', [$this->one->slug, 456], false));
        $this->get('/bus/999999999')->assertNotFound();
        $this->get('/planning/events/999999999')->assertNotFound();
    }

    public function test_workspace_posts_require_current_context_and_keep_tenant_isolation(): void
    {
        $page = $this->get('/bus/options')->assertOk();
        preg_match('/name="_workspace" value="([^"]+)"/', $page->getContent(), $match);
        $this->assertNotEmpty($match);
        $token = html_entity_decode($match[1]);
        $input = ['kind' => 'bus', 'name' => 'Workspace fixture', 'active' => 1];
        $this->post('/bus/options', $input)->assertStatus(419);
        $this->post('/bus/options', $input + ['_workspace' => 'invalid'])->assertStatus(419);
        $this->post('/bus/options', $input + ['_workspace' => $token])->assertRedirect('/bus/options');
        $this->assertSame(1, $this->db->table('clubora.buses')->where('tenant_id', $this->one->id)->where('name', 'Workspace fixture')->count());
        $this->actor->tenants()->attach($this->two, ['role' => 'admin']);
        $this->post('/tenant/switch', ['tenant' => $this->two->id, 'revision' => $this->context()['revision']])->assertRedirect('/');
        $this->post('/bus/options', $input + ['_workspace' => $token])->assertStatus(419);
        $this->get('/bus/'.$this->one->slug)->assertForbidden();
        $this->post('/bus/'.$this->two->slug.'/options', $input)->assertStatus(419);
        $this->assertSame(0, $this->db->table('clubora.buses')->where('tenant_id', $this->two->id)->where('name', 'Workspace fixture')->count());
        $this->get('/planning')->assertOk()->assertSee('Second club');
        $this->actor->tenants()->detach($this->two);
        $this->get('/planning')->assertForbidden();
    }

    public function test_workspace_navigation_livewire_keeps_its_tenant(): void
    {
        $this->actor->tenants()->attach($this->two, ['role' => 'admin']);
        $page = $this->get('/bus')->assertOk();
        preg_match('/wire:snapshot="([^"]+)"/', $page->getContent(), $match);
        $this->assertNotEmpty($match);
        $snapshot = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
        $update = app(HandleRequests::class)->getUpdateUri();
        Filament::setTenant(null);
        $payload = ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => []]]];
        $this->postJson($update, $payload, ['X-Livewire' => ''])->assertOk();
        $this->assertSame($this->one->id, Filament::getTenant()->id);
        $this->post('/tenant/switch', ['tenant' => $this->two->id, 'revision' => $this->context()['revision']])->assertRedirect('/');
        $this->postJson($update, $payload, ['X-Livewire' => ''])->assertStatus(419);
    }
}
