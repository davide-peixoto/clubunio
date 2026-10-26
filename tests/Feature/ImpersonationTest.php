<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\ImpersonationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    private $db;
    private Tenant $tenant;
    private Account $admin;
    private Account $target;
    private const PASSWORD = 'Support-fixture-12345';

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Synthetic rollback tests require opt-in');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        Http::preventStrayRequests();
        $this->tenant = Tenant::create(['slug' => 'support-'.Str::lower(Str::random(12)), 'name' => 'Support fixture']);
        $this->admin = $this->account('Support administrator');
        $this->target = $this->account('Simulated office');
        $this->admin->tenants()->attach($this->tenant, ['role' => 'admin']);
        $this->target->tenants()->attach($this->tenant, ['role' => 'office', 'portal_enabled' => true]);
        $this->actingAs($this->admin, 'clubunio');
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
        $account->forceFill(['username' => Str::random(20).'@example.test', 'display_name' => $name,
            'password' => Hash::make(self::PASSWORD), 'active' => true, 'api_only' => false])->save();
        return $account;
    }

    private function grant(): void
    {
        $this->post('/support/impersonation/grant', ['target_id' => $this->admin->id, 'enabled' => 1,
            'revision' => 0, 'reason' => 'Synthetic support approval', 'password' => self::PASSWORD])->assertRedirect('/support/impersonation');
    }

    private function start(array $override = [])
    {
        return $this->post('/support/impersonation/start', array_replace(['target_id' => $this->target->id,
            'reason' => 'Synthetic support investigation', 'password' => self::PASSWORD], $override));
    }

    public function test_admin_requires_separate_grant_and_password(): void
    {
        $this->get('/support/impersonation')->assertOk()->assertSee('nicht freigeschaltet');
        $this->start()->assertForbidden();
        $this->grant();
        $this->start(['password' => 'wrong'])->assertForbidden();
        $this->assertSame(0, $this->db->table('clubora.support_sessions')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_start_read_and_stop_preserve_real_identity_and_audit(): void
    {
        $this->grant();
        $this->start()->assertRedirect('/');
        $id = session(ImpersonationService::KEY);
        $this->get('/members')->assertOk()->assertSee('Simulation als')->assertSee('Simulated office')
            ->assertHeader('X-ClubUnio-Impersonation', 'read-only');
        $this->assertAuthenticatedAs($this->admin, 'clubunio');
        $read = $this->db->table('clubora.support_events')->where('session_id', $id)->where('action', 'read')->first();
        $this->assertSame($this->admin->id, $read->actor_id);
        $this->assertSame($this->target->id, $read->target_id);
        $this->post('/support/impersonation/stop')->assertRedirect('/support/impersonation');
        $this->assertNull(session(ImpersonationService::KEY));
        $this->assertNotNull($this->db->table('clubora.support_sessions')->where('id', $id)->value('ended_at'));
        $this->assertAuthenticatedAs($this->admin, 'clubunio');
    }

    public function test_mutations_secrets_and_nested_simulation_are_blocked(): void
    {
        $this->grant();
        $this->start()->assertRedirect('/');
        foreach (['/support/impersonation/start', '/support/impersonation/grant', route('default-livewire.update', [], false), '/portal/logout'] as $path) {
            $this->post($path)->assertForbidden();
        }
        foreach (['/api-integrations', '/api-docs', '/members/create', '/support/impersonation'] as $path) {
            $this->get($path)->assertForbidden();
        }
        $this->assertSame(1, $this->db->table('clubora.support_sessions')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_target_admin_inactive_technical_and_other_tenant_are_rejected(): void
    {
        $this->grant();
        $this->start(['target_id' => $this->admin->id])->assertForbidden();
        $foreign = $this->account('Foreign');
        $this->start(['target_id' => $foreign->id])->assertForbidden();
        $this->target->forceFill(['active' => false])->save();
        $this->start()->assertForbidden();
        $this->target->forceFill(['active' => true, 'api_only' => true])->save();
        $this->start()->assertForbidden();
    }

    public function test_revocation_and_expiry_fail_closed(): void
    {
        $this->grant();
        $this->start()->assertRedirect('/');
        $this->db->table('clubora.support_grants')->where('account_id', $this->admin->id)->update(['enabled' => false]);
        $this->get('/members')->assertForbidden();
        $this->assertNull(session(ImpersonationService::KEY));
        $this->db->table('clubora.support_grants')->where('account_id', $this->admin->id)->update(['enabled' => true]);
        $this->start()->assertRedirect('/');
        $this->travel(16)->minutes();
        $this->get('/members')->assertForbidden();
        $this->assertNull(session(ImpersonationService::KEY));
    }

    public function test_support_grant_cannot_be_issued_to_non_admin_or_by_non_admin(): void
    {
        $data = ['target_id' => $this->target->id, 'enabled' => 1, 'revision' => 0, 'reason' => 'Synthetic reason for approval', 'password' => self::PASSWORD];
        $this->post('/support/impersonation/grant', $data)->assertUnprocessable();
        $this->actingAs($this->target, 'clubunio')->get('/support/impersonation')->assertForbidden();
        $this->post('/support/impersonation/grant', $data)->assertForbidden();
    }

    public function test_portal_target_does_not_gain_backoffice_access(): void
    {
        $this->grant();
        $this->db->table('clubora.tenant_accounts')->where('account_id', $this->target->id)->update(['role' => 'member']);
        $member = $this->db->table('clubora.members')->insertGetId(['tenant_id' => $this->tenant->id, 'first_name' => 'Support', 'last_name' => 'Fixture']);
        $this->db->table('clubora.account_member_links')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $this->target->id,
            'member_id' => $member, 'kind' => 'self', 'state' => 'approved', 'source' => 'manual']);
        $this->start()->assertRedirect('/portal/'.$this->tenant->slug.'/members');
        $this->get('/members')->assertForbidden();
        $this->get('/portal/'.$this->tenant->slug.'/members')->assertOk()->assertSee('Simulation als');
    }

    public function test_tenant_switch_and_admin_demotion_cannot_escape_simulation(): void
    {
        $this->grant();
        $this->start()->assertRedirect('/');
        $other = Tenant::create(['slug' => 'other-'.Str::lower(Str::random(12)), 'name' => 'Other fixture']);
        $this->get('/portal/'.$other->slug.'/members')->assertForbidden();
        $this->db->table('clubora.tenant_accounts')->where('account_id', $this->admin->id)->update(['role' => 'office']);
        $this->get('/members')->assertForbidden();
        $this->assertNull(session(ImpersonationService::KEY));
    }

    public function test_stale_grant_cannot_override_newer_choice(): void
    {
        $this->grant();
        $this->post('/support/impersonation/grant', ['target_id' => $this->admin->id, 'enabled' => 0,
            'revision' => 0, 'reason' => 'Stale approval form fixture', 'password' => self::PASSWORD])->assertConflict();
        $this->assertTrue(app(ImpersonationService::class)->allowed($this->admin, $this->tenant));
    }
}
