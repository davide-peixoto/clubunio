<?php

namespace Tests\Feature;

use App\ClubUnio\Services\AzureDocumentReader;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class AzureDocumentReaderTest extends TestCase
{
    public function test_path_mapping_and_unicode_names(): void
    {
        $reader = new AzureDocumentReader;
        $this->assertSame('clubunio/members/1/2/file.pdf', $reader->blobPath('clubunio/members/1/2/', 'file.pdf'));
        $this->assertSame('member-documents/Prüfung.pdf', $reader->blobPath('var/member-documents/', 'Prüfung.pdf'));
        $this->assertSame('upload/mitglieder/anhaenge/photo.png', $reader->blobPath('upload/mitglieder/anhaenge/', 'photo.png'));
        foreach (['../secret', '..\\secret', 'C:secret', "a\0b", '..', ''] as $invalid) {
            try {
                $reader->blobPath('var/member-documents/', $invalid);
                $this->fail('Unsafe path accepted');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $reader->blobPath('https://outside.invalid/', 'a.pdf');
    }

    public function test_bounded_read_and_sanitized_errors(): void
    {
        config(['clubunio-integrations.azure.enabled' => true, 'clubunio-integrations.azure.account' => 'fixturestore',
            'clubunio-integrations.azure.key' => base64_encode('fixture-key')]);
        $mock = new MockHandler([new Response(200, [], 'safe bytes'), new Response(404, [], 'private provider details'),
            new Response(200, ['Content-Length' => AzureDocumentReader::MAX_BYTES + 1], 'oversize'),
            new Response(302, ['Location' => 'https://other.invalid'])]);
        $reader = new AzureDocumentReader(new Client(['handler' => HandlerStack::create($mock)]));
        $this->assertSame('safe bytes', $reader->read('var/member-documents/', 'a.pdf'));
        foreach ([404, 503, 503] as $code) {
            try {
                $reader->read('var/member-documents/', 'a.pdf');
                $this->fail('Unsafe response accepted');
            } catch (\RuntimeException $error) {
                $this->assertSame($code, $error->getCode());
                $this->assertSame('Document storage unavailable', $error->getMessage());
                $this->assertNull($error->getPrevious());
            }
        }
    }
}
