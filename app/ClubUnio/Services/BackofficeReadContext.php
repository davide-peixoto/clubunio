<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class BackofficeReadContext
{
    public function during(Request $request, Closure $next): mixed
    {
        if (! $request->isMethod('GET')) {
            return $next($request);
        }
        $actor = $request->user('clubunio')?->fresh();
        abort_unless($actor?->active, 403);
        $previous = $request->attributes->get(self::class);
        $request->attributes->set(self::class, ['account' => $actor->id,
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
        if (! app()->bound('request') || ! request()->isMethod('GET')) {
            return null;
        }
        $context = request()->attributes->get(self::class);

        return $actor->active && $context && (int) $context['account'] === (int) $actor->id ? $context['tenants'] : null;
    }
}
