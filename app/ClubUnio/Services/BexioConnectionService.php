<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Setting;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class BexioConnectionService
{
    public const CONNECTION_KEY = 'BEXIO_OIDC_CONNECTION';

    public static function canManage(?Account $actor, Tenant $tenant): bool
    {
        return $actor && $actor->fresh()?->active && $actor->tenants()->whereKey($tenant->id)->wherePivot('role', 'admin')->exists();
    }

    public function configuration(Tenant $tenant): array
    {
        $values = [];
        foreach (Setting::where('tenant_id', $tenant->id)->where('area', 'bexio')
            ->whereIn('key', ['BEXIO_CLIENT_ID', 'BEXIO_CLIENT_SECRET', 'BEXIO_AUTHORIZE_SCOPES'])->get() as $row) {
            $values[$row->key] = trim(Crypt::decryptString($row->encrypted_value));
        }
        $id = $values['BEXIO_CLIENT_ID'] ?? '';
        $secret = $values['BEXIO_CLIENT_SECRET'] ?? '';
        if ($id === '' || $secret === '') {
            throw new \RuntimeException('Missing client configuration', 401);
        }
        $scopes = preg_split('/\s+/', $values['BEXIO_AUTHORIZE_SCOPES'] ?? 'kb_invoice_show');
        $scopes = array_values(array_unique(array_filter([...$scopes, 'openid', 'offline_access'])));

        return ['id' => $id, 'secret' => $secret, 'scopes' => $scopes,
            'fingerprint' => hash('sha256', $id."\0".$secret."\0".implode(' ', $scopes))];
    }

    public function connection(Tenant $tenant): array
    {
        $row = Setting::where('tenant_id', $tenant->id)->where('area', 'bexio')->where('key', self::CONNECTION_KEY)->first();

        return $row ? json_decode(Crypt::decryptString($row->encrypted_value), true, 512, JSON_THROW_ON_ERROR) : [];
    }

    public function connect(Tenant $tenant, array $tokens, string $fingerprint, int $actorId): void
    {
        DB::connection('clubunio')->transaction(function () use ($tenant, $tokens, $fingerprint, $actorId) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless(self::canManage(Account::find($actorId), $tenant), 403);
            if (! hash_equals($this->configuration($tenant)['fingerprint'], $fingerprint)) {
                throw new \RuntimeException('Configuration changed', 409);
            }
            $this->store($tenant, $tokens, ['fingerprint' => $fingerprint, 'connected_by' => $actorId, 'connected_at' => now()->toIso8601String()]);
        });
    }

    public function accessToken(Tenant $tenant, ?string $rejectedToken = null): string
    {
        // A PostgreSQL row lock serializes rotation across local and Pi processes.
        return DB::connection('clubunio')->transaction(function () use ($tenant, $rejectedToken) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $config = $this->configuration($tenant);
            $connection = $this->connection($tenant);
            if (empty($connection['refresh_token']) || empty($connection['fingerprint'])
                || ! hash_equals($config['fingerprint'], $connection['fingerprint'])) {
                throw new \RuntimeException('Independent Bexio connection required', 401);
            }
            $token = $connection['access_token'] ?? '';
            if ($token !== '' && ($connection['expires_at'] ?? 0) > time() + 60
                && ($rejectedToken === null || ! hash_equals($token, $rejectedToken))) {
                return $token;
            }
            $response = Http::asForm()->acceptJson()->withBasicAuth($config['id'], $config['secret'])
                ->connectTimeout(5)->timeout(20)->withoutRedirecting()->post(BexioOidcClient::TOKEN_ENDPOINT, [
                    'grant_type' => 'refresh_token', 'refresh_token' => $connection['refresh_token'],
                ]);
            if (! $response->successful()) {
                throw new \RuntimeException('Token refresh failed', $response->status() === 400 ? 401 : $response->status());
            }
            $tokens = $response->json();
            if (! is_array($tokens)) {
                throw new \RuntimeException('Invalid token response', 502);
            }
            // Some providers omit a new refresh token; keep the current one in that case.
            $tokens['refresh_token'] ??= $connection['refresh_token'];
            $this->store($tenant, $tokens, $connection);

            return $tokens['access_token'];
        });
    }

    private function store(Tenant $tenant, array $tokens, array $metadata): void
    {
        foreach (['access_token', 'refresh_token'] as $field) {
            if (! is_string($tokens[$field] ?? null) || trim($tokens[$field]) === '' || strlen($tokens[$field]) > 32000) {
                throw new \RuntimeException('Invalid token response', 502);
            }
        }
        if (strtolower($tokens['token_type'] ?? '') !== 'bearer' || ! is_numeric($tokens['expires_in'] ?? null)
            || (int) $tokens['expires_in'] <= 0) {
            throw new \RuntimeException('Invalid token lifetime/type', 502);
        }
        $data = array_intersect_key($metadata, array_flip(['fingerprint', 'connected_by', 'connected_at']));
        $data += ['access_token' => $tokens['access_token'], 'refresh_token' => $tokens['refresh_token'],
            'expires_at' => time() + (int) $tokens['expires_in'], 'refreshed_at' => now()->toIso8601String()];
        $row = Setting::where('tenant_id', $tenant->id)->where('area', 'bexio')->where('key', self::CONNECTION_KEY)->first() ?? new Setting;
        $row->forceFill(['tenant_id' => $tenant->id, 'area' => 'bexio', 'key' => self::CONNECTION_KEY,
            'label' => 'Bexio OpenID-Verbindung (automatisch)', 'value_type' => 'string', 'is_secret' => true,
            'enabled' => true, 'encrypted_value' => Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR))])->save();
    }
}
