<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Tenant;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ActiveTenant
{
    public function key(Request $request): string
    {
        abort_unless($request->user('clubunio'), 401);

        return 'clubunio_context.'.$request->user('clubunio')->id;
    }

    public function resolve(Request $request): Tenant
    {
        if ($simulation = $request->attributes->get('clubunio_impersonation')) {
            $tenant = Tenant::findOrFail($simulation->tenant_id);
            abort_unless($request->user('clubunio')?->canAccessTenant($tenant), 403);
            return $tenant;
        }
        $actor = $request->user('clubunio');
        abort_unless($actor && $actor->active, 403);
        $context = $request->session()->get($this->key($request));
        abort_unless($context === null || (is_array($context) && isset($context['tenant'], $context['revision'])), 419);
        if ($bound = app(TenantDomainService::class)->current()) {
            abort_unless($actor->fresh()?->canAccessTenant($bound), 403);
            if ($context && ((int) $context['tenant'] !== (int) $bound->id || ($context['host'] ?? null) !== $request->getHost())) {
                abort_unless($request->isMethodSafe(), 419, 'Der Verein oder die Domain wurde gewechselt. Bitte neu laden.');
                $context = null;
            }
            if (! $context) {
                $this->select($request, $bound);
            }

            return $bound;
        }
        if (isset($context['host'])) {
            abort_unless($request->isMethodSafe(), 419, 'Die Domain wurde gewechselt. Bitte neu laden.');
            $tenant = Tenant::findOrFail($context['tenant']);
            $this->select($request, $tenant);
            $context = $request->session()->get($this->key($request));
        }
        if (! $context) {
            $tenant = $actor->getTenants(Filament::getPanel('clubunio'))->sortBy('id')->first();
            abort_unless($tenant, 403);
            $this->select($request, $tenant);
        } else {
            $tenant = Tenant::find($context['tenant']);
            abort_unless($tenant && $actor->canAccessTenant($tenant), 403);
        }

        return $tenant;
    }

    public function select(Request $request, Tenant $tenant): void
    {
        $bound = app(TenantDomainService::class)->current();
        abort_if($bound && ! $bound->is($tenant), 403);
        abort_unless($request->user('clubunio')->canAccessTenant($tenant), 403);
        $context = ['tenant' => $tenant->id, 'revision' => (string) Str::uuid()];
        if ($bound) {
            $context['host'] = $request->getHost();
        }
        $request->session()->put($this->key($request), $context);
    }

    public function assertSnapshot(Request $request, array $context): void
    {
        $current = $request->session()->get($this->key($request));
        abort_unless($current && $context === $current, 419, 'Der Verein wurde gewechselt. Bitte die Seite neu laden.');
        $this->resolve($request);
    }
}
