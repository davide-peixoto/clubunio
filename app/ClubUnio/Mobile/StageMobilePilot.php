<?php

namespace App\ClubUnio\Mobile;

use Illuminate\Support\Facades\DB;

final class StageMobilePilot
{
    public const TENANT = 'stage-mobile-pilot';

    public const ACCOUNTS = ['stage-mobile-ios@example.invalid', 'stage-mobile-android@example.invalid'];

    public static function pushPlatform(?string $username): ?string
    {
        return match ($username) {
            self::ACCOUNTS[0] => 'ios',
            self::ACCOUNTS[1] => 'android',
            default => null,
        };
    }

    public static function allowsAccount(string $username): bool
    {
        if (config('app.url') !== 'https://stage.clubunio.com') return false;
        if (in_array($username, self::ACCOUNTS, true)) return true;
        if (!config('clubunio-mobile.public_identity.enabled', false)) return false;

        return DB::connection('clubunio')->table('clubunio.mobile_identity_challenges as proof')
            ->join('clubunio.accounts as account', 'account.id', '=', 'proof.account_id')
            ->join('clubunio.tenants as tenant', 'tenant.id', '=', 'proof.tenant_id')
            ->join('clubunio.tenant_accounts as membership', fn ($join) => $join
                ->on('membership.account_id', 'proof.account_id')->on('membership.tenant_id', 'proof.tenant_id'))
            ->where('tenant.slug', self::TENANT)->where('account.username', $username)
            ->where('account.active', true)->where('account.api_only', false)->where('membership.portal_enabled', true)
            ->where('proof.purpose', 'register')->where('proof.state', 'completed')->exists();
    }
}
