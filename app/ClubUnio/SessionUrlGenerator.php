<?php

namespace App\ClubUnio;

use App\ClubUnio\Models\Tenant;
use Filament\Facades\Filament;
use Illuminate\Routing\UrlGenerator;

class SessionUrlGenerator extends UrlGenerator
{
    public function toRoute($route, $parameters, $absolute)
    {
        if (preg_match('/^clubunio\.(bus|planning|training)\./', $route->getName() ?? '')
            && request()->hasSession() && request()->user('clubunio')) {
            $tenant = Filament::getTenant();
            if (! $tenant) {
                $context = request()->session()->get('clubunio_context.'.request()->user('clubunio')->id);
                $tenant = $context ? Tenant::find($context['tenant']) : null;
            }
            $values = is_array($parameters) ? $parameters : [$parameters];
            $key = array_key_exists('tenant', $values) ? 'tenant' : 0;
            $supplied = $values[$key] ?? null;
            if ($supplied instanceof Tenant) {
                $supplied = $supplied->slug;
            }
            if ($tenant && $supplied === $tenant->slug && (request()->attributes->get('clubunioWorkspace') || request()->user('clubunio')->canAccessTenant($tenant))) {
                unset($values[$key]);
                $route = $this->routes->getByName(str_replace('clubunio.', 'clubunio.workspace.', $route->getName()));
                $parameters = array_merge($values);
            }
        }
        // Filament still supplies tenant for model scopes. It is no longer a URL parameter.
        if (str_starts_with($route->getName() ?? '', 'filament.clubunio.') && is_array($parameters)) {
            unset($parameters['tenant']);
        }

        return parent::toRoute($route, $parameters, $absolute);
    }
}
