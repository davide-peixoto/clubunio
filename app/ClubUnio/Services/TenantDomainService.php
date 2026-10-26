<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Tenant;
use Illuminate\Http\Request;

class TenantDomainService
{
    public function current(): ?Tenant
    {
        return app()->bound('request') ? request()->attributes->get(self::class) : null;
    }

    public function resolve(Request $request): ?Tenant
    {
        $host = strtolower($request->getHost());
        $rawHost = trim(preg_replace('/:\d+$/', '', strtolower($request->headers->get('host', ''))), '[]');
        abort_unless($host === $rawHost, 400, 'Host und weitergeleiteter Host stimmen nicht überein.');
        $hosts = config('clubunio-domains.hosts', []);
        if (array_key_exists($host, $hosts)) {
            abort_unless(preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $host)
                && is_string($hosts[$host]), 404);

            return Tenant::where('slug', $hosts[$host])->firstOrFail();
        }
        $central = config('clubunio-domains.central_hosts', []);
        $central[] = parse_url(config('app.url'), PHP_URL_HOST);
        if (app()->environment('local', 'testing')) {
            $central = [...$central, 'localhost', '127.0.0.1', '::1'];
        }
        if (in_array($host, $central, true)) {
            return null;
        }
        $tenant = Tenant::whereExists(fn ($q) => $q->selectRaw('1')->from('clubora.tenant_domains as domain')
            ->whereColumn('domain.tenant_id', 'clubora.tenants.id')->where('domain.host', $host)
            ->where('domain.active', true)->where('domain.ownership_verified', true)
            ->where('domain.dns_verified', true)->where('domain.tls_verified', true))->first();
        abort_unless($tenant, 404);

        return $tenant;
    }

    public function brand(): ?array
    {
        $tenant = $this->current();
        if (! $tenant) {
            return null;
        }
        $key = self::class.'.brand.'.$tenant->id;
        if (! request()->attributes->has($key)) {
            request()->attributes->set($key, app(SignageBrandService::class)->read($tenant) + ['name' => $tenant->name]);
        }

        return request()->attributes->get($key);
    }
}
