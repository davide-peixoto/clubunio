<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Models\{Tenant, TenantAccount};
use App\ClubUnio\Services\{AccountSecurity, TenantDomainService, TenantSecurityRecovery};
use Illuminate\Http\Request;

final class TenantSecurityRecoveryController
{
    public function __invoke(Request $request, int $tenantId, int $linkId, AccountSecurity $security, TenantSecurityRecovery $recovery)
    {
        $security->origin($request);
        $tenant = Tenant::findOrFail($tenantId);
        $bound = app(TenantDomainService::class)->resolve($request);
        abort_if($bound && !$bound->is($tenant), 404);
        $actor = $request->user('clubunio');
        abort_unless($actor && $recovery->operator($actor, $tenant), 403);
        $link = TenantAccount::withoutGlobalScopes()->where('tenant_id', $tenant->id)->with('account')->findOrFail($linkId);
        abort_unless($recovery->allowed($actor, $tenant, $link), 403);
        $code = $request->isMethod('POST') ? $recovery->reset($request, $actor, $tenant, $linkId,
            $request->only(['operator_password', 'reason', 'identity_verified'])) : null;
        return response()->view('clubunio.security.tenant-reset', compact('tenant', 'link', 'code'))
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')
            ->header('Content-Security-Policy', "default-src 'none'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    }
}
