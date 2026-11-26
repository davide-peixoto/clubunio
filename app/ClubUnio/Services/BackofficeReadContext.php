<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class BackofficeReadContext
{
    // Page visibility only: actions must retain their own fresh authorization.
    public function allowsReadPage(?Account $actor, ?Tenant $tenant, bool $adminOnly = false): bool
    {
        if (! $actor?->active || ! $tenant) {
            return false;
        }
        $tenants = $this->tenantsFor($actor);
        if ($tenants !== null) {
            return $tenants->contains(fn ($item) => (int) $item->id === (int) $tenant->id
                && (! $adminOnly || $item->pivot->role === 'admin'));
        }
        $fresh = $actor->fresh();

        return $adminOnly ? BexioConnectionService::canManage($fresh, $tenant)
            : ($fresh?->hasUnrestrictedBackofficeAccess($tenant) ?? false);
    }

    public function during(Request $request, Closure $next): mixed
    {
        if (! $request->isMethod('GET')) {
            return $next($request);
        }

        return $this->snapshot($request, $next);
    }

    public function duringRender(Closure $next): mixed
    {
        $request = request();
        if (! $request->isMethod('POST') || ! $request->user('clubunio')) {
            return $next();
        }

        return $this->snapshot($request, $next);
    }

    public function withoutSnapshot(Closure $next): mixed
    {
        $request = request();
        if (! $request->isMethod('POST')) {
            return $next();
        }
        $previous = $request->attributes->get(self::class);
        $request->attributes->remove(self::class);
        try {
            return $next();
        } finally {
            if ($previous !== null) {
                $request->attributes->set(self::class, $previous);
            }
        }
    }

    private function snapshot(Request $request, Closure $next): mixed
    {
        $actor = $request->user('clubunio')?->fresh();
        abort_unless($actor?->active, 403);
        $previous = $request->attributes->get(self::class);
        $request->attributes->set(self::class, ['account' => $actor->id,
            'permissionCache' => [],
            'tenants' => $actor->tenants()->wherePivotIn('role', ['admin', 'office'])->get()]);
        try {
            return $next($request);
        } finally {
            // Never retain a role snapshot for a later request, Livewire mutation or worker job.
            if ($previous === null) {
                $request->attributes->remove(self::class);
            } else {
                $request->attributes->set(self::class, $previous);
            }
        }
    }

    public function tenantsFor(Account $actor): ?Collection
    {
        if (! app()->bound('request')) {
            return null;
        }
        $context = request()->attributes->get(self::class);

        return $actor->active && $context && (int) $context['account'] === (int) $actor->id ? $context['tenants'] : null;
    }

    public function permissionCache(Account $actor, string $key, ?array $value = null): ?array
    {
        if ($this->tenantsFor($actor) === null) {
            return null;
        }
        $context = request()->attributes->get(self::class);
        if ($value !== null) {
            $context['permissionCache'][$key] = $value;
            request()->attributes->set(self::class, $context);
        }
        return $context['permissionCache'][$key] ?? null;
    }
}
