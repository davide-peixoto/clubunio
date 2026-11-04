<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\TenantDomainService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/** Native-only export origin. Web/service clients keep their existing contract. */
final class MobileSponsorShareIdentity
{
    public function url(Request $request, Tenant $tenant, ?object $link, bool $active): ?string
    {
        if (!$active) return null;
        abort_unless($request->isSecure(), 400);
        // Resolve anew against configured central hosts or verified tenant domains,
        // never a body/header URL supplied by the client.
        $bound = app(TenantDomainService::class)->resolve($request);
        abort_if($bound && !$bound->is($tenant), 404);
        abort_unless($link && (int)$link->tenant_id === (int)$tenant->id && !$link->revoked_at
            && CarbonImmutable::parse($link->expires_at)->isFuture(), 409);
        $token = Crypt::decryptString($link->token_encrypted);
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $token) && hash_equals($link->token_hash, hash('sha256',$token)), 409);
        return $request->getSchemeAndHttpHost().'/sponsor-run#'.$token;
    }
}
