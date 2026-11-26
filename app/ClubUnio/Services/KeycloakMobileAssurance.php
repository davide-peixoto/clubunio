<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use Illuminate\Support\Facades\DB;

final class KeycloakMobileAssurance
{
    public static function level(): ?string
    {
        $config = config('clubunio-verein-oidc');
        $level = $config['mobile_mfa_acr'] ?? null;

        return $config['enabled'] && $config['mobile_enabled']
            && ($config['invites_enabled'] || !empty($config['subjects']))
            && is_string($level) && preg_match('/\A[A-Za-z][A-Za-z0-9_.:-]{2,80}\z/', $level) ? $level : null;
    }

    public static function accepts(\stdClass $claims, string $level, int $now): bool
    {
        $time = $claims->auth_time ?? null;

        return ($claims->acr ?? null) === $level && is_int($time) && $time >= $now - 300 && $time <= $now + 10;
    }

    public function stamp(Account $account, ?\stdClass $claims = null): ?string
    {
        $level = self::level();
        if ($level === null || ($claims !== null && (!self::accepts($claims, $level, time()) || !KeycloakLogoutState::accepts($account, $claims)))) {
            return null;
        }
        $issuer = config('clubunio-verein-oidc.issuer');
        $subjects = (array) config('clubunio-verein-oidc.subjects');
        $matches = array_keys($subjects, (int) $account->id, true);
        $subject = count($matches) === 1 ? $matches[0] : null;
        if ($subject === null && config('clubunio-verein-oidc.invites_enabled')) {
            $subject = DB::connection('clubunio')->table('clubunio.keycloak_account_links')
                ->where('issuer', $issuer)->where('account_id', $account->id)->value('subject');
        }
        if (!is_string($subject) || ($claims !== null && ($claims->iss !== $issuer || $claims->sub !== $subject))) {
            return null;
        }

        return hash_hmac('sha256', json_encode(['keycloak-mobile-mfa-v1', $issuer, $subject, $level,
            config('clubunio-verein-oidc.client'), app(AccountSecurity::class)->stamp($account)], JSON_THROW_ON_ERROR),
            (string) config('app.key'));
    }
}
