<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\MemberDocument;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Resources\MemberChangeRequestResource\Pages\ListRequests;
use App\ClubUnio\Resources\MemberDocumentResource\Pages\ListMemberDocuments;
use App\ClubUnio\Resources\MemberResource\Pages\ViewMember;
use App\ClubUnio\Resources\Relations\DocumentsRelationManager;
use App\ClubUnio\Services\AzureDocumentReader;
use App\ClubUnio\Services\MemberChangeService;
use App\ClubUnio\Services\MemberDocumentMaintenanceService;
use App\ClubUnio\Services\MemberService;
use App\ClubUnio\Services\MessageTemplateService;
use App\ClubUnio\Services\RegistrationBlobStore;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MemberChangesDocumentsTest extends TestCase
{
    private $db;

    private Tenant $tenant;

    private Account $office;

    private Account $user;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Isolated DB opt-in');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->tenant = Tenant::create(['slug' => 'changes-'.Str::random(10), 'name' => 'Synthetic club']);
        foreach (['pending', 'approved', 'rejected', 'withdrawn'] as $code) {
            $this->insert('member_change_statuses', ['code' => $code, 'name' => $code, 'color' => 'gray']);
        }
        $this->office = $this->account('office');
        $this->user = $this->account('member');
        $this->member = new Member;
        $this->member->forceFill(['tenant_id' => $this->tenant->id, 'first_name' => 'Synthetic', 'last_name' => 'Member', 'city' => 'Old City', 'birth_date' => '2014-01-02'])->save();
        $this->link($this->user, $this->member, 'self');
        app(MessageTemplateService::class)->seed($this->tenant);
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $this->actingAs($this->office, 'clubunio');
        Filament::setTenant($this->tenant);
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

    private function insert(string $table, array $values): int
    {
        return $this->db->table('clubora.'.$table)->insertGetId(['tenant_id' => $this->tenant->id] + $values);
    }

    private function account(string $role): Account
    {
        $a = new Account;
        $a->forceFill(['username' => Str::random(20), 'display_name' => 'Synthetic '.$role, 'password' => bcrypt('fixture'), 'active' => true])->save();
        $this->insert('tenant_accounts', ['account_id' => $a->id, 'role' => $role, 'portal_enabled' => true]);

        return $a;
    }

    private function link(Account $a, Member $m, string $kind): void
    {
        $this->insert('account_member_links', ['account_id' => $a->id, 'member_id' => $m->id, 'kind' => $kind, 'state' => 'approved', 'source' => 'manual']);
    }

    private function submit(array $data = [])
    {
        return app(MemberChangeService::class)->submit($this->user, $this->tenant, $this->member->id, $data + ['city' => 'New City', '_version' => MemberService::version($this->member->fresh())]);
    }

    public function test_submission_is_encrypted_and_approval_updates_only_requested_values_once(): void
    {
        $r = $this->submit();
        $this->assertSame('Old City', $this->member->fresh()->city);
        $this->assertStringNotContainsString('New City', $r->snapshot_encrypted);
        $this->assertArrayNotHasKey('snapshot_encrypted', $r->toArray());
        $this->assertSame(1, $this->db->table('clubora.notifications')->where('tenant_id', $this->tenant->id)->count());
        $service = app(MemberChangeService::class);
        $service->review($this->office, $this->tenant, $r->id, 1, true, null);
        $this->assertSame('New City', $this->member->fresh()->city);
        $this->assertSame('approved', $r->fresh()->state);
        $this->assertSame(2, $this->db->table('clubora.notifications')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame('Old City', $service->comparison($r, $this->tenant)[0]['before']);
        Http::assertNothingSent();
        Mail::assertNothingSent();
        $this->expectException(ValidationException::class);
        $service->review($this->office, $this->tenant, $r->id, 1, true, null);
    }

    public function test_member_conflict_is_not_overwritten_and_rejection_allows_resubmission(): void
    {
        $r = $this->submit();
        $this->member->forceFill(['city' => 'Office change'])->save();
        try {
            app(MemberChangeService::class)->review($this->office, $this->tenant, $r->id, 1, true, null);
            $this->fail('Expected conflict');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('revision', $e->errors());
        }
        $this->assertSame('Office change', $this->member->fresh()->city);
        $this->assertSame('pending', $r->fresh()->state);
        app(MemberChangeService::class)->review($this->office, $this->tenant, $r->id, 1, false, 'Bitte neu erfassen');
        $this->assertSame('rejected', $r->fresh()->state);
        $this->assertNotSame($r->id, $this->submit()->id);
    }

    public function test_duplicate_and_protected_fields_and_foreign_lookup_are_rejected(): void
    {
        foreach ([['status_id' => 1], ['member_id' => 1], ['pass_number' => '123'], ['country_id' => 99999999], ['birth_date' => '2099-01-01']] as $input) {
            try {
                $this->submit($input);
                $this->fail('Protected/invalid input');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $this->submit();
        $this->expectException(ValidationException::class);
        $this->submit();
    }

    public function test_parent_minor_only_and_revocation_blocks_approval_but_allows_rejection(): void
    {
        $parent = $this->account('member');
        $this->link($parent, $this->member, 'parent');
        $r = app(MemberChangeService::class)->submit($parent, $this->tenant, $this->member->id, ['city' => 'Parent city', '_version' => MemberService::version($this->member->fresh())]);
        $this->db->table('clubora.account_member_links')->where('account_id', $parent->id)->update(['state' => 'revoked']);
        try {
            app(MemberChangeService::class)->review($this->office, $this->tenant, $r->id, 1, true, null);
            $this->fail('Revoked');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        app(MemberChangeService::class)->review($this->office, $this->tenant, $r->id, 1, false, 'Zuordnung nicht mehr gültig');
        $this->db->table('clubora.account_member_links')->where('account_id', $parent->id)->update(['state' => 'approved']);
        $this->member->forceFill(['birth_date' => '1990-01-01'])->save();
        $this->expectException(HttpException::class);
        app(MemberChangeService::class)->represented($parent, $this->tenant, $this->member->id);
    }

    public function test_portal_owner_only_and_withdrawal_and_admin_web_review_form(): void
    {
        $r = $this->submit();
        $this->actingAs($this->user, 'portal')->get(route('clubunio.portal.member.edit', ['tenant' => $this->tenant->slug, 'member' => $this->member->id]))->assertOk()->assertSee('New City');
        $page = Livewire::test(ListRequests::class)->assertSuccessful()->assertCanSeeTableRecords([$r])->mountTableAction('compare', $r)->assertSet('mountedActions.0.name', 'compare');
        $this->assertStringContainsString('Old City', $page->instance()->getMountedAction()->getModalContent()->render());
        $this->assertStringContainsString('New City', $page->instance()->getMountedAction()->getModalContent()->render());
        Livewire::test(ListRequests::class)->callTableAction('reject', $r, ['revision' => 1, 'note' => 'Synthetic note'])->assertHasNoActionErrors();
        $this->assertSame('rejected', $r->fresh()->state);
        $r = $this->submit();
        $this->post(route('clubunio.portal.member.withdraw', ['tenant' => $this->tenant->slug, 'change' => $r->id]), ['revision' => 1])->assertRedirect();
        $this->assertSame('withdrawn', $r->fresh()->state);
        $stranger = $this->account('trainer');
        $other = new Member;
        $other->forceFill(['tenant_id' => $this->tenant->id, 'first_name' => 'Other', 'last_name' => 'Person'])->save();
        $this->link($stranger, $other, 'self');
        $this->actingAs($stranger, 'portal')->get(route('clubunio.portal.member.edit', ['tenant' => $this->tenant->slug, 'member' => $this->member->id]))->assertNotFound();
    }

    private function document(bool $visible = false): MemberDocument
    {
        $type = $this->insert('document_types', ['code' => Str::random(8), 'name' => 'Synthetic document']);
        $this->mock(RegistrationBlobStore::class)->shouldReceive('put')->once()->withArgs(fn ($folder, $name, $bytes, $mime) => $folder === 'clubunio/members/'.$this->tenant->id.'/'.$this->member->id.'/' && str_ends_with($name, '.pdf') && $mime === 'application/pdf');
        config(['clubunio-integrations.azure.tenant_slug' => $this->tenant->slug]);

        return app(MemberDocumentMaintenanceService::class)->add($this->office, $this->tenant, $this->member->id, $type, $visible, UploadedFile::fake()->createWithContent('Ausweis ü.pdf', "%PDF-1.4\nSynthetic"));
    }

    public function test_document_private_upload_metadata_and_revision_and_deactivation(): void
    {
        $d = $this->document();
        $this->assertFalse($d->portal_visible);
        $this->assertSame('Ausweis ü.pdf', $d->original_filename);
        $this->assertSame(64, strlen($d->sha256));
        $this->assertStringNotContainsString('Ausweis', $d->filename);
        $service = app(MemberDocumentMaintenanceService::class);
        $service->update($this->office, $this->tenant, $d->id, $this->member->id, 1, false, true);
        $this->assertFalse($d->fresh()->active);
        $this->assertSame(2, $d->fresh()->revision);
        $this->expectException(ValidationException::class);
        $service->update($this->office, $this->tenant, $d->id, $this->member->id, 1, true, true);
    }

    public function test_portal_documents_require_explicit_visibility_and_current_representation(): void
    {
        $d = $this->document();
        $url = route('clubunio.portal.member.document', ['tenant' => $this->tenant->slug, 'document' => $d->id]);
        $this->actingAs($this->user, 'portal')->get($url)->assertNotFound();
        app(MemberDocumentMaintenanceService::class)->update($this->office, $this->tenant, $d->id, $this->member->id, 1, true, true);
        $this->mock(AzureDocumentReader::class)->shouldReceive('read')->once()->andReturn('%PDF-1.4 Synthetic');
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->db->table('clubora.account_member_links')->where('account_id', $this->user->id)->update(['state' => 'revoked']);
        $this->get($url)->assertNotFound();
    }

    public function test_document_admin_forms_render_and_portal_cannot_write(): void
    {
        $d = $this->document();
        Livewire::test(ListMemberDocuments::class)->assertSuccessful()->assertCanSeeTableRecords([$d])->mountTableAction('manageDocument', $d)->assertHasNoActionErrors();
        Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => $this->member, 'pageClass' => ViewMember::class])->assertSuccessful()->mountTableAction('manageDocument', $d)->assertHasNoActionErrors();
        $this->expectException(HttpException::class);
        app(MemberDocumentMaintenanceService::class)->update($this->user, $this->tenant, $d->id, $this->member->id, 1, false, false);
    }

    public function test_trainer_cannot_review_or_reassign_document_owner_and_failed_upload_saves_nothing(): void
    {
        $r = $this->submit();
        $trainer = $this->account('trainer');
        try {
            app(MemberChangeService::class)->review($trainer, $this->tenant, $r->id, 1, true, null);
            $this->fail('Trainer may not approve');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $d = $this->document();
        try {
            app(MemberDocumentMaintenanceService::class)->update($this->office, $this->tenant, $d->id, 99999999, 1, false, false);
            $this->fail('Owner reassignment');
        } catch (ModelNotFoundException) {
            $this->assertTrue($d->fresh()->active);
        }
        $this->app->forgetInstance(RegistrationBlobStore::class);
        $this->mock(RegistrationBlobStore::class)->shouldReceive('put')->once()->andThrow(new \RuntimeException('sensitive-provider-url'));
        $before = MemberDocument::where('tenant_id', $this->tenant->id)->count();
        try {
            app(MemberDocumentMaintenanceService::class)->add($this->office, $this->tenant, $this->member->id, $d->document_type_id, false, UploadedFile::fake()->createWithContent('next.pdf', "%PDF-1.4\nAnother fixture"));
            $this->fail('Provider failure');
        } catch (ValidationException $e) {
            $this->assertStringNotContainsString('sensitive-provider-url', $e->getMessage());
            $this->assertSame($before, MemberDocument::where('tenant_id', $this->tenant->id)->count());
        }
    }
}
