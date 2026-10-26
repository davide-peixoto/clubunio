<?php

namespace Tests\Feature;

use App\ClubUnio\Api\ApiTokenService;
use App\ClubUnio\Models\AccessProfile;
use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\PlatformAdministrationService;
use App\ClubUnio\Services\SignageBrandService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformAdministrationTest extends TestCase
{
    private $db;

    private Account $operator;

    private Account $admin;

    private Account $person;

    private Tenant $one;

    private Tenant $two;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Explicit synthetic rollback tests required.');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        Http::preventStrayRequests();
        Mail::fake();
        $this->one = Tenant::create(['slug' => 'platform-one-'.Str::lower(Str::random(10)), 'name' => 'Synthetic platform one']);
        $this->two = Tenant::create(['slug' => 'platform-two-'.Str::lower(Str::random(10)), 'name' => 'Synthetic platform two']);
        $this->operator = $this->account('Platform operator');
        $this->admin = $this->account('Club administrator');
        $this->person = $this->account('Shared personal user');
        $this->db->table('clubora.platform_admins')->insert(['account_id' => $this->operator->id]);
        $this->admin->tenants()->attach($this->one, ['role' => 'admin']);
        $this->admin->tenants()->attach($this->two, ['role' => 'admin']);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            while ($this->db->transactionLevel()) {
                $this->db->rollBack();
            }
        }
        parent::tearDown();
    }

    private function account(string $name): Account
    {
        $account = new Account;
        $account->forceFill(['username' => Str::lower(Str::random(18)).'@example.test', 'display_name' => $name,
            'password' => Hash::make('Synthetic-password-123'), 'active' => true, 'api_only' => false])->save();

        return $account;
    }

    private function change(string $operation, array $data = [], ?Tenant $tenant = null)
    {
        $tenant ??= $this->one;

        return $this->actingAs($this->operator, 'clubunio')->post('http://localhost/platform/tenants/'.$tenant->id,
            ['operation' => $operation, 'revision' => $tenant->fresh()->platform_revision, 'reason' => 'Synthetic reviewed platform change', ...$data]);
    }

    private function domain(array $data = []): array
    {
        return ['host' => 'my.synthetic-platform.test', 'ownership_verified' => false, 'dns_verified' => false,
            'tls_verified' => false, 'active' => false, ...$data];
    }

    public function test_club_admins_guests_and_technical_accounts_cannot_enter_platform(): void
    {
        $this->get('/platform')->assertRedirect(route('clubunio.platform.login'));
        $this->actingAs($this->admin, 'clubunio')->get('/platform')->assertForbidden();
        $this->actingAs($this->admin, 'clubunio')->post('/platform/tenants', ['name' => 'Forbidden'])->assertForbidden();
        $this->operator->forceFill(['api_only' => true])->save();
        $this->actingAs($this->operator, 'clubunio')->get('/platform')->assertForbidden();
        $this->assertFalse(app(PlatformAdministrationService::class)->allowed($this->operator));
    }

    public function test_global_operator_has_no_implicit_club_access_and_ui_is_private(): void
    {
        $this->assertFalse($this->operator->canAccessTenant($this->one));
        $this->actingAs($this->operator, 'clubunio')->get('/platform?q='.$this->one->slug)
            ->assertOk()->assertSee($this->one->name)->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/platform/tenants/'.$this->one->id)->assertOk()->assertSee('Benutzerzuordnungen')->assertSee('Vereinsdesign');
    }

    public function test_platform_login_requires_global_role_and_rechecks_revocation(): void
    {
        $this->post('/platform/login', ['username' => $this->admin->username, 'password' => 'Synthetic-password-123'])
            ->assertSessionHasErrors('username');
        $this->post('/platform/login', ['username' => $this->operator->username, 'password' => 'Synthetic-password-123'])
            ->assertRedirect(route('clubunio.platform.index'));
        $this->assertAuthenticatedAs($this->operator, 'clubunio');
        $this->db->table('clubora.platform_admins')->where('account_id', $this->operator->id)->update(['active' => false]);
        $this->get('/platform')->assertForbidden();
        $this->change('name', ['name' => 'Never written'])->assertForbidden();
    }

    public function test_new_club_assigns_only_explicit_admin_and_copies_no_customer_data(): void
    {
        $slug = 'new-platform-'.Str::lower(Str::random(10));
        $this->actingAs($this->operator, 'clubunio')->post('/platform/tenants', ['name' => 'New synthetic club', 'slug' => $slug,
            'username' => $this->person->username, 'reason' => 'Create isolated test club'])->assertRedirect();
        $tenant = Tenant::where('slug', $slug)->firstOrFail();
        $this->assertTrue($this->person->canAccessTenant($tenant));
        $this->assertFalse($this->operator->canAccessTenant($tenant));
        $this->assertSame(0, $this->db->table('clubora.members')->where('tenant_id', $tenant->id)->count());
        $this->assertSame(0, $this->db->table('clubora.tenant_settings')->where('tenant_id', $tenant->id)->count());
        $this->assertTrue($this->db->table('clubora.platform_changes')->where('tenant_id', $tenant->id)->where('action', 'tenant.create')->exists());
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_stale_forms_and_protected_fields_do_not_change_tenant(): void
    {
        $this->change('name', ['name' => 'Updated synthetic club'])->assertRedirect();
        $this->actingAs($this->operator, 'clubunio')->post(route('clubunio.platform.change', $this->one->id),
            ['operation' => 'name', 'revision' => 1, 'name' => 'Stale', 'reason' => 'Stale platform submission'])->assertConflict();
        $this->change('name', ['name' => 'Bad', 'slug' => 'changed-slug'])->assertUnprocessable();
        $this->assertSame('Updated synthetic club', $this->one->fresh()->name);
        $this->assertSame(2, $this->one->fresh()->platform_revision);
    }

    public function test_brand_is_tenant_scoped_and_malicious_logo_is_rejected(): void
    {
        $before = app(SignageBrandService::class)->read($this->two);
        $this->change('brand', ['color' => '#127744', 'logo' => '/brand/blue-v1/clubunio-symbol.svg', 'title' => 'Synthetic club design'])->assertRedirect();
        $this->assertSame('#127744', app(SignageBrandService::class)->read($this->one)['color']);
        $this->assertSame($before, app(SignageBrandService::class)->read($this->two));
        $this->change('brand', ['color' => '#127744', 'logo' => 'javascript:alert(1)', 'title' => 'Unsafe'])->assertSessionHasErrors('logo');
        $this->assertSame(2, $this->one->fresh()->platform_revision);
        Http::assertNothingSent();
    }

    public function test_pending_domain_is_unavailable_until_all_verifications_and_activation(): void
    {
        $this->change('domain', $this->domain())->assertRedirect();
        $this->get('https://my.synthetic-platform.test/login')->assertNotFound();
        $this->change('domain', $this->domain(['active' => true]))->assertSessionHasErrors('host');
        $this->change('domain', $this->domain(['ownership_verified' => true, 'dns_verified' => true, 'tls_verified' => true, 'active' => true]))->assertRedirect();
        auth('clubunio')->logout();
        $this->get('https://my.synthetic-platform.test/login')->assertOk()->assertSee($this->one->name);
        $this->get('https://my.synthetic-platform.test/platform')->assertNotFound();
        $this->change('domain', $this->domain())->assertRedirect();
        $this->get('https://my.synthetic-platform.test/login')->assertNotFound();
    }

    public function test_domain_cannot_be_taken_over_or_used_for_central_or_unsafe_hosts(): void
    {
        $this->change('domain', $this->domain())->assertRedirect();
        $this->change('domain', $this->domain(), $this->two)->assertSessionHasErrors('host');
        foreach (['https://bad.test', '*.bad.test', '127.0.0.1', 'good.test/path', parse_url(config('app.url'), PHP_URL_HOST)] as $host) {
            $this->change('domain', $this->domain(['host' => $host]))->assertSessionHasErrors();
        }
        $this->assertSame(0, $this->db->table('clubora.tenant_domains')->where('tenant_id', $this->two->id)->count());
    }

    public function test_membership_changes_never_change_shared_global_account_or_other_club(): void
    {
        $hash = $this->person->password;
        $this->person->tenants()->attach($this->two, ['role' => 'office']);
        $this->change('membership', ['username' => $this->person->username, 'role' => 'office', 'portal_enabled' => false])->assertRedirect();
        $this->assertTrue($this->person->fresh()->canAccessTenant($this->one));
        $this->change('revoke', ['username' => $this->person->username])->assertRedirect();
        $this->assertFalse($this->person->fresh()->canAccessTenant($this->one));
        $this->assertTrue($this->person->fresh()->canAccessTenant($this->two));
        $this->assertSame($hash, $this->person->fresh()->password);
        $this->assertTrue($this->person->fresh()->active);
    }

    public function test_last_admin_is_protected_and_foreign_profiles_are_rejected(): void
    {
        $this->change('revoke', ['username' => $this->admin->username])->assertSessionHasErrors('role');
        $profile = new AccessProfile;
        $profile->forceFill(['tenant_id' => $this->two->id, 'name' => 'Other tenant profile', 'active' => true, 'permissions' => ['members.view']])->save();
        $this->change('membership', ['username' => $this->person->username, 'role' => 'profile', 'portal_enabled' => false, 'access_profile_id' => $profile->id])->assertUnprocessable();
        $this->assertTrue($this->admin->fresh()->canAccessTenant($this->one));
        $this->assertFalse($this->person->fresh()->canAccessTenant($this->one));
    }

    public function test_personal_account_creation_has_no_roles_and_never_audits_password(): void
    {
        $username = 'platform-account-'.Str::lower(Str::random(8)).'@example.test';
        $this->actingAs($this->operator, 'clubunio')->post('/platform/accounts', ['username' => $username, 'display_name' => 'New synthetic account',
            'password' => 'Synthetic-account-password-123', 'password_confirmation' => 'Synthetic-account-password-123', 'reason' => 'New isolated personal account'])->assertRedirect();
        $created = Account::where('username', $username)->firstOrFail();
        $this->assertTrue(Hash::check('Synthetic-account-password-123', $created->password));
        $this->assertSame(0, $created->tenants()->count());
        $this->assertFalse(app(PlatformAdministrationService::class)->allowed($created));
        $audit = $this->db->table('clubora.platform_changes')->where('actor_id', $this->operator->id)->pluck('details')->implode('');
        $this->assertStringNotContainsString('Synthetic-account-password-123', $audit);
        $this->assertStringNotContainsString($created->password, $audit);
    }

    public function test_database_domains_bind_both_api_and_web_without_switching_token_tenant(): void
    {
        $profile = new AccessProfile;
        $profile->forceFill(['tenant_id' => $this->one->id, 'name' => 'Synthetic API profile', 'active' => true, 'permissions' => ['teams.view']])->save();
        $service = app(ApiTokenService::class);
        $integration = $service->createIntegration($this->admin, $this->one, ['name' => 'Domain QA API',
            'access_profile_id' => $profile->id, 'scopes' => ['website:teams:read'], 'rate_limit' => 600]);
        $token = $service->issueToken($this->admin, $this->one, $integration->id, 'Domain fixture', ['website:teams:read'], now()->addHour())['token'];
        $this->change('domain', $this->domain(['ownership_verified' => true, 'dns_verified' => true, 'tls_verified' => true, 'active' => true]))->assertRedirect();
        $this->change('domain', $this->domain(['host' => 'other.synthetic-platform.test', 'ownership_verified' => true, 'dns_verified' => true, 'tls_verified' => true, 'active' => true]), $this->two)->assertRedirect();
        $this->withToken($token)->getJson('https://my.synthetic-platform.test/api/v1/context')->assertOk()->assertJsonPath('data.tenant.id', $this->one->id);
        $this->withToken($token)->getJson('https://other.synthetic-platform.test/api/v1/context')->assertNotFound()->assertJsonMissingPath('data');
        Http::assertNothingSent();
    }

    public function test_web_writes_require_csrf_outside_test_bypass(): void
    {
        $this->actingAs($this->operator, 'clubunio');
        $this->app->instance('env', 'production');
        $this->post('http://localhost/platform/tenants/'.$this->one->id,
            ['operation' => 'name', 'revision' => 1, 'name' => 'Forbidden CSRF change', 'reason' => 'Synthetic CSRF regression'])->assertStatus(419);
        $this->assertSame('Synthetic platform one', $this->one->fresh()->name);
    }
}
