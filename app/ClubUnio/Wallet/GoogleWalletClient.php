<?php

namespace App\ClubUnio\Wallet;

use Illuminate\Support\Facades\{Cache, Crypt, Http};

/** Fixed Google targets; never exposes credential contents or provider response bodies. */
class GoogleWalletClient
{
    private const API = 'https://walletobjects.googleapis.com/walletobjects/v1/';
    private const TOKEN = 'https://oauth2.googleapis.com/token';
    private const SCOPE = 'https://www.googleapis.com/auth/wallet_object.issuer';
    private const ORIGINS = ['dev' => 'https://dev.clubunio.ch', 'stage' => 'https://stage.clubunio.com', 'prod' => 'https://app.clubunio.com'];

    public function available(): bool
    {
        try { $this->credentials(); return true; } catch (\Throwable) { return false; }
    }

    public function environment(): string
    {
        $environment = (string) config('clubunio-google-wallet.environment');
        if (! isset(self::ORIGINS[$environment])) throw new \RuntimeException('google_wallet_environment');
        return $environment;
    }

    public function origin(): string
    {
        $origin = (string) config('clubunio-google-wallet.public_origin');
        if ($origin !== self::ORIGINS[$this->environment()] || config('app.url') !== $origin) {
            throw new \RuntimeException('google_wallet_origin');
        }
        return $origin;
    }

    private function issuer(): string
    {
        $issuer = (string) config('clubunio-google-wallet.issuer_id');
        if (! preg_match('/\A[0-9]{10,30}\z/D', $issuer)) throw new \RuntimeException('google_wallet_issuer');
        return $issuer;
    }

    public function objectId(string $uuid): string
    {
        if (! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/iD', $uuid)) {
            throw new \RuntimeException('google_wallet_identifier');
        }
        return $this->issuer().'.clubunio_'.$this->environment().'_'.strtolower(str_replace('-', '', $uuid));
    }

    public function classId(): string { return $this->issuer().'.clubunio_'.$this->environment().'_membercard_v1'; }

    private function validateObject(string $id): void
    {
        $prefix = $this->issuer().'.clubunio_'.$this->environment().'_';
        if (! str_starts_with($id, $prefix) || ! preg_match('/\A[0-9a-f]{32}\z/D', substr($id, strlen($prefix)))) {
            throw new \RuntimeException('google_wallet_identifier');
        }
    }

    private function credentials(): array
    {
        if (config('clubunio-google-wallet.enabled') !== true) throw new \RuntimeException('google_wallet_disabled');
        $this->origin(); $this->issuer();
        $path = (string) config('clubunio-google-wallet.credentials');
        if (! is_file($path) || ! is_readable($path) || is_link($path) || filesize($path) > 32768
            || (PHP_OS_FAMILY !== 'Windows' && (fileperms($path) & 0777) !== 0600)) {
            throw new \RuntimeException('google_wallet_credentials');
        }
        $real = realpath($path); $public = realpath(public_path());
        if (! $real || ($public && str_starts_with(strtolower($real), strtolower($public).DIRECTORY_SEPARATOR))) {
            throw new \RuntimeException('google_wallet_credentials');
        }
        try { $key = json_decode(file_get_contents($path), true, 8, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { throw new \RuntimeException('google_wallet_credentials'); }
        $project = (string) config('clubunio-google-wallet.project_id');
        $email = (string) config('clubunio-google-wallet.service_account_email');
        if (! preg_match('/\A[a-z][a-z0-9-]{4,62}\z/D', $project)
            || $email !== 'clubunio-wallet-'.$this->environment().'@'.$project.'.iam.gserviceaccount.com'
            || ($key['type'] ?? '') !== 'service_account' || ($key['project_id'] ?? '') !== $project
            || ($key['client_email'] ?? '') !== $email || ($key['token_uri'] ?? '') !== self::TOKEN) {
            throw new \RuntimeException('google_wallet_credentials');
        }
        $private = openssl_pkey_get_private($key['private_key'] ?? '');
        $details = $private ? openssl_pkey_get_details($private) : false;
        if (! $details || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048) {
            throw new \RuntimeException('google_wallet_credentials');
        }
        return $key;
    }

    private function jwt(array $claims, array $credentials): string
    {
        $encode = fn (string $value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $value = $encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)).'.'.$encode(json_encode($claims, JSON_THROW_ON_ERROR));
        if (! openssl_sign($value, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) throw new \RuntimeException('google_wallet_signing');
        return $value.'.'.$encode($signature);
    }

    private function accessToken(): string
    {
        $credentials = $this->credentials();
        $cacheKey = 'google-wallet-token:'.hash('sha256', $this->environment().'|'.$credentials['client_email'].'|'.($credentials['private_key_id'] ?? '').'|'.hash('sha256', $credentials['private_key']));
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            try { return Crypt::decryptString($cached); } catch (\Throwable) { Cache::forget($cacheKey); }
        }
        $now = time();
        $assertion = $this->jwt(['iss' => $credentials['client_email'], 'scope' => self::SCOPE, 'aud' => self::TOKEN, 'iat' => $now, 'exp' => $now + 3600], $credentials);
        try {
            $response = Http::asForm()->connectTimeout(3)->timeout(8)->withoutRedirecting()->post(self::TOKEN,
                ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion]);
        } catch (\Throwable) { throw new \RuntimeException('google_wallet_transport'); }
        $token = $response->json('access_token'); $expires = (int) $response->json('expires_in');
        if (! $response->successful() || ! is_string($token) || $token === '' || $expires < 60) throw new \RuntimeException('google_wallet_oauth');
        Cache::put($cacheKey, Crypt::encryptString($token), min(3300, $expires - 30));
        return $token;
    }

