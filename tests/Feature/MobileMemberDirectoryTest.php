<?php

namespace Tests\Feature;

use App\ClubUnio\Models\{Account, Tenant};
use Illuminate\Support\Facades\{DB, Http};
use Tests\TestCase;

class MobileMemberDirectoryTest extends TestCase
{
    private $db;
    protected function tearDown(): void { if (isset($this->db)) { $this->db->rollBack(); } parent::tearDown(); }
    public function test_directory_uses_personal_scope_literal_search_and_minimal_rows(): void
    {
        if (getenv('CLUBUNIO_TEST_DB') !== '1') { $this->markTestSkipped('Opt-in synthetic rollback required'); }
        config(['clubunio-mobile.enabled' => true, 'clubunio-mobile.native_members_enabled' => true, 'cache.default' => 'array', 'hashing.bcrypt.rounds' => 4]);
        Http::preventStrayRequests(); $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction(); $this->db->statement("SET LOCAL application_name TO 'clubunio-mobile-directory-test'");
        $tenant = Tenant::create(['slug' => 'directory-'.bin2hex(random_bytes(6)), 'name' => 'Synthetic directory']);
        $actor = new Account; $actor->forceFill(['username' => 'directory-'.bin2hex(random_bytes(8)), 'display_name' => 'Synthetic directory member', 'password' => bcrypt('fixture'), 'active' => true, 'api_only' => false])->save();
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $tenant->id, 'account_id' => $actor->id, 'role' => 'member', 'portal_enabled' => true]);
        $member = $this->db->table('clubora.members')->insertGetId(['tenant_id' => $tenant->id, 'first_name' => 'Synthetic', 'last_name' => 'Visible', 'mother_email' => 'private@example.invalid']);
        $hidden = $this->db->table('clubora.members')->insertGetId(['tenant_id' => $tenant->id, 'first_name' => 'Synthetic', 'last_name' => 'Hidden']);
        $this->db->table('clubora.account_member_links')->insert(['tenant_id' => $tenant->id, 'account_id' => $actor->id, 'member_id' => $member, 'state' => 'approved', 'kind' => 'self', 'source' => 'test']);
        $token = $this->postJson('/api/mobile/v1/session', ['username' => $actor->username, 'password' => 'fixture', 'device_name' => 'Synthetic directory'])->assertOk()->json('data.token');
        $url = '/api/mobile/v1/clubs/'.$tenant->id.'/members'; $this->withToken($token);
        $response = $this->getJson($url)->assertOk()->assertJsonPath('data.totalRecordCount', 1)
            ->assertJsonPath('data.Members.0.MitgliederID', $member)->assertJsonPath('data.Members.0.CanOpenDossier', true);
        $this->assertEqualsCanonicalizing(['MitgliederID', 'Vorname', 'Nachname', 'Email', 'Mobile', 'Ort', 'CanOpenDossier'], array_keys($response->json('data.Members.0')));
        $this->getJson($url.'?q=%25')->assertOk()->assertJsonPath('data.totalRecordCount', 0);
        $this->getJson($url.'?q=Visible&recperpage=1')->assertOk()->assertJsonCount(1, 'data.Members');
        $this->getJson($url.'?recperpage=51')->assertUnprocessable();
        $this->getJson($url.'/'.$hidden)->assertNotFound();
        foreach (['pending', 'approved', 'rejected', 'withdrawn'] as $state) {
            $this->db->table('clubora.member_change_statuses')->insert(['tenant_id' => $tenant->id, 'code' => $state, 'name' => $state, 'color' => '#164B8A']);
        }
        $source = \App\ClubUnio\Models\Member::withoutGlobalScopes()->findOrFail($member);
        $change = app(\App\ClubUnio\Services\MemberChangeService::class)->submit($actor, $tenant, $member,
            ['_version' => \App\ClubUnio\Services\MemberService::version($source), 'mobile' => '+41000000000']);
        $this->getJson($url.'/change-requests')->assertForbidden(); // A visible name grants no private review queue.
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $tenant->id)->where('account_id', $actor->id)->update(['role' => 'admin']);
        $this->getJson($url)->assertOk()->assertJsonPath('data.totalRecordCount', 2)->assertJsonMissingPath('data.Members.0.Mutter');
        $this->getJson($url.'/change-requests')->assertOk()->assertJsonPath('data.totalRecordCount', 1)
            ->assertJsonPath('data.requests.0.CanApprove', true)->assertJsonPath('data.requests.0.CanReject', true);
        $profile = $this->db->table('clubora.access_profiles')->insertGetId(['tenant_id' => $tenant->id, 'name' => 'Synthetic rejection reviewer',
            'active' => true, 'permissions' => json_encode(['members.view', 'member_changes.view', 'member_changes.approve'])]);
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $tenant->id)->where('account_id', $actor->id)->update(['role' => 'profile', 'access_profile_id' => $profile, 'portal_enabled' => false]);
        $this->getJson($url.'/change-requests')->assertForbidden(); // Profiles do not acquire personal app entry from a module grant.
        $this->getJson($url)->assertForbidden();
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $tenant->id)->where('account_id', $actor->id)->update(['role' => 'office', 'access_profile_id' => null, 'portal_enabled' => true]);
        $this->postJson($url.'/'.$member.'/change-requests/'.$change->id.'/decide', ['Revision' => 1, 'approve' => false, 'Begruendung' => 'Synthetic reviewed rejection'])
            ->assertOk()->assertJsonPath('data.status', 'ABGELEHNT');
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $tenant->id)->where('account_id', $actor->id)->update(['role' => 'member']);
        $this->getJson($url)->assertOk()->assertJsonPath('data.CanReviewChangeRequests', false);
        $this->getJson($url.'/change-requests')->assertForbidden();
        $this->db->table('clubora.account_member_links')->where('account_id', $actor->id)->update(['state' => 'pending']);
        $this->getJson($url)->assertForbidden();
    }
}
