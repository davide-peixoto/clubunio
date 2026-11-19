<?php

namespace Tests\Feature;

use App\ClubUnio\Models\AccessProfile;
use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\AccountMemberLink;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Resources\MemberResource\Pages\ViewMember;
use App\ClubUnio\Resources\Relations\PersonalAccessRelationManager;
use App\ClubUnio\Services\MemberAccessService;
use App\ClubUnio\Services\MemberPersonalAccessService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class MemberPersonalAccessTest extends TestCase
{
    private $db;

    private Tenant $tenant;

    private Account $admin;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Opt-in rollback tests');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->db->statement('SET LOCAL search_path TO clubunio');
        $this->tenant = Tenant::create(['slug' => 'access-'.Str::random(12), 'name' => 'Access fixture']);
        $this->admin = $this->account('admin');
        $this->member = new Member;
        $this->member->forceFill(['tenant_id' => $this->tenant->id, 'first_name' => 'Child', 'last_name' => 'Fixture', 'birth_date' => '2012-05-05'])->save();
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $this->actingAs($this->admin, 'clubunio');
        Filament::setTenant($this->tenant);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->transactionLevel()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    private function account(string $role, ?Tenant $tenant = null): Account
    {
        $account = new Account;
        $account->forceFill(['username' => Str::random(16).'@example.invalid', 'display_name' => 'Fixture '.$role, 'password' => bcrypt('fixture'), 'active' => true])->save();
        $this->db->table('tenant_accounts')->insert(['tenant_id' => ($tenant ?? $this->tenant)->id, 'account_id' => $account->id, 'role' => $role, 'portal_enabled' => true]);

        return $account;
    }

    private function link(Account $account, string $kind = 'parent', string $state = 'approved', ?Member $member = null): AccountMemberLink
    {
        $link = new AccountMemberLink;
        $link->forceFill(['tenant_id' => $this->tenant->id, 'member_id' => ($member ?? $this->member)->id,
            'account_id' => $account->id, 'kind' => $kind, 'state' => $state, 'source' => 'test', 'revision' => 1])->save();

        return $link;
    }

    private function rows()
    {
        return app(MemberPersonalAccessService::class)->scope($this->member->personalAccessLinks()->getQuery(), $this->tenant)->get();
    }

    private function manager()
    {
        return Livewire::test(PersonalAccessRelationManager::class, ['ownerRecord' => $this->member, 'pageClass' => ViewMember::class]);
    }

    public function test_explicit_parent_links_survive_staff_roles_without_listing_role_only_users(): void
    {
        $trainer = $this->account('trainer');
        $lead = $this->account('technical_lead');
        $roleOnly = $this->account('trainer');
        $links = [$this->link($trainer), $this->link($lead), $this->link($this->admin)];
        $rows = $this->rows();
        $this->assertCount(3, $rows);
        $this->assertFalse($rows->contains('account_id', $roleOnly->id));
        foreach ($rows as $link) {
            $this->assertSame('Lesen und Änderungsantrag', app(MemberPersonalAccessService::class)->capability($link, $this->member, true));
            $this->assertTrue(app(MemberAccessService::class)->represented($link->account, $this->tenant)->whereKey($this->member->id)->exists());
        }
        $this->manager()->assertCanSeeTableRecords($links)->assertSee('Elternvertretung')->assertSee('Lesen und Änderungsantrag');
    }

    public function test_inactive_revoked_pending_adult_and_portal_disabled_links_are_not_reported_as_access(): void
    {
        foreach (['pending', 'revoked'] as $state) {
            $this->link($this->account('member'), 'parent', $state);
        }
        $inactive = $this->account('member');
        $inactive->forceFill(['active' => false])->save();
        $this->link($inactive);
        $disabled = $this->account('trainer');
        $this->db->table('tenant_accounts')->where('account_id', $disabled->id)->update(['portal_enabled' => false]);
        $this->link($disabled);
        foreach ($this->rows() as $row) {
            $this->assertNotSame('Lesen und Änderungsantrag', app(MemberPersonalAccessService::class)->capability($row, $this->member, true));
        }
        $parent = $this->link($this->account('member'));
        $this->member->forceFill(['birth_date' => now('Europe/Zurich')->subYears(18)->toDateString()])->save();
        $row = $this->rows()->firstWhere('id', $parent->id);
        $this->assertSame('Elternvertretung nicht wirksam', app(MemberPersonalAccessService::class)->capability($row, $this->member->fresh(), true));
        $this->assertFalse(app(MemberAccessService::class)->represented($row->account, $this->tenant)->whereKey($this->member->id)->exists());
        $this->assertSame('Mitgliedschaft nicht aktiv', app(MemberPersonalAccessService::class)->capability($row, $this->member, false));
    }

    public function test_admin_can_review_and_edit_in_place_with_revision_and_existing_services(): void
    {
        $account = $this->account('trainer');
        $link = $this->link($account);
        $this->manager()->callTableAction('review', $link, data: ['account_id' => $account->id, 'member_id' => $this->member->id,
            'kind' => 'parent', 'state' => 'revoked', 'revision' => $link->revision, 'reason' => 'Synthetic access review'])
            ->assertHasNoTableActionErrors();
        $this->assertSame('revoked', $link->fresh()->state);
        $this->manager()->mountTableAction('manage', $link)->assertSee('Benutzer bearbeiten');
        $membership = $this->db->table('tenant_accounts')->where('tenant_id', $this->tenant->id)->where('account_id', $account->id)->first();
        $this->manager()->callTableAction('manage', $link, data: ['display_name' => 'Edited Parent Fixture', 'active' => true,
            'role' => 'trainer', 'portal_enabled' => true, 'revision' => $membership->revision,
            'account_revision' => $account->fresh()->revision, 'reason' => 'Synthetic account correction'])
            ->assertHasNoTableActionErrors();
        $this->assertSame('Edited Parent Fixture', $account->fresh()->display_name);
        $this->assertSame(1, $this->db->table('object_changes')->where('tenant_id', $this->tenant->id)->where('object_type', 'account_member_links')->count());
        $this->assertSame('trainer', $this->db->table('tenant_accounts')->where('account_id', $account->id)->value('role'));
        $this->manager()->callTableAction('review', $link, data: ['account_id' => $account->id, 'member_id' => $this->member->id,
            'kind' => 'parent', 'state' => 'approved', 'revision' => $link->revision, 'reason' => 'Stale synthetic request'])
            ->assertHasTableActionErrors();
        $this->assertSame('revoked', $link->fresh()->state);
    }

    public function test_office_can_review_links_but_not_edit_account_and_trainer_cannot_view_tab(): void
    {
        $link = $this->link($this->account('member'));
        $office = $this->account('office');
        $this->actingAs($office, 'clubunio');
        $this->manager()->assertTableActionVisible('review', $link)->assertTableActionHidden('manage', $link);
        $trainer = $this->account('trainer');
        $this->actingAs($trainer, 'clubunio');
        $this->assertFalse(PersonalAccessRelationManager::canViewForRecord($this->member, ViewMember::class));
        $this->manager()->assertForbidden();
    }

    public function test_foreign_owner_and_unrelated_record_are_scoped_out(): void
    {
        $account = $this->account('member');
        $link = $this->link($account);
        $other = new Member;
        $other->forceFill(['tenant_id' => $this->tenant->id, 'first_name' => 'Other'])->save();
        $otherLink = $this->link($account, 'self', 'approved', $other);
        $this->manager()->assertCanSeeTableRecords([$link])->assertCanNotSeeTableRecords([$otherLink]);
        $foreign = Tenant::create(['slug' => 'foreign-'.Str::random(12), 'name' => 'Foreign']);
        $foreignMember = new Member;
        $foreignMember->forceFill(['tenant_id' => $foreign->id, 'first_name' => 'Foreign'])->save();
        $this->assertFalse(PersonalAccessRelationManager::canViewForRecord($foreignMember, ViewMember::class));
        Livewire::test(PersonalAccessRelationManager::class, ['ownerRecord' => $foreignMember, 'pageClass' => ViewMember::class])->assertForbidden();
        $this->assertCount(0, app(MemberPersonalAccessService::class)->scope($this->member->personalAccessLinks()->getQuery(), $foreign)->get());
    }

    public function test_profile_reader_and_revoked_administrator_cannot_hydrate_the_access_tab(): void
    {
        $this->link($this->account('member'));
        $component = $this->manager();
        $this->db->table('tenant_accounts')->where('tenant_id', $this->tenant->id)->where('account_id', $this->admin->id)->update(['role' => 'trainer']);
        $component->call('$refresh')->assertForbidden();
        $profile = new AccessProfile;
        $profile->forceFill(['tenant_id' => $this->tenant->id, 'name' => 'Reader', 'active' => true, 'permissions' => ['members.view']])->save();
        $reader = $this->account('member');
        $this->db->table('tenant_accounts')->where('tenant_id', $this->tenant->id)->where('account_id', $reader->id)
            ->update(['role' => 'profile', 'access_profile_id' => $profile->id, 'portal_enabled' => false]);
        $this->actingAs($reader, 'clubunio');
        $this->assertFalse(PersonalAccessRelationManager::canViewForRecord($this->member, ViewMember::class));
        $this->manager()->assertForbidden();
    }
}
