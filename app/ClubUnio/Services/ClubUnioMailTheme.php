<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\DB;

class ClubUnioMailTheme
{
    public function render(?Tenant $tenant, string $subject, string $body, string $url, string $action, string $locale): string
    {
        $brand = ['name' => 'ClubUnio', 'color' => '#164b8a', 'ink' => '#ffffff', 'logo' => null];
        $custom = $tenant && $this->hasCustomDesign($tenant);
        if ($custom) {
            $design = app(SignageBrandService::class)->design($tenant);
            $logo = null;
            $origin = rtrim(config('app.url'), '/');
            if (parse_url($origin, PHP_URL_SCHEME) === 'https'
                && preg_match('~^/(?:club-logo|images|brand)/[a-zA-Z0-9_/.-]+\.(?:png|jpg|jpeg)$~D', $design['logo'])) {
                $logo = $origin.$design['logo'];
            }
            $brand = ['name' => $tenant->name, 'color' => $design['color'], 'ink' => $design['ink'], 'logo' => $logo];
        }

        return view('clubunio.mail.theme', compact('brand', 'custom', 'subject', 'body', 'url', 'action', 'locale'))->render();
    }

    private function hasCustomDesign(Tenant $tenant): bool
    {
        return in_array($tenant->slug, config('clubunio-domains.hosts', []), true)
            || DB::connection('clubunio')->table('clubunio.tenant_domains')
                ->where('tenant_id', $tenant->id)->where('active', true)
                ->where('ownership_verified', true)->where('dns_verified', true)
                ->where('tls_verified', true)->exists();
    }
}
