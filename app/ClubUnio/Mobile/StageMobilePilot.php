<?php

namespace App\ClubUnio\Mobile;

final class StageMobilePilot
{
    public const TENANT = 'stage-mobile-pilot';

    public const ACCOUNTS = ['stage-mobile-ios@example.invalid', 'stage-mobile-android@example.invalid'];

    public static function allowsAccount(string $username): bool
    {
        return config('app.url') === 'https://stage.clubunio.com'
            && in_array($username, self::ACCOUNTS, true);
    }
}
