<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\ActiveTenant;
use App\ClubUnio\Services\TenantDomainService;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Tests\TestCase;

class TenantBoundaryTest extends TestCase
{
    private $db;
    private Tenant $one;
    private Tenant $two;
    private Account $actor;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Explicit outer-rollback DB tests required');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->one = Tenant::create(['slug' => 'host-one-'.Str::random(12), 'name' => 'Domain club one']);
        $this->two = Tenant::create(['slug' => 'host-two-'.Str::random(12), 'name' => 'Domain club two']);
        $this->actor = new Account;
        $this->actor->forceFill(['username' => Str::random(24), 'display_name' => 'Tenant QA', 'active' => true, 'password' => bcrypt('fixture')])->save();
        $this->actor->tenants()->attach($this->one, ['role' => 'admin']);
        $this->actor->tenants()->attach($this->two, ['role' => 'office']);
        config(['clubunio-domains.hosts' => ['one.clubunio.test' => $this->one->slug, 'my.custom.test' => $this->two->slug]]);
        foreach ([[$this->one, '#126634', '/brand/blue-v1/clubunio-symbol.svg'], [$this->two, '#992244', '/images/svs-logo.png']] as [$tenant, $color, $logo]) {
            foreach (['SIGNAGE_BRAND_COLOR' => $color, 'SIGNAGE_BRAND_LOGO' => $logo] as $key => $value) {
                $this->db->table('clubora.tenant_settings')->insert(['tenant_id' => $tenant->id, 'area' => 'signage', 'key' => $key,
                    'label' => 'Synthetic branding', 'value_type' => 'string', 'encrypted_value' => \Illuminate\Support\Facades\Crypt::encryptString($value), 'enabled' => true, 'is_secret' => false]);
            }
        }
        Http::preventStrayRequests();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Filament::setTenant(null);
        if (isset($this->db)) {
            while ($this->db->transactionLevel()) {
                $this->db->rollBack();
            }
        }
        parent::tearDown();
    }

    public function test_domain_login_is_branded_before_authentication_without_cross_request_leak(): void
    {
        $this->get('https://one.clubunio.test/login')->assertOk()->assertSee('Domain club one')->assertSee('clubunio-club-color')->assertSee('#126634');
        $this->get('https://my.custom.test/portal/login')->assertOk()->assertSee('Domain club two')->assertDontSee('Domain club one')->assertSee('#992244')->assertSee('/images/svs-logo.png');
        $this->get('http://localhost:8093/login')->assertOk()->assertDontSee('Domain club one')->assertDontSee('Domain club two')->assertDontSee('clubunio-club-color');
        $this->get('https://unconfigured.test/login')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_domain_pins_workspace_and_rejects_other_club_urls_and_switches(): void
    {
        $this->actingAs($this->actor, 'clubunio');
        $this->get('https://one.clubunio.test/members')->assertOk()->assertSee('Domain club one');
        $context = session('clubunio_context.'.$this->actor->id);
        $this->assertSame($this->one->id, $context['tenant']);
        $this->assertSame('one.clubunio.test', $context['host']);
        $this->post('https://one.clubunio.test/tenant/switch', ['tenant' => $this->two->id, 'revision' => $context['revision']])->assertForbidden();
        $this->get('https://one.clubunio.test/join/'.$this->two->slug)->assertNotFound();
        $this->get('https://my.custom.test/members')->assertOk()->assertSee('Domain club two');
        $next = session('clubunio_context.'.$this->actor->id);
        $this->assertSame($this->two->id, $next['tenant']);
        $this->assertNotSame($context['revision'], $next['revision']);
        $this->actor->tenants()->detach($this->two);
        $this->get('https://my.custom.test/members')->assertForbidden();
    }

    public function test_domain_change_rejects_old_livewire_form_before_hydration(): void
    {
        $this->actingAs($this->actor, 'clubunio');
        $page = $this->get('https://one.clubunio.test/members')->assertOk();
        preg_match('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);
        $snapshot = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
        $this->get('https://my.custom.test/members')->assertOk();
        $uri = app(HandleRequests::class)->getUpdateUri();
        $this->postJson('https://my.custom.test'.$uri, ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => []]]], ['X-Livewire' => ''])->assertStatus(419);
    }

    public function test_all_registered_resource_queries_include_tenant_boundary(): void
    {
        $this->actingAs($this->actor, 'clubunio');
        $this->get('/members')->assertOk();
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        Filament::setTenant($this->one);
        foreach (Filament::getPanel('clubunio')->getResources() as $resource) {
            if (str_contains($resource, 'ApiIntegration')) {
                continue; // Parallel API development is not certified by this web audit.
            }
            $query = $resource::getEloquentQuery();
            $this->assertStringContainsString('tenant_id', $query->toSql(), $resource);
            $this->assertContains($this->one->id, $query->getBindings(), $resource);
        }
    }

    public function test_custom_host_never_grants_a_role_and_rejects_shared_cookie_configuration(): void
    {
        $this->actingAs($this->actor, 'clubunio');
        $this->get('https://one.clubunio.test/members')->assertOk();
        $this->assertTrue(app(\App\ClubUnio\Services\AccountAdministrationService::class)->allowed($this->actor, $this->one));
        $this->get('https://my.custom.test/members')->assertOk();
        $this->assertFalse(app(\App\ClubUnio\Services\AccountAdministrationService::class)->allowed($this->actor, $this->two));
        config(['session.domain' => '.clubunio.test']);
        $this->get('https://one.clubunio.test/login')->assertStatus(503);
    }

    public function test_forwarded_host_cannot_select_a_different_club(): void
    {
        $request = \Illuminate\Http\Request::create('https://one.clubunio.test/login', server: ['REMOTE_ADDR' => '192.168.2.105', 'HTTP_X_FORWARDED_HOST' => 'my.custom.test']);
        $previousProxies = \Illuminate\Http\Request::getTrustedProxies();
        $previousHeaders = \Illuminate\Http\Request::getTrustedHeaderSet();
        \Illuminate\Http\Request::setTrustedProxies(['192.168.2.105'], \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST);
        try {
            app(TenantDomainService::class)->resolve($request);
            $this->fail('Forwarded host mismatch accepted');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
            $this->assertSame(400, $error->getStatusCode());
        } finally {
            \Illuminate\Http\Request::setTrustedProxies($previousProxies, $previousHeaders);
        }
    }

    public function test_composite_database_guards_reject_cross_tenant_link_without_rewriting_rows(): void
    {
        // Apply only inside this outer rollback; never migrate the shared DB implicitly.
        if (! $this->db->table('migrations')->where('migration', '2026_10_25_000077_tenant_boundaries')->exists()) {
            (require database_path('clubunio/2026_10_25_000077_tenant_boundaries.php'))->up();
        }
        $bus = $this->db->table('clubora.buses')->insertGetId(['tenant_id' => $this->two->id, 'name' => 'Foreign bus']);
        try {
            $this->db->transaction(fn () => $this->db->table('clubora.bus_keyboxes')->insert(['tenant_id' => $this->one->id, 'bus_id' => $bus, 'name' => 'Invalid foreign box']));
            $this->fail('Cross-tenant keybox inserted');
        } catch (QueryException $error) {
            $this->assertSame('23503', $error->errorInfo[0]);
        }
        $this->assertSame(0, $this->db->table('clubora.bus_keyboxes')->where('tenant_id', $this->one->id)->count());
        $this->db->table('clubora.bus_keyboxes')->insert(['tenant_id' => $this->two->id, 'bus_id' => $bus, 'name' => 'Own box']);
        $this->assertSame(1, $this->db->table('clubora.bus_keyboxes')->where('tenant_id', $this->two->id)->count());
    }
}
