<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
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
}
