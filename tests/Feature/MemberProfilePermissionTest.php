<?php

namespace Tests\Feature;

use App\ClubUnio\Models\{Account, Member, MemberKey, Registration, Tenant};
use App\ClubUnio\Services\{AnnouncementService, BankAccountService, DocumentUploadService, DossierHistoryService, DossierMaintenanceService, FamilyService, MaterialReceiptService, MemberAccessService, MemberChangeService, MemberDocumentService, MemberNoteService, MemberService, MessageTemplateService, PermissionCatalog, RegistrationDataService, RegistrationDocumentService, RegistrationMessageService, RegistrationReviewService, TestMailService};
use Illuminate\Support\Facades\{DB, Http, Mail, Schema};
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MemberProfilePermissionTest extends TestCase
{
    private $db;
    private Tenant $tenant;
    private Account $actor;
    private Member $member;
    private int $profile;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Synthetic transaction opt-in required');
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
        $this->tenant = Tenant::create(['slug' => 'member-permission-'.Str::random(12), 'name' => 'Synthetic fixture']);
        $this->actor = new Account;
        $this->actor->forceFill(['username' => Str::random(20), 'display_name' => 'Synthetic profile', 'password' => bcrypt('fixture'), 'active' => true])->save();
        $this->profile = $this->db->table('clubora.access_profiles')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => 'Synthetic permissions', 'permissions' => '[]']);
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $this->actor->id, 'role' => 'profile', 'access_profile_id' => $this->profile, 'portal_enabled' => false]);
        $this->member = new Member;
        $this->member->forceFill(['tenant_id' => $this->tenant->id, 'first_name' => 'Synthetic', 'last_name' => 'Member'])->save();
        Http::preventStrayRequests();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->transactionLevel()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    private function permissions(array $permissions): void
    {
        $this->db->table('clubora.access_profiles')->where('id', $this->profile)->update(['permissions' => json_encode($permissions)]);
    }

    private function denied(callable $action): void
    {
        try {
            $action();
            $this->fail('Operation should have been forbidden');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    private function materialPortalFixture(): array
    {
        $receipt = (new \App\ClubUnio\Models\MaterialReceipt)->forceFill([
            'id' => 987654, 'tenant_id' => $this->tenant->id, 'member_id' => $this->member->id,
            'title' => 'Synthetic receipt', 'handover_on' => '2026-10-16', 'state' => 'issued',
            'revision' => 1, 'items' => [], 'snapshot' => ['confirmation' => 'Synthetic confirmation'],
        ])->setRelation('member', $this->member);
        $history = (new \App\ClubUnio\Models\LegacyMaterialReceipt)->forceFill([
            'id' => 987655, 'title' => 'Synthetic history', 'source_state' => 'SIGNED',
        ])->setRelation('member', $this->member);

        return ['tenant' => $this->tenant, 'tenants' => collect([$this->tenant]), 'receipt' => $receipt,
            'receipts' => new \Illuminate\Pagination\LengthAwarePaginator([$receipt], 1, 20),
            'history' => new \Illuminate\Pagination\LengthAwarePaginator([$history], 1, 20),
            'states' => [], 'errors' => new \Illuminate\Support\ViewErrorBag];
    }

    public function test_material_portal_render_never_offers_profile_actions_even_with_self_link(): void
    {
        $this->db->table('clubora.account_member_links')->insert(['tenant_id' => $this->tenant->id,
            'account_id' => $this->actor->id, 'member_id' => $this->member->id,
            'kind' => 'self', 'state' => 'approved', 'source' => 'test']);
        $this->actingAs($this->actor, 'portal');
        $data = $this->materialPortalFixture();
        foreach ([['documents.view'], ['documents.view', 'documents.download', 'documents.update', 'documents.send']] as $permissions) {
            $this->permissions($permissions);
            $this->view('clubunio.material.index', $data)
                ->assertDontSee(route('clubunio.portal.material.show', [$this->tenant->slug, 987654]), false)
                ->assertDontSee(route('clubunio.portal.material.history.pdf', [$this->tenant->slug, 987655]), false)
                ->assertDontSee('>Material</a>', false);
            $this->view('clubunio.material.show', $data)
                ->assertDontSee('Empfang bestätigen')->assertDontSee('PDF-Quittung');
            $this->get(route('clubunio.portal.material.index', $this->tenant->slug))->assertForbidden();
            $this->post(route('clubunio.portal.material.confirm', [$this->tenant->slug, 987654]),
                ['revision' => 1, 'accepted' => 1])->assertForbidden();
        }
    }

    public function test_material_portal_render_preserves_legacy_ownership_and_receipt_states(): void
    {
        $this->member->forceFill(['birth_date' => now()->subYears(12)->toDateString()])->save();
        $this->db->table('clubora.tenant_accounts')->where('account_id', $this->actor->id)
            ->update(['role' => 'member', 'access_profile_id' => null, 'portal_enabled' => true]);
        $this->actingAs($this->actor, 'portal');
        $data = $this->materialPortalFixture();
        $this->view('clubunio.material.show', $data)
            ->assertDontSee('Empfang bestätigen')->assertDontSee('PDF-Quittung');
        $link = $this->db->table('clubora.account_member_links')->insertGetId(['tenant_id' => $this->tenant->id,
            'account_id' => $this->actor->id, 'member_id' => $this->member->id,
            'kind' => 'self', 'state' => 'approved', 'source' => 'test']);
        foreach (['self', 'parent'] as $kind) {
            $this->db->table('clubora.account_member_links')->where('id', $link)->update(['kind' => $kind]);
            $this->view('clubunio.material.show', $data)->assertSee('Empfang bestätigen')->assertSee('PDF-Quittung');
            $this->view('clubunio.material.index', $data)
                ->assertSee(route('clubunio.portal.material.show', [$this->tenant->slug, 987654]), false)
                ->assertSee(route('clubunio.portal.material.history.pdf', [$this->tenant->slug, 987655]), false)
                ->assertSee('>Material</a>', false);
        }
        foreach (['confirmed', 'cancelled'] as $state) {
            $data['receipt']->state = $state;
            $this->view('clubunio.material.show', $data)->assertDontSee('Empfang bestätigen')->assertSee('PDF-Quittung');
        }
        $data['receipt']->state = 'draft';
        $this->view('clubunio.material.show', $data)->assertDontSee('Empfang bestätigen')->assertDontSee('PDF-Quittung');
        $data['receipt']->state = 'issued';
        $data['receipt']->snapshot = null;
        $this->view('clubunio.material.show', $data)->assertDontSee('Empfang bestätigen')->assertDontSee('PDF-Quittung');
        $data['receipt']->tenant_id = -1;
        $this->view('clubunio.material.show', $data)->assertDontSee('Empfang bestätigen')->assertDontSee('PDF-Quittung');
    }

    public function test_read_only_cannot_write_download_upload_approve_copy_or_send(): void
    {
        $this->permissions(PermissionCatalog::readOnly());
        $a = $this->actor;
        $t = $this->tenant;
        foreach ([
            fn () => app(MemberService::class)->create($a, $t, []),
            fn () => app(MemberService::class)->update($a, $t, $this->member->id, []),
            fn () => app(MemberService::class)->archive($a, $t, $this->member->id, ''),
            fn () => app(MemberNoteService::class)->save($a, $t, $this->member->id, null, []),
            fn () => app(BankAccountService::class)->save($a, $t, $this->member->id, null, []),
            fn () => app(FamilyService::class)->save($a, $t, \App\ClubUnio\Models\Family::class, null, []),
            fn () => app(RegistrationDataService::class)->save($a, $t, null, []),
            fn () => app(RegistrationDocumentService::class)->remove($a, $t, 1, 1, 1),
            fn () => app(RegistrationMessageService::class)->dispatchOne($a, $t, 1, 1),
            fn () => app(MemberChangeService::class)->review($a, $t, 1, 1, true, null),
            fn () => app(MemberDocumentService::class)->open($a, $t, 1),
            fn () => app(MemberDocumentService::class)->openRegistration($a, $t, 1),
            fn () => app(DocumentUploadService::class)->authorizeSource($a, $t, 'member', $this->member->id, [], null),
            fn () => app(DocumentUploadService::class)->authorizeSource($a, $t, 'registration', 1, [], null),
            fn () => app(AnnouncementService::class)->duplicate($a, $t, 1),
            fn () => app(AnnouncementService::class)->prepare($a, $t, 1, 1),
            fn () => app(AnnouncementService::class)->publish($a, $t, 1, 1, ''),
            fn () => app(AnnouncementService::class)->withdraw($a, $t, 1, 1),
            fn () => app(MessageTemplateService::class)->restore($a, $t, 1, 1, 1),
            fn () => app(TestMailService::class)->sendPreview($a, $t, 1, 'fixture'),
            fn () => app(MaterialReceiptService::class)->pdf($a, $t, 1, true),
        ] as $action) {
            $this->denied($action);
        }
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_profile_never_inherits_portal_or_generic_member_scope(): void
    {
        $this->permissions(PermissionCatalog::keys());
        $this->db->table('clubora.account_member_links')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $this->actor->id, 'member_id' => $this->member->id, 'kind' => 'self', 'state' => 'approved', 'source' => 'manual']);
        $access = app(MemberAccessService::class);
        $this->assertTrue($this->actor->canAccessTenant($this->tenant));
        $this->assertFalse($this->actor->hasUnrestrictedBackofficeAccess($this->tenant));
        $this->assertFalse($access->canEnter($this->actor, $this->tenant));
        $this->assertFalse($access->represented($this->actor, $this->tenant)->exists());
        $this->assertFalse($access->visibleMembers($this->actor, $this->tenant)->exists());
        $this->assertSame([], $access->responsibleTeams($this->actor, $this->tenant));
        $this->denied(fn () => app(MaterialReceiptService::class)->visible($this->actor, $this->tenant));
        $this->permissions(['documents.view']);
        $this->assertFalse(app(BankAccountService::class)->visible($this->actor, $this->tenant)->exists());
        $this->denied(fn () => app(BankAccountService::class)->save($this->actor, $this->tenant, $this->member->id, null, []));
        $this->denied(fn () => app(MemberChangeService::class)->represented($this->actor, $this->tenant, $this->member->id));
    }

    public function test_note_writes_check_create_update_lifecycle_and_fresh_revocation(): void
    {
        $this->permissions(['members.view', 'members.create']);
        $service = app(MemberNoteService::class);
        $note = $service->save($this->actor, $this->tenant, $this->member->id, null, ['body' => 'Original']);
        $this->assertSame('Original', $note->body);
        $update = fn ($active) => $service->save($this->actor, $this->tenant, $this->member->id, $note->id, ['revision' => $note->fresh()->revision, 'body' => 'Updated', 'active' => $active]);
        $this->denied(fn () => $update(true));
        $this->permissions(['members.view', 'members.update']);
        $this->assertSame('Updated', $update(true)->body);
        $this->denied(fn () => $update(false));
        $this->permissions(['members.view', 'members.deactivate']);
        $this->assertFalse($update(false)->active);
        $this->denied(fn () => $update(true));
        $this->permissions(['members.view', 'members.restore']);
        $this->assertTrue($update(true)->active);
        $this->permissions(['members.view']);
        $this->denied(fn () => $update(true));
        $this->assertNotEmpty(app(DossierHistoryService::class)->entries($this->actor, $this->tenant, $note));
        $this->permissions(['documents.view']);
        $this->denied(fn () => app(DossierHistoryService::class)->entries($this->actor, $this->tenant, $note));
    }

    public function test_dossier_model_and_action_mapping_cannot_expand_update_rights(): void
    {
        $service = app(DossierMaintenanceService::class);
        $data = ['number' => 'fixture', 'name' => 'Synthetic key', 'color' => 'Blue', 'active' => true];
        $save = fn ($id, $values, $copy = null) => $service->save($this->actor, $this->tenant, MemberKey::class, $this->member->id, $id, $values, $copy);
        $this->permissions(['members.view', 'members.create']);
        $key = $save(null, $data);
        $this->permissions(['members.view', 'members.update']);
        $this->denied(fn () => $save($key->id, ['revision' => 1, 'active' => false] + $data));
        $this->denied(fn () => $save(null, ['number' => 'copy'] + $data, $key->id));
        $this->denied(fn () => $service->save($this->actor, $this->tenant, \App\ClubUnio\Models\ClubFunction::class, null, null, ['name' => 'Forbidden', 'active' => true]));
        $this->permissions(['members.view', 'members.deactivate']);
        $key = $save($key->id, ['revision' => 1, 'active' => false] + $data);
        $this->assertFalse($key->active);
        $this->permissions(['members.view', 'members.restore']);
        $this->denied(fn () => $save($key->id, ['revision' => 2, 'name' => 'Smuggled edit'] + $data));
        $key = $save($key->id, ['revision' => 2] + $data);
        $this->assertTrue($key->active);
        $this->permissions(['members.view', 'members.copy']);
        $copy = $save(null, ['number' => 'copy'] + $data, $key->id);
        $this->assertNotSame($key->id, $copy->id);
        $this->assertSame(3, (int) $key->fresh()->revision);
    }

    public function test_registration_visibility_does_not_grant_review_and_revocation_is_fresh(): void
    {
        $registration = new Registration;
        $registration->forceFill(['tenant_id' => $this->tenant->id, 'first_name' => 'Synthetic', 'last_name' => 'Applicant', 'active' => true])->save();
        $service = app(RegistrationReviewService::class);
        $this->permissions(['registrations.view']);
        $this->assertTrue($service->visible($this->actor, $this->tenant)->whereKey($registration->id)->exists());
        $this->assertFalse($service->canReview($this->actor, $this->tenant, $registration));
        $this->permissions(['registrations.view', 'registrations.approve']);
        $this->assertTrue($service->canReview($this->actor, $this->tenant, $registration));
        $this->db->table('clubora.access_profiles')->where('id', $this->profile)->update(['active' => false]);
        $this->assertFalse($service->visible($this->actor, $this->tenant)->exists());
        $this->assertFalse($service->canReview($this->actor, $this->tenant, $registration));
    }

    public function test_copy_permission_does_not_require_create_or_grant_update(): void
    {
        $this->db->table('clubora.announcement_statuses')->insert(['tenant_id' => $this->tenant->id, 'code' => 'draft', 'name' => 'Synthetic draft']);
        $announcement = new \App\ClubUnio\Models\Announcement;
        $announcement->forceFill(['tenant_id' => $this->tenant->id, 'title' => 'Synthetic copy', 'body' => 'Synthetic body', 'target_type' => 'all', 'target_ids' => [], 'state' => 'draft', 'revision' => 1, 'created_by' => $this->actor->id])->save();
        $this->permissions(['announcements.view', 'announcements.copy']);
        $service = app(AnnouncementService::class);
        $copy = $service->duplicate($this->actor, $this->tenant, $announcement->id);
        $this->assertNotSame($announcement->id, $copy->id);
        $this->assertSame($announcement->title, $copy->title);
        $this->denied(fn () => $service->save($this->actor, $this->tenant, $copy->id, []));
        $this->denied(fn () => $service->save($this->actor, $this->tenant, null, []));
        $this->denied(fn () => $service->prepare($this->actor, $this->tenant, $copy->id, 1));
    }

    public function test_tenant_account_and_rollout_boundaries_are_checked_without_cached_privileges(): void
    {
        $this->permissions(['members.view', 'members.create']);
        $service = app(MemberNoteService::class);
        $other = Tenant::create(['slug' => 'foreign-permission-'.Str::random(12), 'name' => 'Synthetic foreign tenant']);
        $this->denied(fn () => $service->save($this->actor, $other, $this->member->id, null, ['body' => 'Forbidden']));
        $this->db->table('clubora.account_roles')->where('code', 'profile')->update(['active' => false]);
        $this->denied(fn () => $service->save($this->actor, $this->tenant, $this->member->id, null, ['body' => 'Forbidden']));
        $this->db->table('clubora.account_roles')->where('code', 'profile')->update(['active' => true]);
        $this->db->table('clubora.accounts')->where('id', $this->actor->id)->update(['active' => false]);
        $this->denied(fn () => $service->save($this->actor, $this->tenant, $this->member->id, null, ['body' => 'Forbidden']));
        $this->assertFalse(app(MemberAccessService::class)->canEnter($this->actor, $this->tenant));
    }
}
