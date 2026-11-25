<?php

namespace Tests\Feature;

use App\ClubUnio\Mobile\MalwareScanner;
use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\{DocumentSafety, DocumentUploadService, RegistrationDocumentService, SyncWorkService, TenantDocumentStorage};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Crypt, DB, Http, Mail, Queue};
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Core upload regression: in-memory SQL and scanner/storage fakes only. */
final class DocumentSafetyCoreTest extends TestCase
{
    private Account $actor;

    private Tenant $tenant;

    private const FOLDER = 'clubunio/storage/12345678-1234-1234-1234-123456789abc/registrations/71/1/';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.clubunio' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('clubunio');
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        $db = DB::connection('clubunio');
        $this->assertSame('sqlite', $db->getDriverName());
        $this->assertSame(':memory:', $db->getDatabaseName());
        $db->statement("ATTACH DATABASE ':memory:' AS clubora");
        $db->unprepared('CREATE TABLE clubora.tenants(id INTEGER PRIMARY KEY,name TEXT,slug TEXT);
            CREATE TABLE clubora.accounts(id INTEGER PRIMARY KEY,username TEXT,display_name TEXT,active INTEGER,api_only INTEGER,password TEXT);
            CREATE TABLE clubora.document_uploads(id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER,kind TEXT,object_id INTEGER,deduplication_key TEXT,sha256 TEXT,byte_count INTEGER,mime_type TEXT,
                folder TEXT,filename TEXT,encrypted_metadata TEXT,encrypted_bytes TEXT,created_by INTEGER,public_request_id TEXT,
                state TEXT DEFAULT "pending",result_id INTEGER,provider_started_at TEXT,published_at TEXT);');
        $db->table('clubora.tenants')->insert(['id' => 71, 'name' => 'Synthetic core fixture', 'slug' => 'synthetic-core']);
        $db->table('clubora.accounts')->insert(['id' => 1, 'username' => 'synthetic@example.invalid',
            'display_name' => 'Synthetic core actor', 'active' => true, 'api_only' => false, 'password' => 'synthetic-unused']);
        $this->actor = Account::findOrFail(1);
        $this->tenant = Tenant::findOrFail(71);
    }

    private function uploads(): DocumentUploadService
    {
        // Isolate scanner/publication ordering; source permission rules are not
        // substituted with a claim of end-to-end authorization coverage.
        return new class extends DocumentUploadService {
            public function authorizeSource(?Account $actor, Tenant $tenant, string $kind, int $id, array $metadata, ?string $publicRequest): void {}
        };
    }

    private function scanner(string $outcome): void
    {
        $this->mock(MalwareScanner::class)->shouldReceive('scan')->once()->andReturnUsing(function () use ($outcome) {
            if ($outcome === 'error') {
                throw new \RuntimeException('private upstream details');
            }

            return ['clean' => $outcome === 'clean', 'version' => 'synthetic-core-scanner'];
        });
    }

    private function expectRejected(callable $operation, string $outcome): void
    {
        try {
            $operation();
            $this->fail('Unsafe or unscanned file accepted');
        } catch (ValidationException $error) {
            $this->assertSame('infected', $outcome);
            $this->assertArrayHasKey('file', $error->errors());
        } catch (HttpException $error) {
            $this->assertSame('error', $outcome);
            $this->assertSame(503, $error->getStatusCode());
            $this->assertStringNotContainsString('private upstream', $error->getMessage());
        }
    }

    public function test_clean_infected_and_unavailable_scanner_decide_before_staging(): void
    {
        $service = $this->uploads();
        foreach (['infected', 'error', 'clean'] as $outcome) {
            $this->scanner($outcome);
            if ($outcome === 'clean') {
                $this->mock(TenantDocumentStorage::class)->shouldReceive('folder')->once()->andReturn(self::FOLDER);
                $this->mock(SyncWorkService::class)->shouldReceive('rememberDocument')->once();
                $id = $service->stage($this->actor, $this->tenant, 'registration', 1, ['kind' => 'identity'],
                    UploadedFile::fake()->createWithContent('synthetic.pdf', "%PDF-1.4\nSynthetic core file"));
                $row = $service->table()->where('id', $id)->first();
                $this->assertSame('pending', $row->state);
                $this->assertNotNull($row->encrypted_bytes);
                $this->assertSame("%PDF-1.4\nSynthetic core file", Crypt::decryptString($row->encrypted_bytes));
            } else {
                $this->expectRejected(fn () => $service->stage($this->actor, $this->tenant, 'registration', 1,
                    ['kind' => 'identity'], UploadedFile::fake()->createWithContent('synthetic.pdf', "%PDF-1.4\nSynthetic core file")), $outcome);
                $this->assertSame(0, $service->table()->count());
            }
        }
        Http::assertNothingSent();
    }

