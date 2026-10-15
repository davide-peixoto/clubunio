<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\ActiveTenant;
use App\ClubUnio\Services\BexioConnectionService;
use App\ClubUnio\Services\BexioOidcClient;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BexioConnectionController
{
    public function centralShow(Request $request, Tenant $tenant, BexioConnectionService $service)
    {
        abort_if($request->filled('tenant') && $request->input('tenant') !== $tenant->slug, 403);

        return $this->show($request, $tenant, $service);
    }

    public function centralStart(Request $request, Tenant $tenant, BexioConnectionService $service)
    {
        $data = $request->validate(['tenant' => 'required|string|max:200']);
        abort_unless($data['tenant'] === $tenant->slug, 403);

        return $this->start($request, $tenant, $service);
    }

    public function centralCallback(Request $request, BexioConnectionService $service)
    {
        // Never resolve a callback tenant from query parameters or a later UI selection.
        $pending = $request->session()->pull('bexio_pending.'.$request->user('clubunio')->id);
        abort_unless(is_array($pending) && ($pending['expires'] ?? 0) >= time(), 400);
        $tenant = Tenant::findOrFail($pending['tenant_id']);

        return $this->callback($request, $tenant, $service);
    }

    public function show(Request $request, Tenant $tenant, BexioConnectionService $service)
    {
        $this->authorize($request, $tenant);
        if (! $request->attributes->get('clubunioWorkspace')) {
            $selected = app(ActiveTenant::class)->resolve($request);
            abort_unless($selected->is($tenant), 403);

            return redirect()->route('clubunio.bexio.show');
        }
        $ready = false;
        $connected = false;
        try {
            $config = $service->configuration($tenant);
            $ready = true;
            $connection = $service->connection($tenant);
            $connected = ! empty($connection['refresh_token']) && hash_equals($config['fingerprint'], $connection['fingerprint'] ?? '');
        } catch (\Throwable) {
            // Show configuration state without exposing encrypted content or exceptions.
        }
        $callback = $this->callbackUrl($tenant);

        return response()->view('clubunio.bexio.connection', compact('tenant', 'ready', 'connected', 'callback'))
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function start(Request $request, Tenant $tenant, BexioConnectionService $service)
    {
        $this->authorize($request, $tenant);
        $prefix = $this->prefix($request, $tenant);
        $request->session()->forget($prefix);
        try {
            $config = $service->configuration($tenant);
            $callback = $this->callbackUrl($tenant);
            $request->session()->put($prefix.'.context', ['expires' => time() + 600, 'callback' => $callback, 'fingerprint' => $config['fingerprint']]);
            $request->session()->put('bexio_pending.'.$request->user('clubunio')->id, ['tenant_id' => $tenant->id, 'expires' => time() + 600]);
            $this->client($request, $config, $prefix, $callback)->run([]);
            throw new \RuntimeException('Authorization did not redirect');
        } catch (HttpResponseException $redirect) {
            return $redirect->getResponse()->header('Cache-Control', 'private, no-store');
        } catch (\Throwable $error) {
            $request->session()->forget($prefix);

            return $this->failure($tenant, $error);
        }
    }

    public function callback(Request $request, Tenant $tenant, BexioConnectionService $service)
    {
        $this->authorize($request, $tenant);
        $prefix = $this->prefix($request, $tenant);
        try {
            $query = $request->validate(['state' => 'required|string|max:256', 'code' => 'nullable|string|max:8192', 'error' => 'nullable|string|max:256']);
            $context = $request->session()->get($prefix.'.context');
            if (! is_array($context) || ($context['expires'] ?? 0) < time()) {
                throw new \RuntimeException('Expired authorization', 400);
            }
            $config = $service->configuration($tenant);
            if (! hash_equals($config['fingerprint'], $context['fingerprint'])) {
                throw new \RuntimeException('Changed configuration', 409);
            }
            $client = $this->client($request, $config, $prefix, $context['callback']);
            // Validate before any code exchange (the upstream library checks it afterwards).
            if (! $client->matchesState($query['state']) || ! empty($query['error']) || empty($query['code'])) {
                throw new \RuntimeException('Invalid or denied authorization', 400);
            }
            if (! $client->run($query)) {
                throw new \RuntimeException('Incomplete authorization', 400);
            }
            $service->connect($tenant, (array) $client->getTokenResponse(), $config['fingerprint'], $request->user('clubunio')->id);

            return redirect()->route('clubunio.bexio.show')->with('notice', 'Bexio wurde für '.$tenant->name.' mit ClubUnio verbunden.');
        } catch (\Throwable $error) {
            return $this->failure($tenant, $error);
        } finally {
            $request->session()->forget($prefix);
        }
    }

    protected function client(Request $request, array $config, string $prefix, string $callback): BexioOidcClient
    {
        $client = new BexioOidcClient($config['id'], $config['secret'], $request->session(), $prefix);
        $client->setRedirectURL($callback);
        $client->addScope($config['scopes']);

        return $client;
    }

    private function authorize(Request $request, Tenant $tenant): void
    {
        abort_unless(BexioConnectionService::canManage($request->user('clubunio'), $tenant), 403);
    }

    private function prefix(Request $request, Tenant $tenant): string
    {
        return 'bexio_oidc.'.$tenant->id.'.'.$request->user('clubunio')->id;
    }

    private function callbackUrl(Tenant $tenant): string
    {
        return rtrim(config('app.url'), '/').route('clubunio.bexio.callback', [], false);
    }

    private function failure(Tenant $tenant, \Throwable $error)
    {
        $reference = (string) Str::uuid();
        Log::warning('clubunio.bexio.connection_failed', ['tenant_id' => $tenant->id, 'reference' => $reference, 'status' => (int) $error->getCode()]);

        return redirect()->route('clubunio.bexio.show')->with('connection_error', 'Bexio konnte für '.$tenant->name.' nicht verbunden werden. Bitte Zugang und freigegebene Callback-Adresse prüfen. Referenz: '.$reference)
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