    private function request(string $method, string $path, ?array $body = null): \Illuminate\Http\Client\Response
    {
        $token = $this->accessToken();
        try {
            return Http::withToken($token)->acceptJson()->connectTimeout(3)->timeout(8)->withoutRedirecting()
                ->send($method, self::API.$path, $body === null ? [] : ['json' => $body]);
        } catch (\Throwable) { throw new \RuntimeException('google_wallet_transport'); }
    }

    private function requireSuccess(\Illuminate\Http\Client\Response $response): void
    {
        if (! $response->successful()) throw new \RuntimeException('google_wallet_http_'.$response->status());
    }

    public function upsert(array $payload): void
    {
        $this->credentials();
        $id = (string) ($payload['id'] ?? ''); $this->validateObject($id);
        if (($payload['classId'] ?? null) !== $this->classId()) throw new \RuntimeException('google_wallet_identifier');
        $class = $this->request('GET', 'genericClass/'.$this->classId());
        if ($class->status() === 404) {
            $class = $this->request('POST', 'genericClass', ['id' => $this->classId(), 'multipleDevicesAndHoldersAllowedStatus' => 'ONE_USER_ALL_DEVICES']);
            if ($class->status() === 409) $class = $this->request('GET', 'genericClass/'.$this->classId());
        }
        $this->requireSuccess($class);
        if ($class->json('id') !== $this->classId()) throw new \RuntimeException('google_wallet_identifier');
        $object = $this->request('GET', 'genericObject/'.$id);
        if ($object->status() === 404) {
            $object = $this->request('POST', 'genericObject', $payload);
            if ($object->status() !== 409) { $this->requireSuccess($object); return; }
            $object = $this->request('GET', 'genericObject/'.$id);
        }
        $this->requireSuccess($object);
        if ($object->json('id') !== $id || $object->json('classId') !== $this->classId()) throw new \RuntimeException('google_wallet_identifier');
        unset($payload['id'], $payload['classId']);
        $this->requireSuccess($this->request('PATCH', 'genericObject/'.$id, $payload));
    }

    public function saveUrl(string $objectId): string
    {
        $credentials = $this->credentials(); $this->validateObject($objectId);
        return 'https://pay.google.com/gp/v/save/'.$this->jwt(['iss' => $credentials['client_email'], 'aud' => 'google',
            'typ' => 'savetowallet', 'iat' => time(), 'origins' => [$this->origin()],
            'payload' => ['genericObjects' => [['id' => $objectId]]]], $credentials);
    }
}
