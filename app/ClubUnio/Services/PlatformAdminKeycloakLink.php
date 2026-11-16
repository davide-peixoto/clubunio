<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;

final class PlatformAdminKeycloakLink
{
    public static function configured(): bool
    {
        return config('clubunio-platform-oidc.enabled') && KeycloakMobileAssurance::level() !== null
            && config('clubunio-platform-oidc.origin') === config('clubunio-verein-oidc.origin');
    }

    public function resolve(\stdClass $claims): ?Account
    {
        $level = KeycloakMobileAssurance::level();
        if (!self::configured() || $level === null
            || !self::matchesMfa($claims, config('clubunio-verein-oidc.issuer'), $level, time())) {
            return null;
        }
        // This allowlist is separate from both the legacy platform mapping and
        // tenant identity links. Only explicit, operator-reviewed entries qualify.
        $links = config('clubunio-platform-oidc.migrated_subjects', []);
        $id = is_array($links) ? ($links[$claims->sub] ?? null) : null;
        $actor = is_int($id) && $id > 0 ? Account::find($id) : null;
        if (!app(PlatformAdministrationService::class)->allowed($actor)
            || !hash_equals(mb_strtolower(trim($actor->username)), mb_strtolower(trim($claims->email)))) {
            return null;
        }

        return $actor;
    }

    public static function matchesMfa(\stdClass $claims, string $issuer, string $level, int $now): bool
    {
        return ($claims->iss ?? null) === $issuer && is_string($claims->sub ?? null) && $claims->sub !== ''
            && ($claims->email_verified ?? false) === true && is_string($claims->email ?? null)
            && KeycloakMobileAssurance::accepts($claims, $level, $now);
    }
}
