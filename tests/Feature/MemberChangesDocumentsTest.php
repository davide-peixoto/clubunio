<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\MemberChangeRequest;
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
use AzureOss\Storage\Blob\Sas\BlobSasBuilder;
use AzureOss\Storage\Common\Auth\StorageSharedKeyCredential;
use AzureOss\Storage\Common\Sas\SasProtocol;
use Dompdf\Dompdf;
use Filament\Facades\Filament;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
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
        $a->forceFill(['username' => strtolower(Str::random(20)), 'display_name' => 'Synthetic '.$role, 'password' => bcrypt('fixture'), 'active' => true])->save();
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

    public function test_player_web_login_submit_approve_and_notification_are_owner_scoped(): void
    {
        Auth::guard('portal')->logout();
        $this->post(route('clubunio.portal.login'), ['username' => $this->user->username, 'password' => 'fixture'])->assertRedirect()->assertSessionHasNoErrors();
        $edit = route('clubunio.portal.member.edit', [$this->tenant->slug, $this->member->id]);
        $this->get($edit)->assertOk()->assertSee('Old City')->assertSee('Änderungsantrag');
        $this->post(route('clubunio.portal.member.submit', [$this->tenant->slug, $this->member->id]),
            ['city' => 'Player request city', '_version' => MemberService::version($this->member->fresh())])->assertRedirect($edit)->assertSessionHasNoErrors();
        $r = MemberChangeRequest::where('tenant_id', $this->tenant->id)->sole();
        $this->assertSame('Old City', $this->member->fresh()->city);
        $this->assertSame($this->user->id, $r->requested_by);
        $this->get($edit)->assertSee('Player request city');
        app(MemberChangeService::class)->review($this->office, $this->tenant, $r->id, 1, true, 'Synthetic approval');
        $this->get($edit)->assertOk()->assertSee('Synthetic approval');
        $this->assertSame('Player request city', $this->member->fresh()->city);
        $this->get(route('clubunio.portal.notifications', $this->tenant->slug))->assertOk()->assertSee('Antrag öffnen');
        $this->assertSame(1, $this->db->table('clubora.notifications')->where('tenant_id', $this->tenant->id)->where('account_id', $this->user->id)->count());
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_parent_web_login_child_request_and_privacy_and_age_and_exit_boundaries(): void
    {
        $parent = $this->account('member');
        $secondParent = $this->account('member');
        $this->link($parent, $this->member, 'parent');
        $this->link($secondParent, $this->member, 'parent');
        $otherChild = new Member;
        $otherChild->forceFill(['tenant_id' => $this->tenant->id, 'first_name' => 'Synthetic', 'last_name' => 'Sibling', 'birth_date' => now()->subYears(8)->toDateString()])->save();
        $this->link($parent, $otherChild, 'parent');
        Auth::guard('portal')->logout();
        $this->post(route('clubunio.portal.login'), ['username' => $parent->username, 'password' => 'fixture'])->assertRedirect()->assertSessionHasNoErrors();
        $edit = route('clubunio.portal.member.edit', [$this->tenant->slug, $this->member->id]);
        $this->get($edit)->assertOk();
        $this->post(route('clubunio.portal.member.submit', [$this->tenant->slug, $this->member->id]),
            ['city' => 'Parent request city', '_version' => MemberService::version($this->member->fresh())])->assertRedirect($edit)->assertSessionHasNoErrors();
        $r = MemberChangeRequest::where('tenant_id', $this->tenant->id)->sole();
        $this->actingAs($secondParent, 'portal')->get($edit)->assertOk()->assertDontSee('Parent request city');
        $this->post(route('clubunio.portal.member.withdraw', [$this->tenant->slug, $r->id]), ['revision' => 1])->assertNotFound();
        app(MemberChangeService::class)->review($this->office, $this->tenant, $r->id, 1, true, 'Parent-only decision note');
        $this->get($edit)->assertDontSee('Parent-only decision note');
        $this->actingAs($parent, 'portal')->get($edit)->assertSee('Parent-only decision note');
        $this->member->forceFill(['birth_date' => now()->subYears(18)->toDateString()])->save();
        $this->get($edit)->assertNotFound();
        $this->get(route('clubunio.portal.member.edit', [$this->tenant->slug, $otherChild->id]))->assertOk();
        $this->member->forceFill(['birth_date' => now()->subYears(12)->toDateString(), 'exit_date' => now()->toDateString()])->save();
        $this->get($edit)->assertNotFound();
        $this->db->table('clubora.account_member_links')->where('account_id', $parent->id)->where('member_id', $otherChild->id)->update(['state' => 'revoked']);
        $this->get(route('clubunio.portal.member.edit', [$this->tenant->slug, $otherChild->id]))->assertForbidden();
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_live_azure_private_upload_roundtrip_and_player_parent_http_access(): void
    {
        if (getenv('CLUBUNIO_TEST_AZURE') !== '1') {
            $this->markTestSkipped('Explicit real private Azure opt-in required');
        }
        $config = config('clubunio-integrations.azure');
        $this->assertTrue((bool) $config['enabled']);
        $this->assertSame('svs-prod-private', $config['container']);
        $this->assertFalse((bool) config('clubunio.outbound_enabled'));
        config(['clubunio-integrations.azure.tenant_slug' => $this->tenant->slug]);
        $parent = $this->account('member');
        $stranger = $this->account('member');
        $this->link($parent, $this->member, 'parent');
        $other = new Member;
        $other->forceFill(['tenant_id' => $this->tenant->id, 'first_name' => 'Synthetic', 'last_name' => 'Stranger', 'birth_date' => '1990-01-01'])->save();
        $this->link($stranger, $other, 'self');
        $type = $this->insert('document_types', ['code' => 'azure-live-fixture', 'name' => 'Synthetic Azure test']);
        $pdf = new Dompdf;
        $pdf->loadHtml('<html><body><h1>ClubUnio storage test</h1><p>Synthetic fixture. No personal information.</p></body></html>');
        $pdf->render();
        $bytes = $pdf->output();
        $sha = hash('sha256', $bytes);
        $store = new class extends RegistrationBlobStore
        {
            public ?array $created = null;

            public function put(string $folder, string $filename, string $bytes, string $mime): void
            {
                $this->created = [$folder, $filename];
                parent::put($folder, $filename, $bytes, $mime);
            }
        };
        $this->app->instance(RegistrationBlobStore::class, $store);
        try {
            $d = app(MemberDocumentMaintenanceService::class)->add($this->office, $this->tenant, $this->member->id, $type, false,
                UploadedFile::fake()->createWithContent('ClubUnio Azure Test.pdf', $bytes));
            $this->assertSame($sha, $d->sha256);
            $this->assertSame(strlen($bytes), $d->file_size);
            $this->assertSame($sha, hash('sha256', app(AzureDocumentReader::class)->read($d->storage_path, $d->filename)));
            $this->assertFalse(is_file(public_path($d->storage_path.$d->filename)));
            $unsigned = 'https://'.$config['account'].'.blob.core.windows.net/'.$config['container'].'/'.$d->storage_path.$d->filename;
            $anonymous = (new Client)->request('GET', $unsigned, ['http_errors' => false, 'allow_redirects' => false, 'timeout' => 20]);
            $this->assertContains($anonymous->getStatusCode(), [401, 403, 404]);
            $url = route('clubunio.portal.member.document', [$this->tenant->slug, $d->id]);
            $this->actingAs($this->user, 'portal')->get($url)->assertNotFound();
            $this->actingAs($parent, 'portal')->get($url)->assertNotFound();
            $admin = $this->actingAs($this->office, 'clubunio')->get(route('clubunio.documents.open', [$this->tenant->slug, $d->id]))->assertOk();
            $this->assertSame($sha, hash('sha256', $admin->getContent()));
            app(MemberDocumentMaintenanceService::class)->update($this->office, $this->tenant, $d->id, $this->member->id, 1, true, true);
            foreach ([$this->user, $parent] as $actor) {
                $response = $this->actingAs($actor, 'portal')->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
                $this->assertSame($sha, hash('sha256', $response->getContent()));
                $this->get(route('clubunio.portal.member.edit', [$this->tenant->slug, $this->member->id]))->assertOk()->assertSee('ClubUnio Azure Test.pdf');
            }
            $this->actingAs($stranger, 'portal')->get($url)->assertNotFound();
            $this->db->table('clubora.account_member_links')->where('account_id', $parent->id)->update(['state' => 'revoked']);
            $this->actingAs($parent, 'portal')->get($url)->assertNotFound();
            app(MemberDocumentMaintenanceService::class)->update($this->office, $this->tenant, $d->id, $this->member->id, 2, false, true);
            $this->actingAs($this->user, 'portal')->get($url)->assertNotFound();
            $this->actingAs($this->office, 'clubunio')->get(route('clubunio.documents.open', [$this->tenant->slug, $d->id]))->assertNotFound();
            Mail::assertNothingSent();
            Http::assertNothingSent();
        } finally {
            if ($store->created) {
                $this->removeLiveFixture($store->created, $sha);
            }
        }
    }

    private function removeLiveFixture(array $created, string $sha): void
    {
        [$folder, $name] = $created;
        $this->assertSame('clubunio/members/'.$this->tenant->id.'/'.$this->member->id.'/', $folder);
        $this->assertMatchesRegularExpression('/^[a-f0-9-]{36}\.pdf$/D', $name);
        $config = config('clubunio-integrations.azure');
        $path = $folder.$name;
        $sas = BlobSasBuilder::new()->setContainerName($config['container'])->setBlobName($path)
            ->setPermissions('rd')->setProtocol(SasProtocol::HTTPS)->setExpiresOn(now()->addMinutes(2))
            ->build(new StorageSharedKeyCredential($config['account'], $config['key']));
        $url = 'https://'.$config['account'].'.blob.core.windows.net/'.$config['container'].'/'.$path.'?'.$sas;
        try {
            $client = new Client(['http_errors' => false, 'allow_redirects' => false, 'timeout' => 20]);
            $response = $client->get($url);
            if ($response->getStatusCode() === 404) {
                return;
            }
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame($sha, hash('sha256', (string) $response->getBody()));
            $etag = $response->getHeaderLine('ETag');
            $this->assertNotSame('', $etag);
            $deleted = $client->delete($url, ['headers' => ['If-Match' => $etag]]);
            $this->assertSame(202, $deleted->getStatusCode());
            $this->assertSame(404, $client->head($url)->getStatusCode());
        } catch (GuzzleException) {
            throw new \RuntimeException('Azure synthetic fixture cleanup unavailable; signed URL suppressed');
        }
    }
}
