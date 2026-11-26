<?php

namespace App\ClubUnio\Services;

use Illuminate\Support\Facades\{Cache, DB, Http};

final class KeycloakBackchannelLogout
{
    public const EVENT = 'http://schemas.openid.net/event/backchannel-logout';

    public function verify(#[\SensitiveParameter] string $token): \stdClass
    {
        abort_unless(strlen($token) <= 16384 && preg_match('/\A[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\z/', $token), 400);
        $issuer = config('clubunio-verein-oidc.issuer');
        abort_unless(is_string($issuer) && str_starts_with($issuer, 'https://'), 503);
        try {
            // Never use token-controlled iss/jku/x5u as a network destination.
            $keys = Http::acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(8)
                ->get($issuer.'/protocol/openid-connect/certs')->throw()->json();
        } catch (\Throwable) {
            abort(503);
        }
        try {
            $claims = KeycloakCodeExchange::decodeSignedToken($token, $keys);
        } catch (\Throwable) {
            abort(400);
        }
        $clients = array_merge([config('clubunio-verein-oidc.client'),
            config('clubunio-platform-oidc.issuer') === $issuer ? config('clubunio-platform-oidc.client') : null],
            (array) config('clubunio-keycloak-backchannel.clients', []));
        $clients = array_values(array_filter($clients, fn ($client) => is_string($client) && $client !== ''));
        abort_unless(self::validClaims($claims, $issuer, $clients, time()), 400);

        return $claims;
    }

    public static function validClaims(\stdClass $claims, string $issuer, array $clients, int $now): bool
    {
        $aud = $claims->aud ?? null;
        $aud = is_string($aud) ? [$aud] : $aud;
        $event = $claims->events ?? null;
        return ($claims->iss ?? null) === $issuer && is_array($aud) && $aud !== []
            && count(array_filter($aud, 'is_string')) === count($aud) && array_intersect($aud, $clients) !== []
            && is_int($claims->iat ?? null) && $claims->iat >= $now - 300 && $claims->iat <= $now + 10
            && is_int($claims->exp ?? null) && $claims->exp > $now && $claims->exp > $claims->iat
            && is_string($claims->sub ?? null) && $claims->sub !== '' && strlen($claims->sub) <= 255
            && is_string($claims->jti ?? null) && $claims->jti !== '' && strlen($claims->jti) <= 255
            && !property_exists($claims, 'nonce') && $event instanceof \stdClass
            && property_exists($event, self::EVENT) && $event->{self::EVENT} instanceof \stdClass
            && get_object_vars($event->{self::EVENT}) === [];
    }

    public function revoke(\stdClass $claims): void
    {
        $store = config('clubunio-keycloak-backchannel.cache_store');
        $driver = is_string($store) ? config('cache.stores.'.$store.'.driver') : null;
        // Replay state and locks must be shared by all PHP workers/hosts.
        abort_unless(in_array($driver, ['redis', 'database'], true), 503);
        $key = 'keycloak-backchannel:'.hash('sha256', $claims->iss.'|'.$claims->jti);
        try {
            $cache = Cache::store($store);
            $cache->lock($key.':lock', 30)->block(10, function () use ($cache, $key, $claims) {
                if ($cache->has($key)) return;
                DB::connection('clubunio')->transaction(function () use ($claims) {
                    $db = DB::connection('clubunio');
                    $linked = $db->table('clubunio.keycloak_account_links')->where('issuer', $claims->iss)
                        ->where('subject', $claims->sub)->value('account_id');
                    $candidates = $linked === null ? [] : [(int) $linked];
                    $mappings = ['clubunio-verein-oidc.subjects'];
                    if (config('clubunio-platform-oidc.issuer') === $claims->iss) {
                        $mappings = array_merge($mappings, ['clubunio-platform-oidc.subjects', 'clubunio-platform-oidc.migrated_subjects']);
                        if (config('clubunio-platform-oidc.invitations_enabled')) {
                            $invited = $db->table('clubunio.platform_keycloak_invitations')->where('issuer', $claims->iss)
                                ->where('subject', $claims->sub)->whereNotNull('invited_at')->value('account_id');
                            if ($invited !== null) $candidates[] = (int) $invited;
                        }
                    }
                    foreach ($mappings as $mapping) {
                        $mapped = ((array) config($mapping, []))[$claims->sub] ?? null;
                        if (is_int($mapped) && $mapped > 0) $candidates[] = $mapped;
                    }
                    $candidates = array_values(array_unique($candidates));
                    if (count($candidates) > 1) throw new \UnexpectedValueException;
                    $account = $candidates[0] ?? null;
                    if ($account === null) return;
                    // Serialize against MobileSessions::issue, which locks this account.
                    $db->table('clubunio.accounts')->where('id', $account)->lockForUpdate()->first();
                    $watermarks = $db->table('clubunio.keycloak_logout_watermarks')->where('account_id', $account);
                    $previous = $watermarks->value('cutoff');
                    if ($previous === null || (int) $previous < $claims->iat) {
                        $db->table('clubunio.keycloak_logout_watermarks')->updateOrInsert(['account_id' => $account],
                            ['issuer' => $claims->iss, 'subject' => $claims->sub, 'cutoff' => $claims->iat]);
                    }
                    $db->table('clubunio.mobile_sessions')->where('account_id', $account)->whereNull('revoked_at')
                        // JWT times have second precision: include the entire event second.
                        ->where('created_at', '<', \Illuminate\Support\Carbon::createFromTimestampUTC($claims->iat + 1)->toIso8601String())
                        ->update(['revoked_at' => now()->toIso8601String()]);
                });
                // Written only after successful commit. Retries never extend the cutoff.
                if (!$cache->put($key, true, 600)) throw new \RuntimeException;
            });
        } catch (\Throwable) {
            // Do not report successful delivery when cache/DB processing failed.
            abort(503);
        }
    }
}
