<?php

namespace App\ClubUnio\Services\Storage;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

abstract class DriveDocumentProvider implements DocumentProvider
{
    public function __construct(protected DriveObjectIndex $index) {}

    protected function http(): PendingRequest
    {
        return Http::connectTimeout(4)->timeout(15)->withOptions(['read_timeout' => 5, 'allow_redirects' => false]);
    }

    protected function expect(Response $response, int ...$statuses): Response
    {
        if (! in_array($response->status(), $statuses, true)) {
            throw ProviderBytes::unavailable();
        }

        return $response;
    }

    protected function tokenFrom(Response $response): string
    {
        $token = $this->expect($response, 200)->json('access_token');
        if (! is_string($token) || ! preg_match('/^[!-~]+$/D', $token)) {
            throw ProviderBytes::unavailable();
        }

        return $token;
    }
}
