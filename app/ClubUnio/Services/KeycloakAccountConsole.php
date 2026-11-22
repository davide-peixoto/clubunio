<?php

namespace App\ClubUnio\Services;

final class KeycloakAccountConsole
{
    public static function securityUrl(bool $platform = false): ?string
    {
        $console = self::url($platform);

        return $console === null ? null : $console.'account-security/signing-in';
    }

    public static function url(bool $platform = false): ?string
    {
        $config = $platform ? 'clubunio-platform-oidc' : 'clubunio-verein-oidc';
        $issuer = config($config.'.issuer');

        return config($config.'.enabled') && is_string($issuer)
            && preg_match('~\Ahttps://auth\.clubunio\.com/realms/[A-Za-z0-9_-]+\z~', $issuer)
            ? $issuer.'/account/' : null;
    }
}
