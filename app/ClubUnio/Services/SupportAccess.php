<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Account, Tenant};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SupportAccess
{
    public function role(Request $request, Account $actor, Tenant $tenant): string
    {
        $this->realAccount($request, $actor);
        $bound = app(TenantDomainService::class)->resolve($request);
        abort_if($bound && !$bound->is($tenant), 404);
        abort_unless(app(MemberAccessService::class)->canEnter($actor, $tenant)
            || app(ModulePermissionService::class)->canEnter($actor, $tenant), 403);

        return (string) DB::connection('clubunio')->table('clubora.tenant_accounts')
            ->where('tenant_id', $tenant->id)->where('account_id', $actor->id)->value('role');
    }

    public function platform(Request $request, Account $actor): void
    {
        $this->realAccount($request, $actor);
        abort_if(app(TenantDomainService::class)->resolve($request), 404);
        app(PlatformAdministrationService::class)->authorize($actor);
    }

    public function realAccount(Request $request, Account $actor): void
    {
        abort_if($request->attributes->has('clubunio_impersonation')
            || ($request->hasSession() && $request->session()->has(ImpersonationService::KEY)), 403);
        abort_unless(Account::whereKey($actor->id)->where('active', true)->where('api_only', false)->exists(), 403);
    }

    public function staff(string $role): bool
    {
        return in_array($role, ModulePermissionService::FULL_BACKOFFICE_ROLES, true);
    }

    public function tickets(int $tenant, int $actor, bool $staff)
    {
        return DB::connection('clubunio')->table('clubora.support_tickets')->where('audience', 'club')
            ->where('tenant_id', $tenant)->when(!$staff, fn ($q) => $q->where('requester_id', $actor));
    }

    public function articles(?int $tenant, bool $staff, bool $platform, string $locale)
    {
        return DB::connection('clubunio')->table('clubora.support_articles')
            ->where('published', true)->where('locale', $locale)
            ->where(function ($query) use ($tenant, $staff, $platform) {
                $query->where('audience', 'public');
                if ($platform) $query->orWhere('audience', 'platform');
                if ($tenant !== null) {
                    $query->orWhere(fn ($club) => $club->where('tenant_id', $tenant)
                        ->whereIn('audience', $staff ? ['club', 'club_staff'] : ['club']));
                }
            });
    }
}
