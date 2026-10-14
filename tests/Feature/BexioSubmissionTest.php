<?php

namespace Tests\Feature;

use App\ClubUnio\Services\BexioSubmissionClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class BexioSubmissionTest extends TestCase
{
    public function test_upload_uuid_is_used_in_bill_creation(): void
    {
        $uuid = 'b4c6ba83-19d9-4674-9084-ae9d52d8e7e0';
        $handler = new MockHandler([new Response(200, [], json_encode([['uuid' => $uuid]])), new Response(201, [], json_encode(['id' => $uuid]))]);
        $client = new BexioSubmissionClient($handler);
        $file = $client->upload('%PDF-fixture', 'Spesen-1.pdf', 'application/pdf');
        $this->assertSame('accepted', $file['state']);
        $this->assertSame('/3.0/files', $handler->getLastRequest()->getUri()->getPath());
        $this->assertStringContainsString('multipart/form-data', $handler->getLastRequest()->getHeaderLine('Content-Type'));
        $result = $client->create('/4.0/purchase/bills', ['attachment_ids' => [$file['id']]]);
        $this->assertSame('accepted', $result['state']);
        $this->assertSame([$uuid], json_decode((string) $handler->getLastRequest()->getBody(), true)['attachment_ids']);
    }

    public function test_no_retry_on_uncertain_or_invalid_response(): void
    {
        foreach ([new Response(504), new Response(429), new Response(302), new Response(201, [], '{}'), new Response(201, [], 'not json')] as $response) {
            $handler = new MockHandler([$response, new Response(201, [], '{"id":1}')]);
            $result = (new BexioSubmissionClient($handler))->create('/2.0/kb_invoice', []);
            $this->assertSame('uncertain', $result['state']);
            $this->assertCount(1, $handler);
        }
    }

    public function test_provider_rejection_is_distinguished_without_private_error_body(): void
    {
        $handler = new MockHandler([new Response(422, [], 'private details')]);
        $result = (new BexioSubmissionClient($handler))->create('/2.0/kb_invoice', []);
        $this->assertSame(['state' => 'rejected', 'http_status' => 422], $result);
    }
}
