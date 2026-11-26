<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use Illuminate\Support\Facades\DB;

/** Persistent revocation floor; never infer 'no logout' from an evictable cache. */
final class KeycloakLogoutState
{
    public static function cutoff(Account $actor): ?int
    {
        if (config('clubunio-keycloak-backchannel.enabled') !== true
            && config('clubunio-keycloak-backchannel.state_enforced') !== true) return null;
        $cutoff = DB::connection('clubunio')->table('clubunio.keycloak_logout_watermarks')
            ->where('account_id', $actor->id)->value('cutoff');

        return $cutoff === null ? 0 : (int) $cutoff;
    }

    public static function accepts(Account $actor, \stdClass $claims): bool
    {
        $cutoff = self::cutoff($actor);
        return $cutoff === null || (is_int($claims->iat ?? null) && $claims->iat > $cutoff
            && is_int($claims->auth_time ?? null) && $claims->auth_time > $cutoff);
    }
}
