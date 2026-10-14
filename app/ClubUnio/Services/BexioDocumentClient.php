<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class BexioDocumentClient
{
    public function read(Tenant $tenant, string $type, string $id): array
    {
        $path = match ($type) {
            'invoice' => preg_match('/^[1-9][0-9]{0,14}$/', $id) ? '/2.0/kb_invoice/'.$id : null,
            'expense' => Str::isUuid($id) ? '/4.0/purchase/bills/'.$id : null,
            default => null,
        };
        if (! $path) {
            throw new \RuntimeException('Invalid document reference', 422);
        }
        $connection = app(BexioConnectionService::class);
        $token = $connection->accessToken($tenant);
        $read = fn ($token) => Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(12)->withoutRedirecting()->get('https://api.bexio.com'.$path);
        $response = $read($token);
        if ($response->status() === 401) {
            $response = $read($connection->accessToken($tenant, $token));
        }
        if (! $response->successful()) {
            throw new \RuntimeException('Document read failed', $response->status());
        }
        $data = $response->json();
        if (! is_array($data) || strtolower((string) ($data['id'] ?? '')) !== strtolower($id)) {
            throw new \RuntimeException('Document identity mismatch', 502);
        }

        return $data;
    }
}
