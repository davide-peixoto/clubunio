<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;

/** Web MFA must not depend on enabling app login or issuing invitations. */
final class KeycloakWebAssurance
{
    public static function level(bool $platform = false): ?string
    {
        $level = config($platform ? 'clubunio-platform-oidc.mfa_acr' : 'clubunio-verein-oidc.web_mfa_acr');
        // Retain the explicitly configured pre-existing ACR during upgrades.
        $level ??= config('clubunio-verein-oidc.mobile_mfa_acr');

        return is_string($level) && preg_match('/\A[A-Za-z][A-Za-z0-9_.:-]{2,80}\z/', $level) ? $level : null;
    }

    public static function requiredLevel(bool $platform = false): string
    {
        $level = self::level($platform);
        abort_if($level === null, 503);

        return $level;
    }

    public static function proof(Account $actor, \stdClass $claims, bool $platform = false): array
    {
        $level = self::requiredLevel($platform);
        return \Illuminate\Support\Facades\DB::connection('clubunio')->transaction(function () use ($actor, $claims, $level) {
            $fresh = Account::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($fresh->active && !$fresh->api_only && KeycloakMobileAssurance::accepts($claims, $level, time())
                && KeycloakLogoutState::accepts($fresh, $claims), 403);

            return ['id' => (int) $fresh->id, 'level' => $level, 'auth_time' => $claims->auth_time,
                'issued_at' => time(), 'stamp' => app(AccountSecurity::class)->stamp($fresh)];
        });
    }

    public static function acceptsSession(Account $actor, mixed $proof, bool $platform = false): bool
    {
        $level = self::level($platform);
        return $actor->active && !$actor->api_only && $level !== null && is_array($proof)
            && ($proof['id'] ?? null) === (int) $actor->id && ($proof['level'] ?? null) === $level
            && is_int($proof['issued_at'] ?? null) && $proof['issued_at'] <= time() + 10 && $proof['issued_at'] > time() - 1800
            && is_int($proof['auth_time'] ?? null) && $proof['auth_time'] >= $proof['issued_at'] - 300
            && $proof['auth_time'] <= $proof['issued_at'] + 10 && is_string($proof['stamp'] ?? null)
            && hash_equals(app(AccountSecurity::class)->stamp($actor), $proof['stamp']);
    }
}
