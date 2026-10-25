<?php

namespace App\ClubUnio\Http;

use Closure;
use Illuminate\Http\Request;

final class ProtectApiAdminResponse
{
    public function handle(Request $request, Closure $next)
    {
        $protected = $request->is('api-integrations') || $request->routeIs('filament.clubunio.pages.api-integrations');
        foreach ($request->is('livewire/*') ? (array) $request->input('components', []) : [] as $component) {
            $snapshot = is_array($component) && is_string($component['snapshot'] ?? null)
                ? json_decode($component['snapshot'], true) : null;
            $memo = is_array($snapshot) && is_array($snapshot['memo'] ?? null) ? $snapshot['memo'] : [];
            $name = is_string($memo['name'] ?? null) ? $memo['name'] : '';
            $protected = $protected || ($memo['path'] ?? null) === 'api-integrations'
                || str_ends_with($name, '.api-integrations');
        }
        $response = $next($request);
        if ($protected) {
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->set('Referrer-Policy', 'no-referrer');
        }

        return $response;
    }
}
