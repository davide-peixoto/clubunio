<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\{KeycloakAccountConsole, MemberAccessService, TenantDomainService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PersonalProfileController
{
    public function portal(Request $request, MemberAccessService $access)
    {
        $actor = $this->personalAccount($request, 'portal');
        $bound = $request->attributes->get(TenantDomainService::class);
        $memberships = DB::connection('clubunio')->table('clubora.tenant_accounts')->where('account_id', $actor->id);
        $tenants = Tenant::whereIn('id', $memberships->select('tenant_id'))
            ->when($bound instanceof Tenant, fn ($query) => $query->whereKey($bound->id))->orderBy('name')->get()
            ->filter(fn ($tenant) => $access->canEnter($actor, $tenant));
        $club = $request->query('club');
        $tenant = (is_string($club) ? $tenants->firstWhere('slug', $club) : null) ?? $tenants->first();

        return response()->view('clubunio.profile.portal', [
            'actor' => $actor, 'tenants' => $tenants, 'tenant' => $tenant,
            'accountConsole' => KeycloakAccountConsole::securityUrl(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function platform(Request $request)
    {
        $actor = $this->personalAccount($request, PlatformOidcController::guard($request));

        return response()->view('clubunio.profile.platform', [
            'actor' => $actor, 'accountConsole' => KeycloakAccountConsole::securityUrl(platform: true),
        ])->header('Cache-Control', 'private, no-store');
    }

    private function personalAccount(Request $request, string $guard): Account
    {
        $actor = $request->user($guard);
        abort_unless($actor instanceof Account && $actor->active && !$actor->api_only
            && !$request->attributes->has('clubunio_impersonation'), 403);

        return $actor;
    }
}
