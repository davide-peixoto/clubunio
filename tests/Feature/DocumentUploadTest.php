<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Expense;
use App\ClubUnio\Models\ExpenseLine;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\MemberDocument;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\AzureDocumentReader;
use App\ClubUnio\Services\DocumentUploadService;
use App\ClubUnio\Services\ExpenseDocumentService;
use App\ClubUnio\Services\ExpenseService;
use App\ClubUnio\Services\RegistrationBlobStore;
use App\ClubUnio\Services\SyncWorkService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DocumentUploadTest extends TestCase
{
    private $db;

    private Tenant $tenant;

    private Account $actor;

    private Member $member;

    private int $type;

    private string $bytes = "%PDF-1.4\nprivate synthetic upload\n%%EOF";

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Isolated DB opt-in');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->tenant = Tenant::create(['slug' => 'upload-'.Str::random(10), 'name' => 'Synthetic upload club']);
        $this->actor = new Account;
        $this->actor->forceFill(['username' => Str::random(20), 'display_name' => 'Synthetic uploader', 'password' => bcrypt('fixture'), 'active' => true])->save();
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $this->actor->id, 'role' => 'admin']);
        $this->member = new Member;
        $this->member->forceFill(['tenant_id' => $this->tenant->id, 'first_name' => 'Synthetic', 'last_name' => 'Upload'])->save();
        $this->type = $this->db->table('clubora.document_types')->insertGetId(['tenant_id' => $this->tenant->id, 'code' => 'synthetic', 'name' => 'Synthetic attachment', 'active' => true]);
        config(['clubunio-integrations.azure.tenant_slug' => $this->tenant->slug]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->transactionLevel()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    private function stage(): int
    {
        return app(DocumentUploadService::class)->stage($this->actor, $this->tenant, 'member', $this->member->id,
            ['type' => $this->type, 'portal_visible' => false], UploadedFile::fake()->createWithContent('private-original.pdf', $this->bytes));
    }

    private function failUpload(int $id): void
    {
        try {
            app(DocumentUploadService::class)->attempt($this->actor, $this->tenant, $id);
            $this->fail('Expected safely retained upload');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('Referenz: Upload #'.$id, $error->getMessage());
        }
    }

    public function test_timeout_is_retained_encrypted_and_existing_azure_blob_publishes_once(): void
    {
        $uploads = app(DocumentUploadService::class);
        $id = $this->stage();
        $this->assertSame($id, $this->stage());
        $row = $uploads->table()->find($id);
        $this->assertStringNotContainsString($this->bytes, json_encode($row));
        $this->assertStringNotContainsString('private-original.pdf', json_encode($row));
        $this->assertSame($this->bytes, Crypt::decryptString($row->encrypted_bytes));
        $this->mock(RegistrationBlobStore::class)->shouldReceive('put')->once()->with($row->folder, $row->filename, $this->bytes, 'application/pdf')->andThrow(new \RuntimeException('timeout', 504));
        $this->failUpload($id);
        $this->assertSame(0, MemberDocument::where('tenant_id', $this->tenant->id)->count());
        $this->assertSame('attention', app(SyncWorkService::class)->table()->where('tenant_id', $this->tenant->id)->value('state'));
        $this->mock(AzureDocumentReader::class)->shouldReceive('read')->once()->with($row->folder, $row->filename)->andReturn($this->bytes);
        $document = $uploads->attempt($this->actor, $this->tenant, $id);
        $this->assertSame($document, $uploads->attempt($this->actor, $this->tenant, $id));
        $this->assertSame(1, MemberDocument::where('tenant_id', $this->tenant->id)->count());
        $this->assertNull($uploads->table()->find($id)->encrypted_bytes);
        $this->assertSame('succeeded', app(SyncWorkService::class)->table()->where('tenant_id', $this->tenant->id)->value('state'));
    }

    public function test_unavailable_or_different_azure_content_never_causes_a_blind_put(): void
    {
        $id = $this->stage();
        app(DocumentUploadService::class)->table()->where('id', $id)->update(['provider_started_at' => now()->toIso8601String()]);
        $this->mock(RegistrationBlobStore::class)->shouldNotReceive('put');
        $reader = $this->mock(AzureDocumentReader::class);
        $reader->shouldReceive('read')->once()->andThrow(new \RuntimeException('unavailable', 503));
        $this->failUpload($id);
        $reader->shouldReceive('read')->once()->andReturn('wrong file');
        $this->failUpload($id);
        $this->assertSame('pending', app(DocumentUploadService::class)->table()->find($id)->state);
        $this->assertSame(0, MemberDocument::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_missing_blob_retries_same_path_and_manual_close_keeps_recoverable_bytes(): void
    {
        $id = $this->stage();
        $uploads = app(DocumentUploadService::class);
        $row = $uploads->table()->find($id);
        $uploads->table()->where('id', $id)->update(['provider_started_at' => now()->toIso8601String()]);
        $this->mock(AzureDocumentReader::class)->shouldReceive('read')->once()->andThrow(new \RuntimeException('not found', 404));
        $this->mock(RegistrationBlobStore::class)->shouldReceive('put')->once()->with($row->folder, $row->filename, $this->bytes, 'application/pdf')->andThrow(new \RuntimeException('unavailable', 503));
        $this->failUpload($id);
        $sync = app(SyncWorkService::class);
        $task = $sync->table()->where('tenant_id', $this->tenant->id)->first();
        $sync->close($this->actor, $this->tenant, $task->id, $task->revision, 'Synthetic intentional cancellation');
        $this->assertSame('cancelled', $uploads->table()->find($id)->state);
        $this->assertSame($this->bytes, Crypt::decryptString($uploads->table()->find($id)->encrypted_bytes));
        $this->assertSame(0, MemberDocument::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_changed_storage_or_revoked_source_permission_blocks_retry_before_network(): void
    {
        $id = $this->stage();
        $this->mock(RegistrationBlobStore::class)->shouldNotReceive('put');
        $this->mock(AzureDocumentReader::class)->shouldNotReceive('read');
        config(['clubunio-integrations.azure.container' => 'different-target']);
        $this->failUpload($id);
        $this->actor->forceFill(['active' => false])->save();
        $this->failUpload($id);
        $this->assertSame(0, MemberDocument::where('tenant_id', $this->tenant->id)->count());
    }

    private function expenseLine(): ExpenseLine
    {
        $status = $this->db->table('clubora.expense_statuses')->insertGetId(['tenant_id' => $this->tenant->id, 'code' => 'O', 'name' => 'Offen']);
        $expense = new Expense;
        $expense->forceFill(['tenant_id' => $this->tenant->id, 'member_id' => $this->member->id, 'status_id' => $status, 'revision' => 1])->save();
        $line = new ExpenseLine;
        $line->forceFill(['tenant_id' => $this->tenant->id, 'expense_id' => $expense->id, 'amount' => 12, 'storage_reference' => 'legacy.pdf', 'revision' => 1])->save();

        return $line->fresh();
    }

    public function test_expense_upload_preserves_legacy_references_and_is_idempotent_after_revision_change(): void
    {
        $line = $this->expenseLine();
        $this->mock(RegistrationBlobStore::class)->shouldReceive('put')->once()->andReturnNull();
        $service = app(ExpenseDocumentService::class);
        $file = UploadedFile::fake()->createWithContent('receipt.pdf', $this->bytes);
        $this->assertSame($line->id, $service->add($this->actor, $this->tenant, $line->id, 1, 1, $file));
        $line->refresh();
        $this->assertStringStartsWith('legacy.pdf,clubunio/expenses/'.$this->tenant->id.'/'.$line->id.'/', $line->storage_reference);
        $this->assertSame(2, (int) $line->revision);
        $this->assertSame(2, (int) $line->expense->revision);
        $this->assertSame($line->id, $service->add($this->actor, $this->tenant, $line->id, 2, 2, $file));
        $this->assertSame(1, app(DocumentUploadService::class)->table()->where('tenant_id', $this->tenant->id)->count());
        $newPath = explode(',', $line->storage_reference)[1];
        $this->assertSame($newPath, (new AzureDocumentReader)->blobPath('azure.blob://files/', $newPath));
    }

    public function test_pending_expense_attachment_blocks_release_and_stale_source_blocks_publish(): void
    {
        $line = $this->expenseLine();
        $uploads = app(DocumentUploadService::class);
        $id = $uploads->stage($this->actor, $this->tenant, 'expense', $line->id, ['revision' => 1, 'expense_revision' => 1], UploadedFile::fake()->createWithContent('receipt.pdf', $this->bytes));
        $released = $this->db->table('clubora.expense_statuses')->insertGetId(['tenant_id' => $this->tenant->id, 'code' => 'F', 'name' => 'Freigegeben']);
        try {
            app(ExpenseService::class)->transition($this->actor, $this->tenant, $line->expense_id, ['revision' => 1, 'status_id' => $released, 'reason' => 'Synthetic release test']);
            $this->fail('Pending upload must block release');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('Azure', $error->getMessage());
        }
        $line->increment('revision');
        $this->mock(RegistrationBlobStore::class)->shouldNotReceive('put');
        $this->failUpload($id);
        $this->assertSame('legacy.pdf', $line->fresh()->storage_reference);
        $this->assertNotNull($uploads->table()->find($id)->encrypted_bytes);
    }
}
