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
        $actor = $request->user('clubunio');
        abort_unless($actor && $actor->active, 403);
        $context = $request->session()->get($this->key($request));
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
        abort_unless($request->user('clubunio')->canAccessTenant($tenant), 403);
        $request->session()->put($this->key($request), ['tenant' => $tenant->id, 'revision' => (string) Str::uuid()]);
    }

    public function assertSnapshot(Request $request, array $context): void
    {
        $current = $request->session()->get($this->key($request));
        abort_unless($current && $context === $current, 419, 'Der Verein wurde gewechselt. Bitte die Seite neu laden.');
        $this->resolve($request);
    }
}
