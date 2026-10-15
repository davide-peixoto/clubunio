<?php

namespace Tests\Feature;

use App\ClubUnio\Services\RegistrationBlobStore;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class RegistrationBlobStoreTest extends TestCase
{
    public function test_upload_is_private_create_only_and_cannot_overwrite(): void
    {
        config(['clubunio-integrations.azure' => ['enabled' => true, 'container' => 'svs-prod-private', 'account' => 'fixtureaccount', 'key' => base64_encode(str_repeat('x', 32))]]);
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(201), new Response(412, [], 'sensitive provider body')]));
        $stack->push(Middleware::history($history));
        $store = new RegistrationBlobStore(new Client(['handler' => $stack]));
        $args = ['clubunio/registrations/1/2/', '12345678-1234-1234-1234-123456789012.pdf', '%PDF-1.4 fixture', 'application/pdf'];
        $store->put(...$args);
        $request = $history[0]['request'];
        $this->assertSame('PUT', $request->getMethod());
        $this->assertSame('*', $request->getHeaderLine('If-None-Match'));
        $this->assertSame('BlockBlob', $request->getHeaderLine('x-ms-blob-type'));
        parse_str($request->getUri()->getQuery(), $sas);
        $this->assertSame('c', $sas['sp']);
        $this->assertStringContainsString('/svs-prod-private/clubunio/registrations/1/2/', $request->getUri()->getPath());
        try {
            $store->put(...$args);
            $this->fail('Overwrite accepted');
        } catch (\RuntimeException $error) {
            $this->assertSame('Private document upload unavailable', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
    }

    public function test_legacy_folders_cannot_be_written(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new RegistrationBlobStore)->put('upload/anmeldungen/', 'fixture.pdf', 'fixture', 'application/pdf');
    }

    public function test_member_namespace_is_private_and_scoped_to_positive_ids(): void
    {
        config(['clubunio-integrations.azure' => ['enabled' => true, 'container' => 'svs-prod-private', 'account' => 'fixtureaccount', 'key' => base64_encode(str_repeat('x', 32))]]);
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(201)]));
        $stack->push(Middleware::history($history));
        $store = new RegistrationBlobStore(new Client(['handler' => $stack]));
        $store->put('clubunio/members/1/2/', '12345678-1234-1234-1234-123456789012.pdf', '%PDF-1.4 fixture', 'application/pdf');
        $this->assertSame('/svs-prod-private/clubunio/members/1/2/12345678-1234-1234-1234-123456789012.pdf', $history[0]['request']->getUri()->getPath());
        $this->expectException(\InvalidArgumentException::class);
        $store->put('clubunio/members/1/../', '12345678-1234-1234-1234-123456789012.pdf', 'fixture', 'application/pdf');
    }
}