    public function test_old_pending_uploads_are_rescanned_before_storage_and_publication(): void
    {
        $service = $this->uploads();
        $bytes = "%PDF-1.4\nSynthetic old pending file";
        foreach (['infected', 'error', 'clean'] as $outcome) {
            $this->scanner($outcome);
            $id = $service->table()->insertGetId(['tenant_id' => $this->tenant->id, 'kind' => 'registration', 'object_id' => 1,
                'created_by' => $this->actor->id, 'folder' => self::FOLDER, 'filename' => '12345678-1234-1234-1234-123456789abc.pdf',
                'encrypted_metadata' => Crypt::encryptString(json_encode(['storage_fingerprint' => hash('sha256', self::FOLDER)], JSON_THROW_ON_ERROR)),
                'encrypted_bytes' => Crypt::encryptString($bytes), 'byte_count' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
                'mime_type' => 'application/pdf']);
            $storage = $this->mock(TenantDocumentStorage::class);
            if ($outcome === 'clean') {
                $storage->shouldReceive('put')->once()->with($this->tenant, self::FOLDER, \Mockery::type('string'), $bytes, 'application/pdf');
                $this->mock(RegistrationDocumentService::class)->shouldReceive('publishUpload')->once()->andReturn(99);
                $this->assertSame(99, $service->process($this->actor, $this->tenant, $id));
                $this->assertSame('published', $service->table()->where('id', $id)->value('state'));
                $this->assertNull($service->table()->where('id', $id)->value('encrypted_bytes'));
            } else {
                $storage->shouldNotReceive('put');
                $storage->shouldNotReceive('read');
                $this->expectRejected(fn () => $service->process($this->actor, $this->tenant, $id), $outcome);
                $this->assertSame('pending', $service->table()->where('id', $id)->value('state'));
                $this->assertNull($service->table()->where('id', $id)->value('provider_started_at'));
                $this->assertNotNull($service->table()->where('id', $id)->value('encrypted_bytes'));
            }
        }
        Http::assertNothingSent();
    }

    public function test_source_authorization_precedes_scanner_and_staging(): void
    {
        $this->mock(MalwareScanner::class)->shouldNotReceive('scan');
        $service = new class extends DocumentUploadService {
            public function authorizeSource(?Account $actor, Tenant $tenant, string $kind, int $id, array $metadata, ?string $publicRequest): void
            {
                abort(403);
            }
        };
        try {
            $service->stage($this->actor, $this->tenant, 'registration', 1, ['kind' => 'identity'],
                UploadedFile::fake()->createWithContent('synthetic.pdf', "%PDF-1.4\nSynthetic core file"));
            $this->fail('Unauthorized source accepted');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertSame(0, $service->table()->count());
    }

    public function test_malformed_verdicts_fail_closed_with_sanitized_unavailable_error(): void
    {
        foreach ([[], ['clean' => true], ['clean' => 'true', 'version' => 'synthetic'],
            ['clean' => 1, 'version' => 'synthetic'], ['clean' => true, 'version' => ''],
            ['clean' => true, 'version' => '  '], ['clean' => true, 'version' => 42]] as $verdict) {
            $this->mock(MalwareScanner::class)->shouldReceive('scan')->once()->andReturn($verdict);
            try {
                app(DocumentSafety::class)->assertClean('Synthetic core file');
                $this->fail('Malformed scanner verdict accepted');
            } catch (HttpException $error) {
                $this->assertSame(503, $error->getStatusCode());
                $this->assertSame(trans('document_safety.unavailable'), $error->getMessage());
            }
        }
    }

    public function test_rejection_and_unavailable_messages_are_localized_in_enabled_languages(): void
    {
        $messages = [];
        foreach (array_keys(config('clubunio-languages.enabled')) as $locale) {
            app()->setLocale($locale);
            $this->scanner('infected');
            try {
                app(DocumentSafety::class)->assertClean('Synthetic core file');
                $this->fail('Infected file accepted');
            } catch (ValidationException $error) {
                $messages[$locale] = $error->errors()['file'][0];
                $this->assertSame(trans('document_safety.rejected'), $messages[$locale]);
                $this->assertStringNotContainsString('document_safety.', $messages[$locale]);
            }
            $this->scanner('error');
            try {
                app(DocumentSafety::class)->assertClean('Synthetic core file');
                $this->fail('Unavailable scanner accepted');
            } catch (HttpException $error) {
                $this->assertSame(503, $error->getStatusCode());
                $this->assertSame(trans('document_safety.unavailable'), $error->getMessage());
                $this->assertStringNotContainsString('document_safety.', $error->getMessage());
                $this->assertStringNotContainsString('private upstream', $error->getMessage());
            }
        }
        $this->assertNotSame($messages['de'], $messages['en']);
    }
}
