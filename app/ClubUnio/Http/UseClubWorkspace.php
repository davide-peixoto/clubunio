<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Services\ActiveTenant;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class UseClubWorkspace
{
    public function handle(Request $request, Closure $next)
    {
        Filament::setServingStatus(true);
        $active = app(ActiveTenant::class);
        $tenant = Filament::getTenant();

        if (! $request->isMethodSafe()) {
            abort_unless(is_string($request->input('_workspace')), 419, 'Bitte die Seite neu laden.');
            try {
                $context = json_decode(Crypt::decryptString((string) $request->input('_workspace')), true);
            } catch (DecryptException) {
                abort(419, 'Bitte die Seite neu laden.');
            }
            abort_unless(is_array($context), 419);
            $active->assertSnapshot($request, $context);
        }

        $request->attributes->set('clubunioWorkspace', true);
        $request->attributes->set('workspaceToken', Crypt::encryptString(json_encode($request->session()->get($active->key($request)))));
        // Controller argument order remains Request, Tenant, then record identifiers.
        $parameters = $request->route()->parameters();
        foreach (array_keys($parameters) as $key) {
            $request->route()->forgetParameter($key);
        }
        $request->route()->setParameter('tenant', $tenant);
        foreach ($parameters as $key => $value) {
            if ($key !== 'tenant') {
                $request->route()->setParameter($key, $value);
            }
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
