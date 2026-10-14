<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\AzureDocumentReader;
use App\ClubUnio\Services\BexioAttachmentService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BexioAttachmentTest extends TestCase
{
    private function prepare(array $files): array
    {
        $tenant = new Tenant;
        $tenant->forceFill(['id' => 1, 'slug' => 'fixture']);
        config(['clubunio-integrations.azure.tenant_slug' => 'fixture']);
        $reader = \Mockery::mock(AzureDocumentReader::class);
        foreach ($files as $name => $bytes) {
            $reader->shouldReceive('read')->with('azure.blob://files/', $name)->once()->andReturn($bytes);
        }
        $this->app->instance(AzureDocumentReader::class, $reader);

        return app(BexioAttachmentService::class)->prepare($tenant, 123, ['source_hash' => str_repeat('a', 64), 'names' => array_keys($files)]);
    }

    public function test_empty_attachment_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->prepare(['empty.pdf' => '']);
    }

    public function test_individual_file_size_is_bounded(): void
    {
        $this->expectException(ValidationException::class);
        $this->prepare(['large.pdf' => str_repeat('x', BexioAttachmentService::MAX_FILE_BYTES + 1)]);
    }

    public function test_aggregate_size_is_bounded(): void
    {
        $pdf = '%PDF-1.4'.str_repeat(' ', BexioAttachmentService::MAX_FILE_BYTES - 8);
        $this->expectException(ValidationException::class);
        $this->prepare(['one.pdf' => $pdf, 'two.pdf' => $pdf, 'three.pdf' => '%PDF-1.4']);
    }

    public function test_mime_drives_canonical_name_and_content_checksum(): void
    {
        $pdf = "%PDF-1.4\nfixture\n%%EOF";
        $result = $this->prepare(['private-name.txt' => $pdf]);
        $this->assertSame('Spesen-123-1.pdf', $result['files'][0]['filename']);
        $this->assertSame('application/pdf', $result['files'][0]['mime']);
        $this->assertSame(hash('sha256', $pdf), $result['files'][0]['sha256']);
        $this->assertSame(strlen($pdf), $result['bytes']);
    }
}
