<?php

namespace App\ClubUnio\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Str;

// Only a mock handler can be constructed here. No configuration switch enables live writes.
class BexioSubmissionClient
{
    private Client $client;

    public function __construct(MockHandler $handler)
    {
        $this->client = new Client(['handler' => HandlerStack::create($handler), 'base_uri' => 'https://api.bexio.com',
            'http_errors' => false, 'allow_redirects' => false, 'timeout' => 15]);
    }

    public static function simulation(string $type, int $attachments, string $outcome): self
    {
        $responses = [];
        for ($i = 0; $i < $attachments; $i++) {
            $responses[] = new Response(200, [], json_encode([['uuid' => (string) Str::uuid()]]));
        }
        $responses[] = match ($outcome) {
            'simulated' => new Response(201, [], json_encode(['id' => $type === 'invoice' ? random_int(100000, 999999) : (string) Str::uuid()])),
            'rejected' => new Response(422),
            'uncertain' => new Response(504),
            default => throw new \InvalidArgumentException('Invalid simulation outcome'),
        };

        return new self(new MockHandler($responses));
    }

    public function upload(string $bytes, string $name, string $mime): array
    {
        return $this->request('/3.0/files', ['multipart' => [['name' => 'file', 'contents' => $bytes, 'filename' => $name,
            'headers' => ['Content-Type' => $mime]]]], 'file');
    }

    public function create(string $path, array $payload): array
    {
        $type = match ($path) {
            '/2.0/kb_invoice' => 'invoice', '/4.0/purchase/bills' => 'expense', default => throw new \InvalidArgumentException('Unsupported write path'),
        };

        return $this->request($path, ['json' => $payload], $type);
    }

    private function request(string $path, array $options, string $type): array
    {
        try {
            $response = $this->client->post($path, $options + ['headers' => ['Accept' => 'application/json']]);
            $status = $response->getStatusCode();
            if (in_array($status, [400, 401, 403, 422], true)) {
                return ['state' => 'rejected', 'http_status' => $status];
            }
            if ($status < 200 || $status >= 300) {
                return ['state' => 'uncertain', 'http_status' => $status];
            }
            $json = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);
            $id = $type === 'file' ? ($json[0]['uuid'] ?? null) : ($json['id'] ?? null);
            if (! is_scalar($id) || ($type === 'invoice' ? ! preg_match('/^[1-9][0-9]{0,14}$/D', (string) $id) : ! Str::isUuid((string) $id))) {
                return ['state' => 'uncertain', 'http_status' => $status];
            }

            return ['state' => 'accepted', 'http_status' => $status, 'id' => (string) $id];
        } catch (\Throwable) {
            // A timeout can happen after remote acceptance. Never blindly retry a POST.
            return ['state' => 'uncertain', 'http_status' => null];
        }
    }
}
