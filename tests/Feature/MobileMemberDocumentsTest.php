<?php

namespace Tests\Feature;

use App\ClubUnio\Mobile\{MalwareScanner, MobileMemberDocumentPdf};
use App\ClubUnio\Models\{Account, Tenant};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Crypt, DB, Http};
use Tests\TestCase;

final class DocumentFixtureScanner implements MalwareScanner
{
    public array $verdict = ['clean' => true, 'version' => 'synthetic-document-scanner'];
    public function scan(string $bytes): array { return $this->verdict; }
}

class MobileMemberDocumentsTest extends TestCase
{
    private $db;
    private Tenant $tenant;
    private Account $admin;
    private Account $parent;
    private Account $self;
    private Account $trainer;
    private int $child;
    private int $otherMember;
    private int $type;
    private string $original;
    private DocumentFixtureScanner $scanner;
    private string $archiveBytes;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') { $this->markTestSkipped('Opt-in rolled-back PostgreSQL fixture required'); }
        config(['clubunio-mobile.enabled' => true, 'clubunio-mobile.native_documents_enabled' => true, 'clubunio-mobile.uploads_enabled' => true,
            'cache.default' => 'array', 'hashing.bcrypt.rounds' => 4]);
        Http::preventStrayRequests();
        $this->db = DB::connection('clubunio'); $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction(); $this->db->statement("SET LOCAL application_name TO 'clubunio-mobile-documents-test'");
        $this->db->statement('SET LOCAL search_path TO clubunio');
        $this->tenant = Tenant::create(['slug' => 'mobile-docs-'.bin2hex(random_bytes(6)), 'name' => 'Synthetic document club']);
        $this->admin = $this->account('admin'); $this->parent = $this->account('member'); $this->self = $this->account('member'); $this->trainer = $this->account('trainer');
        $this->child = $this->db->table('members')->insertGetId(['tenant_id' => $this->tenant->id, 'first_name' => 'Synthetic', 'last_name' => 'Child', 'birth_date' => '2013-01-01']);
        $this->otherMember = $this->db->table('members')->insertGetId(['tenant_id' => $this->tenant->id, 'first_name' => 'Synthetic', 'last_name' => 'Coach']);
        foreach ([[$this->parent, $this->child, 'parent'], [$this->self, $this->child, 'self'], [$this->trainer, $this->otherMember, 'self']] as [$actor, $member, $kind]) {
            $this->db->table('account_member_links')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $actor->id, 'member_id' => $member, 'state' => 'approved', 'kind' => $kind, 'source' => 'test']);
        }
        $this->type = $this->db->table('document_types')->insertGetId(['tenant_id' => $this->tenant->id, 'code' => 'MATERIALQUITTUNG', 'name' => 'Synthetic receipt']);
        $this->scanner = new DocumentFixtureScanner; $this->app->instance(MalwareScanner::class, $this->scanner);
        $pdf = new \FPDF; $pdf->AddPage(); $pdf->SetFont('Helvetica', '', 14); $pdf->Cell(0, 10, 'Synthetic private document - no real person');
        $this->original = $pdf->Output('S');
        $this->temporarySchema();
    }

    protected function tearDown(): void { if (isset($this->db)) { $this->db->rollBack(); } parent::tearDown(); }
    private function account(string $role): Account
    {
        $actor = new Account; $actor->forceFill(['username' => 'mobile-doc-'.bin2hex(random_bytes(8)), 'display_name' => 'Synthetic '.$role,
            'password' => bcrypt('fixture'), 'active' => true, 'api_only' => false])->save();
        $this->db->table('tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $actor->id, 'role' => $role, 'portal_enabled' => true]);
        return $actor;
    }
    private function temporarySchema(): void
    {
        $this->db->statement('CREATE TEMP TABLE receipt_test_tenants(id bigint PRIMARY KEY) ON COMMIT DROP');
        $this->db->table('pg_temp.receipt_test_tenants')->insert(['id' => $this->tenant->id]);
        foreach (['members', 'document_types', 'member_documents', 'legacy_material_receipts'] as $table) {
            $this->db->statement('CREATE TEMP TABLE receipt_test_'.$table.'(tenant_id bigint NOT NULL,id bigint NOT NULL,UNIQUE(tenant_id,id)) ON COMMIT DROP');
            $this->db->statement('INSERT INTO pg_temp.receipt_test_'.$table.' SELECT tenant_id,id FROM clubunio.'.$table.' WHERE tenant_id=?', [$this->tenant->id]);
        }
        $this->db->statement('CREATE TEMP TABLE receipt_test_tenant_accounts(tenant_id bigint NOT NULL,account_id bigint NOT NULL,UNIQUE(tenant_id,account_id)) ON COMMIT DROP');
        $this->db->statement('INSERT INTO pg_temp.receipt_test_tenant_accounts SELECT tenant_id,account_id FROM clubunio.tenant_accounts WHERE tenant_id=?', [$this->tenant->id]);
        $ddl = $this->db->pretend(fn () => (require database_path('clubunio/2026_11_03_000089_mobile_member_receipts.php'))->up());
        $this->assertCount(1, $ddl);
        $sql = str_replace('CREATE TABLE clubunio.mobile_member_receipts', 'CREATE TEMP TABLE mobile_member_receipts', $ddl[0]['query']);
        $sql = str_replace(['ON clubunio.mobile_member_receipts', ");\nCREATE INDEX"], ['ON pg_temp.mobile_member_receipts', ") ON COMMIT DROP;\nCREATE INDEX"], $sql);
        $sql = str_replace('ON clubunio.legacy_material_receipts(', 'ON pg_temp.receipt_test_legacy_material_receipts(', $sql);
        foreach (['tenants', 'members', 'document_types', 'member_documents', 'legacy_material_receipts', 'tenant_accounts'] as $table) { $sql = str_replace('REFERENCES clubunio.'.$table.'(', 'REFERENCES pg_temp.receipt_test_'.$table.'(', $sql); }
        $this->assertStringNotContainsString('ON clubunio.', $sql);
        $this->assertStringNotContainsString('REFERENCES clubunio.', $sql);
        $this->db->unprepared($sql);
        $this->db->setQueryGrammar(new class($this->db) extends \Illuminate\Database\Query\Grammars\PostgresGrammar {
            public function wrapTable($table, $prefix = null) { return parent::wrapTable($table === 'clubunio.mobile_member_receipts' ? 'pg_temp.mobile_member_receipts' : $table, $prefix); }
        });
    }
    private function token(Account $actor): string { return $this->postJson('/api/mobile/v1/session', ['username' => $actor->username, 'password' => 'fixture', 'device_name' => 'Synthetic document'])->assertOk()->json('data.token'); }
    private function base(): string { return '/api/mobile/v1/clubs/'.$this->tenant->id.'/member-documents'; }
    private function upload(array $changes = [], ?string $bytes = null) {
        return $this->post($this->base(), array_replace(['MitgliederID' => $this->child, 'Titel' => 'Synthetic material receipt',
            'DokumentTypID' => $this->type, 'UnterschriftNotwendig' => '1', 'RequestKey' => 'synthetic-document-request-001',
            'file' => UploadedFile::fake()->createWithContent('original.pdf', $bytes ?? $this->original)], $changes), ['Accept' => 'application/json']);
    }
    private function signature(array $changes = []): array { return array_replace(['Revision' => 1, 'OriginalSha256' => hash('sha256', $this->original), 'Accepted' => true,
        'Strokes' => [[[.1, .4], [.2, .2], [.3, .8], [.9, .4]]]], $changes); }
    private function pendingArchive(): object
    {
        $this->archiveBytes = $this->original;
        $this->mock(\App\ClubUnio\Services\AzureDocumentReader::class)->shouldReceive('read')->andReturnUsing(fn () => $this->archiveBytes);
        $document = $this->db->table('member_documents')->insertGetId(['tenant_id' => $this->tenant->id, 'member_id' => $this->child, 'document_type_id' => $this->type,
            'filename' => 'pending.pdf', 'original_filename' => 'pending.pdf', 'storage_path' => 'var/member-documents/', 'sha256' => hash('sha256', $this->original), 'portal_visible' => false]);
        $id = $this->db->table('legacy_material_receipts')->insertGetId(['tenant_id' => $this->tenant->id, 'member_id' => $this->child,
            'source_id' => 41, 'source_hash' => hash('sha256', 'synthetic open archived assignment'), 'title' => 'Synthetic open archive', 'source_state' => 'OFFEN',
            'original_document_id' => $document, 'original_sha256' => hash('sha256', $this->original), 'imported_by' => $this->admin->id]);
        $this->db->table('pg_temp.receipt_test_member_documents')->insert(['tenant_id' => $this->tenant->id, 'id' => $document]);
        $this->db->table('pg_temp.receipt_test_legacy_material_receipts')->insert(['tenant_id' => $this->tenant->id, 'id' => $id]);
        return $this->db->table('legacy_material_receipts')->where('id', $id)->first();
    }

    public function test_original_upload_and_signature_are_versioned_idempotent_and_keep_private_evidence(): void
    {
        $admin = $this->token($this->admin); $this->withToken($admin);
        $created = $this->upload()->assertOk()->assertJsonPath('data.Dokument.Status', 'OFFEN')->assertJsonPath('data.Dokument.CanSign', false);
        $id = $created->json('data.Dokument.QuittungID');
        $this->upload()->assertOk()->assertJsonPath('data.Dokument.QuittungID', $id);
        $this->upload(['Titel' => 'Different title'])->assertConflict();
        $this->assertSame(1, $this->db->table('clubunio.mobile_member_receipts')->count());
        $row = $this->db->table('clubunio.mobile_member_receipts')->where('id', $id)->first();
        $this->assertSame($this->original, Crypt::decryptString($row->original_pdf_encrypted));
        $this->assertNotSame($this->original, $row->original_pdf_encrypted);
        $this->withToken($this->token($this->parent))->getJson($this->base().'/'.$id)->assertOk()->assertJsonPath('data.Dokument.CanSign', true)->assertJsonMissingPath('data.Dokument.original_pdf_encrypted');
        $url = $this->base().'/'.$id.'/sign';
        $this->postJson($url, $this->signature(['OriginalSha256' => str_repeat('0', 64)]))->assertConflict();
        $this->postJson($url, $this->signature(['Strokes' => [[[.1, .1], [.11, .1]]]]))->assertStatus(422);
        $this->postJson($url, $this->signature(['Accepted' => false]))->assertStatus(422);
        $this->postJson($url, $this->signature())->assertOk()->assertJsonPath('data.Dokument.Status', 'UNTERSCHRIEBEN')->assertJsonPath('data.Dokument.Revision', 2)->assertJsonPath('data.Dokument.CanSign', false);
        $this->postJson($url, $this->signature())->assertOk()->assertJsonPath('data.Dokument.Revision', 2);
        $this->postJson($url, $this->signature(['Strokes' => [[[0., 0.], [1., 1.]]]]))->assertConflict();
        $row = $this->db->table('clubunio.mobile_member_receipts')->where('id', $id)->first();
        $this->assertSame('parent', $row->signed_kind); $this->assertSame($this->parent->id, $row->signed_by);
        $this->assertSame($this->original, Crypt::decryptString($row->original_pdf_encrypted));
        $this->assertSame(2, $row->signed_page_count); $this->assertNotNull($row->strokes_encrypted);
        $original = $this->get($this->base().'/'.$id.'/pdf?original=1')->assertOk()->assertHeader('X-Content-SHA256', hash('sha256', $this->original));
        $this->assertSame($this->original, $original->getContent());
        $signed = $this->get($this->base().'/'.$id.'/pdf')->assertOk()->assertHeader('X-Content-SHA256', $row->signed_sha256);
        $this->assertStringContainsString('no-store', $signed->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $signed->headers->get('Cache-Control'));
        $this->assertSame($row->signed_sha256, hash('sha256', $signed->getContent()));
        $this->assertSame(strlen($signed->getContent()), (int) $row->signed_byte_count);
        $this->withToken($this->token($this->self))->postJson($url, $this->signature())->assertConflict();
        $this->assertSame(1, $this->db->table('object_changes')->where('tenant_id', $this->tenant->id)->where('object_type', 'mobile_member_receipts')->whereRaw("after_value::jsonb->>'operation' = 'signed'")->count());
        $this->assertStringNotContainsString('Strokes', $this->db->table('object_changes')->where('tenant_id', $this->tenant->id)->get()->toJson());
    }

    public function test_personal_document_permissions_revocations_readiness_and_malware_fail_closed(): void
    {
        $admin = $this->token($this->admin); $this->withToken($admin);
        $id = $this->upload()->assertOk()->json('data.Dokument.QuittungID');
        $memberUrl = '/api/mobile/v1/clubs/'.$this->tenant->id.'/members/'.$this->child;
        $this->getJson($memberUrl)->assertOk()->assertJsonPath('data.Member.CanReadDocuments', false);
        $this->withHeaders(['X-ClubUnio-Mobile-Features' => 'member-documents-v1'])->getJson($memberUrl)->assertOk()->assertJsonPath('data.Member.CanReadDocuments', true);
        $this->withToken($this->token($this->trainer))->getJson($this->base().'/'.$id)->assertNotFound();
        $this->upload()->assertForbidden(); $this->getJson($this->base().'/types')->assertForbidden();
        $this->withToken($this->token($this->parent))->getJson($this->base().'?mine=0')->assertOk()->assertJsonPath('data.totalRecordCount', 1)->assertJsonPath('data.CanUpload', false);
        $this->db->table('account_member_links')->where('account_id', $this->parent->id)->update(['state' => 'pending']);
        $this->postJson($this->base().'/'.$id.'/sign', $this->signature())->assertForbidden();
        $this->withToken($admin); config(['clubunio-mobile.native_documents_enabled' => false]);
        $this->getJson($this->base())->assertStatus(503); $this->upload()->assertStatus(503);
        $this->getJson($memberUrl)->assertOk()->assertJsonPath('data.Member.CanReadDocuments', false);
        config(['clubunio-mobile.native_documents_enabled' => true]);
        $this->scanner->verdict = ['clean' => false, 'version' => 'synthetic-reject'];
        $this->upload(['RequestKey' => 'synthetic-rejected-document'])->assertStatus(422);
        $this->scanner->verdict = ['clean' => true];
        $this->upload(['RequestKey' => 'synthetic-uncertain-document'])->assertStatus(503);
        $this->assertSame(1, $this->db->table('clubunio.mobile_member_receipts')->count());
        $this->scanner->verdict = ['clean' => true, 'version' => 'synthetic'];
        $this->upload(['RequestKey' => 'synthetic-invalid-pdf-file'], '%PDF-invalid %%EOF')->assertStatus(422);
        $this->db->table('clubunio.mobile_member_receipts')->where('id', $id)->update(['original_pdf_encrypted' => Crypt::encryptString('tampered')]);
        $this->getJson($this->base().'/'.$id.'/pdf')->assertConflict();
        $this->withToken($this->token($this->self))->postJson($this->base().'/'.$id.'/sign', $this->signature())->assertConflict();
    }

    public function test_signed_state_database_constraints_refuse_incomplete_evidence(): void
    {
        $this->withToken($this->token($this->admin)); $id = $this->upload()->assertOk()->json('data.Dokument.QuittungID');
        try {
            $this->db->transaction(fn () => $this->db->table('clubunio.mobile_member_receipts')->where('id', $id)->update(['state' => 'signed']));
            $this->fail('Incomplete signature accepted by PostgreSQL');
        } catch (\Illuminate\Database\QueryException $error) { $this->assertSame('23514', $error->getCode()); }
        $this->assertSame('pending', $this->db->table('clubunio.mobile_member_receipts')->where('id', $id)->value('state'));
    }

    public function test_staff_sign_only_for_their_own_membership_without_an_administration_shortcut(): void
    {
        $this->withToken($this->token($this->admin));
        $id = $this->upload(['MitgliederID' => $this->otherMember])->assertOk()->json('data.Dokument.QuittungID');
        $this->postJson($this->base().'/'.$id.'/sign', $this->signature())->assertNotFound();
        $this->withToken($this->token($this->parent))->getJson($this->base().'/'.$id)->assertNotFound();
        $this->withToken($this->token($this->trainer))->getJson($this->base().'/'.$id)->assertOk()->assertJsonPath('data.Dokument.CanSign', true);
        $this->postJson($this->base().'/'.$id.'/sign', $this->signature())->assertOk()->assertJsonPath('data.Dokument.Status', 'UNTERSCHRIEBEN');
        $this->assertSame('self', $this->db->table('clubunio.mobile_member_receipts')->where('id', $id)->value('signed_kind'));
    }

    public function test_existing_files_and_historical_receipts_keep_typed_sources_and_exact_authorization(): void
    {
        config(['clubunio-integrations.azure.tenant_slug' => $this->tenant->slug]);
        $signed = app(MobileMemberDocumentPdf::class)->sign($this->original, $this->signature()['Strokes'], ['club' => $this->tenant->name,
            'title' => 'Synthetic historical receipt', 'member' => 'Synthetic Child', 'signer' => 'Synthetic parent', 'username' => 'synthetic@example.invalid',
            'signed_at' => '2026-11-03 12:00:00', 'receipt' => 'legacy-42', 'kind' => 'parent', 'original_sha256' => hash('sha256', $this->original)])['bytes'];
        $storage = ['original.pdf' => $this->original, 'signed.pdf' => $signed, 'public.pdf' => $this->original, 'private.pdf' => $this->original];
        $reader = $this->mock(\App\ClubUnio\Services\AzureDocumentReader::class);
        $reader->shouldReceive('read')->andReturnUsing(function ($folder, $filename) use (&$storage) { return $storage[$filename]; });
        $document = function ($filename, $portal) use (&$storage) {
            return $this->db->table('member_documents')->insertGetId(['tenant_id' => $this->tenant->id, 'member_id' => $this->child, 'document_type_id' => $this->type,
                'filename' => $filename, 'original_filename' => $filename, 'storage_path' => 'var/member-documents/', 'sha256' => hash('sha256', $storage[$filename]), 'portal_visible' => $portal]);
        };
        $originalId = $document('original.pdf', false); $signedId = $document('signed.pdf', false);
        $publicId = $document('public.pdf', true); $privateId = $document('private.pdf', false);
        $archive = $this->db->table('legacy_material_receipts')->insertGetId(['tenant_id' => $this->tenant->id, 'member_id' => $this->child,
            'source_id' => 42, 'source_hash' => hash('sha256', 'synthetic historic source'), 'title' => 'Synthetic historical receipt', 'source_state' => 'UNTERSCHRIEBEN',
            'signed_name' => 'Synthetic parent', 'signed_at' => now(), 'original_document_id' => $originalId, 'signed_document_id' => $signedId,
            'original_sha256' => hash('sha256', $this->original), 'signed_sha256' => hash('sha256', $signed), 'imported_by' => $this->admin->id]);
        $this->withToken($this->token($this->parent));
        $rows = $this->getJson($this->base().'?mine=0')->assertOk()->assertJsonPath('data.totalRecordCount', 2)->json('data.Dokumente');
        $this->assertEqualsCanonicalizing(['archive', 'file'], array_column($rows, 'SourceKind'));
        $this->getJson($this->base().'/archive/'.$archive)->assertOk()->assertJsonPath('data.Dokument.CanSign', false)->assertJsonPath('data.Dokument.Status', 'UNTERSCHRIEBEN');
        $this->get($this->base().'/archive/'.$archive.'/pdf')->assertOk()->assertHeader('X-Content-SHA256', hash('sha256', $signed));
        $this->get($this->base().'/archive/'.$archive.'/pdf?original=1')->assertOk()->assertHeader('X-Content-SHA256', hash('sha256', $this->original));
        $this->get($this->base().'/files/'.$publicId.'/pdf')->assertOk()->assertHeader('X-Content-SHA256', hash('sha256', $this->original));
        $this->getJson($this->base().'/files/'.$privateId)->assertNotFound();
        $this->withToken($this->token($this->trainer))->getJson($this->base().'/archive/'.$archive)->assertNotFound();
        $this->getJson($this->base().'?mine=0')->assertOk()->assertJsonPath('data.totalRecordCount', 0);
        $this->withToken($this->token($this->admin))->getJson($this->base().'?mine=0')->assertOk()->assertJsonPath('data.totalRecordCount', 3);
        $storage['signed.pdf'] = '%PDF-tampered';
        $this->getJson($this->base().'/archive/'.$archive.'/pdf')->assertConflict();
        $this->assertSame('UNTERSCHRIEBEN', $this->db->table('legacy_material_receipts')->where('id', $archive)->value('source_state'));
    }

    public function test_unsigned_documents_limits_and_composite_foreign_keys_are_not_bypassed(): void
    {
        $this->withToken($this->token($this->admin));
        $id = $this->upload(['UnterschriftNotwendig' => '0'])->assertOk()->assertJsonPath('data.Dokument.Status', 'ABGELEGT')->json('data.Dokument.QuittungID');
        $this->getJson($this->base().'/types')->assertOk()->assertJsonPath('data.DokumentTypen.0.DokumentTypID', $this->type);
        $this->getJson($this->base().'/members?q=Synthetic')->assertOk()->assertJsonCount(2, 'data.Mitglieder')->assertJsonMissingPath('data.Mitglieder.0.Geburtsdatum');
        $this->getJson($this->base().'/members?q=x')->assertUnprocessable();
        $this->upload(['RequestKey' => 'synthetic-too-large-document'], str_repeat('x', MobileMemberDocumentPdf::MAX_ORIGINAL + 1))->assertUnprocessable();
        $this->upload(['RequestKey' => 'synthetic-invalid-document-title', 'Titel' => "Injected\nTitle"])->assertUnprocessable();
        $this->withToken($this->token($this->parent))->postJson($this->base().'/'.$id.'/sign', $this->signature())->assertConflict();
        $this->getJson($this->base().'?Status=OFFEN')->assertOk()->assertJsonPath('data.totalRecordCount', 0);
        $other = Tenant::create(['slug' => 'docs-foreign-'.bin2hex(random_bytes(5)), 'name' => 'Foreign fixture']);
        $this->getJson(str_replace('/clubs/'.$this->tenant->id.'/', '/clubs/'.$other->id.'/', $this->base()).'/'.$id)->assertForbidden();
        $foreignType = $this->db->table('document_types')->insertGetId(['tenant_id' => $other->id, 'code' => 'FOREIGN', 'name' => 'Foreign']);
        $this->db->table('pg_temp.receipt_test_tenants')->insert(['id' => $other->id]);
        $this->db->table('pg_temp.receipt_test_document_types')->insert(['tenant_id' => $other->id, 'id' => $foreignType]);
        $row = (array) $this->db->table('clubunio.mobile_member_receipts')->where('id', $id)->first(); unset($row['id']);
        $row['document_type_id'] = $foreignType; $row['request_key'] = 'synthetic-cross-tenant-fk';
        try { $this->db->transaction(fn () => $this->db->table('clubunio.mobile_member_receipts')->insert($row)); $this->fail('Cross-tenant document type accepted'); }
        catch (\Illuminate\Database\QueryException $error) { $this->assertSame('23503', $error->getCode()); }
        $this->assertSame(1, $this->db->table('clubunio.mobile_member_receipts')->count());
    }

    public function test_open_archived_assignment_continues_with_one_new_proof_without_mutating_history(): void
    {
        $archive = $this->pendingArchive(); $before = json_encode($archive, JSON_THROW_ON_ERROR);
        $this->withToken($this->token($this->parent)); $url = $this->base().'/archive/'.$archive->id;
        $this->getJson($url)->assertOk()->assertJsonPath('data.Dokument.CanSign', true)->assertJsonPath('data.Dokument.SourceRevision', $archive->source_hash);
        $this->getJson($this->base().'?Status=OFFEN')->assertOk()->assertJsonPath('data.totalRecordCount', 1);
        $this->assertSame(0, $this->db->table('clubunio.mobile_member_receipts')->count()); // Reads never create a forwarding proof.
        $signature = $this->signature(['SourceRevision' => $archive->source_hash]);
        $this->postJson($url.'/sign', array_replace($signature, ['SourceRevision' => str_repeat('0', 64)]))->assertConflict();
        $this->assertSame(0, $this->db->table('clubunio.mobile_member_receipts')->count());
        $this->postJson($url.'/sign', $signature)->assertOk()->assertJsonPath('data.Dokument.SourceKind', 'archive')
            ->assertJsonPath('data.Dokument.QuittungID', $archive->id)->assertJsonPath('data.Dokument.Revision', 2)->assertJsonPath('data.Dokument.Status', 'UNTERSCHRIEBEN');
        $this->postJson($url.'/sign', $signature)->assertOk()->assertJsonPath('data.Dokument.Revision', 2);
        $this->postJson($url.'/sign', array_replace($signature, ['Strokes' => [[[0., 0.], [1., 1.]]]]))->assertConflict();
        $this->withToken($this->token($this->self))->postJson($url.'/sign', $signature)->assertConflict();
        $this->assertSame(1, $this->db->table('clubunio.mobile_member_receipts')->count());
        $proof = $this->db->table('clubunio.mobile_member_receipts')->first();
        $this->assertSame((int) $archive->id, $proof->archive_receipt_id); $this->assertSame($archive->source_hash, $proof->archive_source_hash);
        $this->assertSame($this->original, Crypt::decryptString($proof->original_pdf_encrypted));
        $this->assertSame($before, json_encode($this->db->table('legacy_material_receipts')->where('id', $archive->id)->first(), JSON_THROW_ON_ERROR));
        $this->get($url.'/pdf')->assertOk()->assertHeader('X-Content-SHA256', $proof->signed_sha256);
        $this->get($url.'/pdf?original=1')->assertOk()->assertHeader('X-Content-SHA256', $archive->original_sha256);
        $this->getJson($this->base().'?Status=OFFEN')->assertOk()->assertJsonPath('data.totalRecordCount', 0);
        $this->getJson($this->base().'?Status=UNTERSCHRIEBEN')->assertOk()->assertJsonPath('data.totalRecordCount', 1); // No duplicate archive + forwarding row.
        $duplicate = (array) $proof; unset($duplicate['id']); $duplicate['request_key'] = 'synthetic-duplicate-proof';
        try { $this->db->transaction(fn () => $this->db->table('clubunio.mobile_member_receipts')->insert($duplicate)); $this->fail('Duplicate archive proof accepted'); }
        catch (\Illuminate\Database\QueryException $error) { $this->assertSame('23505', $error->getCode()); }
        // Same numerical archive ID in a different tenant cannot satisfy the source FK.
        $foreignTenant = Tenant::create(['slug' => 'docs-archive-fk-'.bin2hex(random_bytes(5)), 'name' => 'Foreign archive fixture']);
        $foreignArchive = $archive->id + 100000000;
        $this->db->table('pg_temp.receipt_test_legacy_material_receipts')->insert(['tenant_id' => $foreignTenant->id, 'id' => $foreignArchive]);
        $crossTenant = (array) $proof; unset($crossTenant['id']);
        $crossTenant['archive_receipt_id'] = $foreignArchive; $crossTenant['request_key'] = 'synthetic-foreign-archive-proof';
        try { $this->db->transaction(fn () => $this->db->table('clubunio.mobile_member_receipts')->insert($crossTenant)); $this->fail('Cross-tenant archive source accepted'); }
        catch (\Illuminate\Database\QueryException $error) { $this->assertSame('23503', $error->getCode()); }
        $this->db->table('legacy_material_receipts')->where('id', $archive->id)->update(['source_hash' => str_repeat('1', 64)]);
        $this->getJson($url)->assertConflict();
        $this->postJson($url.'/sign', $signature)->assertConflict();
    }

    public function test_archived_signature_rechecks_actual_original_and_revoked_representation_before_creating_proof(): void
    {
        $archive = $this->pendingArchive(); $url = $this->base().'/archive/'.$archive->id.'/sign';
        $signature = $this->signature(['SourceRevision' => $archive->source_hash]);
        $this->withToken($this->token($this->admin))->postJson($url, $signature)->assertNotFound();
        $this->withToken($this->token($this->parent));
        $this->archiveBytes = '%PDF-tampered';
        $this->postJson($url, $signature)->assertConflict();
        $this->assertSame(0, $this->db->table('clubunio.mobile_member_receipts')->count());
        $this->archiveBytes = $this->original;
        $this->db->table('account_member_links')->where('account_id', $this->parent->id)->update(['state' => 'pending']);
        $this->postJson($url, $signature)->assertForbidden();
        $this->assertSame(0, $this->db->table('clubunio.mobile_member_receipts')->count());
        $this->withToken($this->token($this->self))->postJson($url, $signature)->assertOk()->assertJsonPath('data.Dokument.Status', 'UNTERSCHRIEBEN');
    }
}
