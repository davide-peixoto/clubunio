<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\AccessProfileService;
use App\ClubUnio\Services\PermissionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AccessProfileTest extends TestCase
{
    private $db;
    private Tenant $tenant;
    private Account $actor;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Isolated transaction opt-in');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        if (! Schema::connection('clubunio')->hasTable('clubora.access_profiles')) {
            (require database_path('clubunio/2026_10_16_000069_access_profiles.php'))->up();
        }
        $this->tenant = Tenant::create(['slug' => 'profile-'.Str::random(12), 'name' => 'Profile fixture']);
        $this->actor = new Account;
        $this->actor->forceFill(['username' => Str::random(16), 'display_name' => 'Fixture', 'password' => bcrypt('fixture'), 'active' => true])->save();
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $this->actor->id, 'role' => 'admin']);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->transactionLevel()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    private function input(array $permissions): array
    {
        return ['name' => 'Fixture role', 'description' => 'Fixture', 'active' => true, 'permissions' => $permissions, 'reason' => 'Synthetic test changes'];
    }

    public function test_read_only_profile_contains_no_write_export_or_download_permissions(): void
    {
        $permissions = PermissionCatalog::readOnly();
        $this->assertNotEmpty($permissions);
        foreach ($permissions as $permission) {
            $this->assertStringEndsWith('.view', $permission);
        }
        $profile = app(AccessProfileService::class)->save($this->actor, $this->tenant, null, $this->input($permissions));
        $this->assertSame($permissions, $profile->fresh()->permissions);
        $this->assertSame(1, $profile->revision);
        $this->assertSame(1, $this->db->table('clubora.object_changes')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_unknown_permissions_and_missing_view_are_rejected(): void
    {
        foreach ([['members.update'], ['members.view', 'members.superadmin'], ['members.view', 'members.view']] as $permissions) {
            try {
                app(AccessProfileService::class)->save($this->actor, $this->tenant, null, $this->input($permissions));
                $this->fail('Invalid permissions accepted');
            } catch (ValidationException) {
                $this->assertSame(0, $this->db->table('clubora.access_profiles')->where('tenant_id', $this->tenant->id)->count());
            }
        }
    }

    public function test_stale_profile_and_non_admin_are_rejected(): void
    {
        $service = app(AccessProfileService::class);
        $profile = $service->save($this->actor, $this->tenant, null, $this->input(['members.view']));
        $service->save($this->actor, $this->tenant, $profile->id, $this->input(['members.view', 'members.update']) + ['revision' => 1]);
        try {
            $service->save($this->actor, $this->tenant, $profile->id, $this->input([]) + ['revision' => 1]);
            $this->fail('Stale update accepted');
        } catch (ValidationException) {
            $this->assertSame(2, $profile->fresh()->revision);
        }
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)->update(['role' => 'office']);
        $this->expectException(HttpException::class);
        $service->save($this->actor, $this->tenant, $profile->id, $this->input([]) + ['revision' => 2]);
    }
}
