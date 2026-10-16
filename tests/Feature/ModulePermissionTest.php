<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\AccessProfileService;
use App\ClubUnio\Services\AccountAdministrationService;
use App\ClubUnio\Services\ModulePermissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ModulePermissionTest extends TestCase
{
    private $db;
    private Tenant $tenant;
    private Account $admin;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Transaction opt-in required');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        if (! Schema::connection('clubunio')->hasTable('clubora.access_profiles')) {
            (require database_path('clubunio/2026_10_16_000069_access_profiles.php'))->up();
        }
        if (! Schema::connection('clubunio')->hasColumn('clubora.tenant_accounts', 'access_profile_id')) {
            (require database_path('clubunio/2026_10_16_000070_account_access_profiles.php'))->up();
        }
        $this->db->table('clubora.account_roles')->where('code', 'profile')->update(['active' => true]);
        $this->tenant = Tenant::create(['slug' => 'permission-'.Str::random(12), 'name' => 'Permission fixture']);
        $this->admin = new Account;
        $this->admin->forceFill(['username' => Str::random(20), 'display_name' => 'Admin fixture', 'password' => bcrypt('fixture'), 'active' => true])->save();
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $this->admin->id, 'role' => 'admin']);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->transactionLevel()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    private function profile()
    {
        return app(AccessProfileService::class)->save($this->admin, $this->tenant, null, [
            'name' => 'Read fixture', 'active' => true, 'permissions' => ['members.view'], 'reason' => 'Synthetic permission fixture',
        ]);
    }

    private function input(int $profile): array
    {
        return ['username' => Str::random(20), 'display_name' => 'Profile fixture', 'active' => true,
            'role' => 'profile', 'access_profile_id' => $profile, 'portal_enabled' => false,
            'password' => 'Synthetic-fixture-password', 'password_confirmation' => 'Synthetic-fixture-password',
            'reason' => 'Synthetic permission fixture'];
    }

    public function test_assignment_is_a_separate_role_and_fails_closed_for_legacy_access(): void
    {
        $profile = $this->profile();
        $link = app(AccountAdministrationService::class)->save($this->admin, $this->tenant, null, $this->input($profile->id));
        $this->assertSame('profile', $link->role);
        $this->assertSame($profile->id, (int) $link->access_profile_id);
        $this->assertFalse($link->account->canAccessTenant($this->tenant));
        $this->assertFalse($link->account->hasBackofficeTenant());
        $this->assertFalse(app(AccountAdministrationService::class)->allowed($link->account, $this->tenant));
        $after = $this->db->table('clubora.object_changes')->where('object_type', 'tenant_accounts')->where('object_id', $link->id)->value('after_value');
        $this->assertSame($profile->id, json_decode($after, true)['access_profile_id']);
    }

    public function test_read_only_permissions_are_fresh_and_default_deny(): void
    {
        $profile = $this->profile();
        $link = app(AccountAdministrationService::class)->save($this->admin, $this->tenant, null, $this->input($profile->id));
        $gate = app(ModulePermissionService::class);
        $actor = $link->account;
        $this->assertTrue($gate->allows($actor, $this->tenant, 'members', 'view'));
        foreach (['create', 'update', 'copy', 'deactivate', 'restore', 'export', 'unknown'] as $function) {
            $this->assertFalse($gate->allows($actor, $this->tenant, 'members', $function));
        }
        $this->assertFalse($gate->allows($actor, $this->tenant, 'invoices', 'view'));
        $this->assertFalse($gate->allows($actor, $this->tenant, 'unknown', 'view'));
        $profile->forceFill(['active' => false])->save();
        $this->assertFalse($gate->allows($actor, $this->tenant, 'members', 'view'));
        $profile->forceFill(['active' => true])->save();
        $this->assertTrue($gate->allows($actor, $this->tenant, 'members', 'view'));
        $this->db->table('clubora.account_roles')->where('code', 'profile')->update(['active' => false]);
        $this->assertFalse($gate->allows($actor, $this->tenant, 'members', 'view'));
        $this->db->table('clubora.account_roles')->where('code', 'profile')->update(['active' => true]);
        $this->db->table('clubora.accounts')->where('id', $actor->id)->update(['active' => false]);
        $this->assertFalse($gate->allows($actor, $this->tenant, 'members', 'view'));
    }

    public function test_foreign_tenant_and_inactive_profile_are_not_assignable(): void
    {
        $profile = $this->profile();
        $profile->forceFill(['active' => false])->save();
        try {
            app(AccountAdministrationService::class)->save($this->admin, $this->tenant, null, $this->input($profile->id));
            $this->fail('Inactive profile accepted');
        } catch (ValidationException) {
            $this->assertSame(1, $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)->count());
        }
        $other = Tenant::create(['slug' => 'foreign-'.Str::random(12), 'name' => 'Foreign fixture']);
        $profile->forceFill(['tenant_id' => $other->id, 'active' => true])->save();
        $this->expectException(ValidationException::class);
        app(AccountAdministrationService::class)->save($this->admin, $this->tenant, null, $this->input($profile->id));
    }

    public function test_portal_and_admin_profile_combination_is_rejected(): void
    {
        $profile = $this->profile();
        foreach ([['portal_enabled' => true], ['role' => 'admin'], ['role' => 'office']] as $override) {
            try {
                app(AccountAdministrationService::class)->save($this->admin, $this->tenant, null, array_replace($this->input($profile->id), $override));
                $this->fail('Invalid role/profile combination accepted');
            } catch (ValidationException) {
                $this->assertSame(1, $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)->count());
            }
        }
    }

    public function test_last_admin_cannot_be_converted_to_profile(): void
    {
        $profile = $this->profile();
        $link = $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)->first();
        $input = $this->input($profile->id);
        unset($input['username'], $input['password'], $input['password_confirmation']);
        $this->expectException(ValidationException::class);
        app(AccountAdministrationService::class)->save($this->admin, $this->tenant, $link->id,
            $input + ['revision' => $link->revision, 'account_revision' => $this->admin->fresh()->revision]);
    }

    public function test_cross_tenant_access_and_permission_revocation_are_immediate(): void
    {
        $profile = $this->profile();
        $link = app(AccountAdministrationService::class)->save($this->admin, $this->tenant, null, $this->input($profile->id));
        $gate = app(ModulePermissionService::class);
        $other = Tenant::create(['slug' => 'other-'.Str::random(12), 'name' => 'Other fixture']);
        $this->assertFalse($gate->allows($link->account, $other, 'members', 'view'));
        $profile->forceFill(['permissions' => ['members.view', 'members.update']])->save();
        $this->assertTrue($gate->allows($link->account, $this->tenant, 'members', 'update'));
        $profile->forceFill(['permissions' => ['members.view']])->save();
        $this->assertFalse($gate->allows($link->account, $this->tenant, 'members', 'update'));
        // Invalid persisted dependencies must not grant access either.
        $profile->forceFill(['permissions' => ['members.update']])->save();
        $this->assertFalse($gate->allows($link->account, $this->tenant, 'members', 'update'));
    }

    public function test_non_admin_cannot_assign_and_stale_edit_is_rejected(): void
    {
        $profile = $this->profile();
        $service = app(AccountAdministrationService::class);
        $link = $service->save($this->admin, $this->tenant, null, $this->input($profile->id));
        $input = $this->input($profile->id);
        unset($input['username'], $input['password'], $input['password_confirmation']);
        $input += ['revision' => $link->revision, 'account_revision' => $link->account->revision];
        try {
            $service->save($link->account, $this->tenant, $link->id, $input);
            $this->fail('Non-admin assigned a profile');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $service->save($this->admin, $this->tenant, $link->id, $input);
        $this->expectException(ValidationException::class);
        $service->save($this->admin, $this->tenant, $link->id, $input);
    }
}
