<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\ActiveTenant;
use Illuminate\Http\Request;

class TenantNavigationController
{
    public function switch(Request $request, ActiveTenant $context)
    {
        $data = $request->validate(['tenant' => 'required|integer', 'revision' => 'required|string']);
        $current = $request->session()->get($context->key($request));
        abort_unless($current && hash_equals($current['revision'], $data['revision']), 419);
        $context->select($request, Tenant::findOrFail($data['tenant']));
        // Invalidate old non-Livewire forms as well as signed component contexts.
        $request->session()->regenerateToken();

        return redirect('/');
    }

    public function legacy(Request $request, ActiveTenant $context, string $tenant, ?string $path = null)
    {
        abort_unless($context->resolve($request)->slug === $tenant, 404);
        abort_unless(preg_match('~^[A-Za-z0-9/_-]*$~D', $path ?? ''), 404);
        $target = '/'.ltrim($path ?? '', '/');

        return redirect($target.($request->getQueryString() ? '?'.$request->getQueryString() : ''));
    }
}
