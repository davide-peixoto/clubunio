<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\{PublicWebService, TenantDomainService};
use Illuminate\Http\Request;

final class MobilePublicIdentityPolicy
{
    public function origin(Request $request): string
    {
        $origin = $request->getSchemeAndHttpHost();
        $this->authorizeOrigin($origin);
        return $origin;
    }
    public function authorizeOrigin(string $origin): void
    {
        $parts = parse_url($origin);
        abort_unless(is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host'])
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['path']) && !isset($parts['query']) && !isset($parts['fragment'])
            && in_array($origin, config('clubunio-mobile.public_identity.origins', []), true), 503, 'Öffentliche Anmeldung noch nicht freigeschaltet.');
    }
    public function bound(string $origin): ?Tenant
    {
        $this->authorizeOrigin($origin);
        return app(TenantDomainService::class)->resolve(Request::create($origin));
    }
    public function tenant(string $origin, int $id): Tenant
    {
        $bound = $this->bound($origin);
        abort_if($bound && (int) $bound->id !== $id, 404);
        return Tenant::whereKey($id)->whereIn('slug', config('clubunio-mobile.public_identity.tenant_slugs', []))->firstOrFail();
    }
    public function clubs(string $origin): array
    {
        $bound = $this->bound($origin);
        return Tenant::whereIn('slug', config('clubunio-mobile.public_identity.tenant_slugs', []))
            ->when($bound, fn ($q) => $q->whereKey($bound->id))->orderBy('name')->limit(100)->get(['id','name'])
            ->map(fn ($tenant) => ['id' => (int) $tenant->id, 'name' => $tenant->name])->all();
    }
    public function enabled(Tenant $tenant, string $surface): bool
    {
        if (!config('clubunio-mobile.public_identity.enabled', false) || !in_array($tenant->slug, config('clubunio-mobile.public_identity.tenant_slugs', []), true)) { return false; }
        if ($surface === 'registration' && !app(MobileBootstrapState::class)->registrationActive($tenant)) { return false; }
        if ($surface === 'membership') {
            return config('clubunio-mobile.public_identity.membership_enabled', false) && app(PublicWebService::class)->enabled($tenant, 'registration');
        }
        return in_array($surface, ['registration','password_reset','email_enrollment'], true) && config('clubunio-mobile.public_identity.'.$surface.'_enabled', false)
            && app(MobileIdentityMailGuard::class)->configured();
    }
    public function authorize(Tenant $tenant, string $surface): void { abort_unless($this->enabled($tenant, $surface), 503, 'Dieser öffentliche Ablauf ist noch nicht freigeschaltet.'); }
}
