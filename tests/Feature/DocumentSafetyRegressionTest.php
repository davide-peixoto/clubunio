<?php

namespace Tests\Feature;

use App\ClubUnio\Mobile\MalwareScanner;
use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\{ClubhouseMenus, DocumentSafety, DocumentUploadService, RegistrationDocumentService, SyncWorkService, TenantDocumentStorage};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Crypt, DB};
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\ClubhouseFixture;
use Tests\TestCase;

class DocumentSafetyRegressionTest extends TestCase
{
    use ClubhouseFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clubhouseFixture(); // SQLite in memory only; blocks external HTTP.
        DB::connection('clubunio')->statement('CREATE TABLE clubora.document_uploads(id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER,kind TEXT,object_id INTEGER,deduplication_key TEXT,sha256 TEXT,byte_count INTEGER,mime_type TEXT,
            folder TEXT,filename TEXT,encrypted_metadata TEXT,encrypted_bytes TEXT,created_by INTEGER,public_request_id TEXT,
            state TEXT DEFAULT "pending",result_id INTEGER,provider_started_at TEXT,published_at TEXT)');
    }

    private function scanner(string $outcome): void
    {
        $this->mock(MalwareScanner::class)->shouldReceive('scan')->once()->andReturnUsing(function () use ($outcome) {
            if ($outcome === 'error') throw new \RuntimeException('private upstream details');
            return ['clean' => $outcome === 'clean', 'version' => 'synthetic-scanner'];
        });
    }

    private function expectRejected(callable $operation, string $outcome): void
    {
        try {
            $operation();
            $this->fail('Unsafe or unscanned file was accepted');
        } catch (ValidationException $e) {
            $this->assertSame('infected', $outcome);
            $this->assertArrayHasKey('file', $e->errors());
        } catch (HttpException $e) {
            $this->assertSame('error', $outcome);
            $this->assertSame(503, $e->getStatusCode());
            $this->assertStringNotContainsString('private upstream', $e->getMessage());
        }
    }

    private function uploads(): DocumentUploadService
    {
        // Source authorization is covered separately; this isolates scan ordering and publication.
        return new class extends DocumentUploadService {
            public function authorizeSource(?Account $actor, Tenant $tenant, string $kind, int $id, array $metadata, ?string $publicRequest): void {}
        };
    }

    public function test_general_upload_is_scanned_before_staging(): void
    {
        foreach (['infected', 'error', 'clean'] as $outcome) {
            $this->scanner($outcome);
            $service = $this->uploads();
            if ($outcome !== 'clean') {
                $this->expectRejected(fn () => $service->stage($this->admin, $this->club, 'registration', 1,
                    ['kind' => 'identity'], UploadedFile::fake()->createWithContent('test.pdf', "%PDF-1.4\nSynthetic")), $outcome);
                $this->assertSame(0, $service->table()->count());
            } else {
                $this->mock(TenantDocumentStorage::class)->shouldReceive('folder')->once()->andReturn('clubunio/registrations/71/1/');
                $this->mock(SyncWorkService::class)->shouldReceive('rememberDocument')->once();
                $id = $service->stage($this->admin, $this->club, 'registration', 1, ['kind' => 'identity'],
                    UploadedFile::fake()->createWithContent('test.pdf', "%PDF-1.4\nSynthetic"));
                $this->assertSame('pending', $service->table()->where('id', $id)->value('state'));
            }
        }
    }

    public function test_old_pending_uploads_are_rescanned_before_external_storage_and_publication(): void
    {
        $service = $this->uploads();
        $bytes = "%PDF-1.4\nSynthetic pending file";
        $folder = 'clubunio/storage/12345678-1234-1234-1234-123456789abc/registrations/71/1/';
        foreach (['infected', 'error', 'clean'] as $outcome) {
            $this->scanner($outcome);
            $id = $service->table()->insertGetId(['tenant_id' => 71, 'kind' => 'registration', 'object_id' => 1,
                'created_by' => 1, 'folder' => $folder, 'filename' => '12345678-1234-1234-1234-123456789abc.pdf',
                'encrypted_metadata' => Crypt::encryptString(json_encode(['storage_fingerprint' => hash('sha256', $folder)])),
                'encrypted_bytes' => Crypt::encryptString($bytes), 'byte_count' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
                'mime_type' => 'application/pdf']);
            $storage = $this->mock(TenantDocumentStorage::class);
            if ($outcome === 'clean') {
                $storage->shouldReceive('put')->once()->with($this->club, $folder, \Mockery::type('string'), $bytes, 'application/pdf');
                $this->mock(RegistrationDocumentService::class)->shouldReceive('publishUpload')->once()->andReturn(99);
                $this->assertSame(99, $service->process($this->admin, $this->club, $id));
                $this->assertNull($service->table()->where('id', $id)->value('encrypted_bytes'));
            } else {
                $storage->shouldNotReceive('put');
                $storage->shouldNotReceive('read');
                $this->expectRejected(fn () => $service->process($this->admin, $this->club, $id), $outcome);
                $this->assertSame('pending', $service->table()->where('id', $id)->value('state'));
                $this->assertNull($service->table()->where('id', $id)->value('provider_started_at'));
            }
        }
    }

    public function test_clubhouse_upload_requires_clean_scan_before_external_write(): void
    {
        $menus = app(ClubhouseMenus::class);
        $id = $menus->save($this->admin, $this->club, null, $this->menu());
        foreach (['infected', 'error', 'clean'] as $outcome) {
            $this->scanner($outcome);
            $storage = $this->mock(TenantDocumentStorage::class);
            if ($outcome === 'clean') {
                $storage->shouldReceive('folder')->once()->andReturn('clubunio/clubhouse/71/'.$id.'/');
                $storage->shouldReceive('put')->once();
                $this->assertTrue($menus->upload($this->admin, $this->club, $id, 1,
                    UploadedFile::fake()->createWithContent('test.pdf', "%PDF-1.4\nSynthetic"))['has_document']);
            } else {
                $storage->shouldNotReceive('put');
                $this->expectRejected(fn () => $menus->upload($this->admin, $this->club, $id, 1,
                    UploadedFile::fake()->createWithContent('test.pdf', "%PDF-1.4\nSynthetic")), $outcome);
                $this->assertNull($menus->query($this->club)->where('id', $id)->value('document'));
            }
        }
    }

    public function test_clubhouse_existing_unscanned_document_cannot_publish_without_clean_verdict(): void
    {
        $menus = app(ClubhouseMenus::class);
        $id = $menus->save($this->admin, $this->club, null, $this->menu());
        $bytes = "%PDF-1.4\nPreviously uploaded";
        $menus->query($this->club)->where('id', $id)->update(['document' => json_encode([
            'folder' => 'clubunio/clubhouse/71/'.$id.'/', 'filename' => '12345678-1234-1234-1234-123456789abc.pdf',
            'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
        ])]);
        $this->mock(TenantDocumentStorage::class)->shouldReceive('read')->times(3)->andReturn($bytes);
        foreach (['infected', 'error', 'clean'] as $outcome) {
            $this->scanner($outcome);
            if ($outcome === 'clean') {
                $menus->transition($this->admin, $this->club, $id, 1, 'publish');
                $this->assertNotNull($menus->query($this->club)->where('id', $id)->value('published_snapshot'));
            } else {
                $this->expectRejected(fn () => $menus->transition($this->admin, $this->club, $id, 1, 'publish'), $outcome);
                $this->assertNull($menus->query($this->club)->where('id', $id)->value('published_snapshot'));
                $this->assertSame(1, $menus->query($this->club)->where('id', $id)->value('revision'));
            }
        }
    }

    public function test_unauthorized_upload_does_not_invoke_scanner(): void
    {
        $this->mock(MalwareScanner::class)->shouldNotReceive('scan');
        try {
            app(ClubhouseMenus::class)->upload($this->reader, $this->club, 1, 1,
                UploadedFile::fake()->createWithContent('test.pdf', "%PDF-1.4\nSynthetic"));
            $this->fail('Unauthorized upload accepted');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_inconclusive_scanner_response_is_not_clean(): void
    {
        $this->mock(MalwareScanner::class)->shouldReceive('scan')->once()->andReturn(['clean' => true]);
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage(trans('document_safety.unavailable'));
        app(DocumentSafety::class)->assertClean('Synthetic');
    }

    public function test_general_source_authorization_runs_before_scanning(): void
    {
        $this->mock(MalwareScanner::class)->shouldNotReceive('scan');
        $service = new class extends DocumentUploadService {
            public function authorizeSource(?Account $actor, Tenant $tenant, string $kind, int $id, array $metadata, ?string $publicRequest): void { abort(403); }
        };
        $this->expectException(HttpException::class);
        $service->stage($this->admin, $this->club, 'registration', 1, ['kind' => 'identity'],
            UploadedFile::fake()->createWithContent('test.pdf', "%PDF-1.4\nSynthetic"));
    }

    public function test_rejection_is_localized_in_each_enabled_language(): void
    {
        $messages = [];
        foreach (array_keys(config('clubunio-languages.enabled')) as $locale) {
            app()->setLocale($locale);
            $this->scanner('infected');
            try {
                app(DocumentSafety::class)->assertClean('Synthetic');
                $this->fail('Infected file accepted');
            } catch (ValidationException $e) {
                $messages[$locale] = $e->errors()['file'][0];
                $this->assertSame(trans('document_safety.rejected'), $messages[$locale]);
                $this->assertStringNotContainsString('document_safety.', $messages[$locale]);
            }
            $this->assertStringNotContainsString('document_safety.', trans('document_safety.unavailable'));
        }
        $this->assertNotSame($messages['de'], $messages['en']);
    }
}
