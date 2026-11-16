<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Models\AccessProfile;
use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\AccountRole;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\AccountSecurity;
use App\ClubUnio\Services\PlatformAdministrationService;
use App\ClubUnio\Services\SignageBrandService;
use App\ClubUnio\Services\TenantDomainService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class PlatformAdministrationController
{
    public function loginForm(Request $request, PlatformAdministrationService $service)
    {
        abort_if(app(TenantDomainService::class)->resolve($request), 404);
        if (PlatformOidcController::required() && !$request->user('platform')) {
            return redirect()->route('clubunio.platform.oidc.start');
        }
        if ($service->allowed($request->user(PlatformOidcController::guard($request)))) {
            return redirect()->route('clubunio.platform.index');
        }

        return response()->view('clubunio.platform.login')->header('Cache-Control', 'private, no-store');
    }

    public function login(Request $request, PlatformAdministrationService $service)
    {
        abort_if(PlatformOidcController::required(), 404);
        abort_if(app(TenantDomainService::class)->resolve($request), 404);
        $data = $request->validate(['username' => 'required|string|max:255', 'password' => 'required|string|max:255']);
        $actor = Account::whereRaw('lower(username) = ?', [mb_strtolower(trim($data['username']))])->first();
        if (! $actor || ! Hash::check($data['password'], $actor->password) || ! $service->allowed($actor)) {
            throw ValidationException::withMessages(['username' => __('Anmeldung nicht möglich.')]);
        }
        $security = app(AccountSecurity::class);
        if ($security->requiresStrongLogin($actor)) {
            return redirect()->route('clubunio.security.show', ['mode' => 'platform', 'setup' => $security->mustEnroll($actor) ? '1' : null]);
        }
        Auth::guard('clubunio')->login($actor);
        $request->session()->regenerate();

        return redirect()->route('clubunio.platform.index');
    }

    public function index(Request $request)
    {
        $data = $request->validate(['q' => 'nullable|string|max:100']);
        $rows = Tenant::query()->when(trim($data['q'] ?? '') !== '', function ($q) use ($data) {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($data['q'])).'%';
            $q->where(fn ($q) => $q->where('name', 'ilike', $term)->orWhere('slug', 'ilike', $term));
        })->withCount('tenantAccounts')->orderBy('name')->orderBy('id')->simplePaginate(30)->withQueryString();

        $metrics = app(\App\ClubUnio\Services\PlatformTenantMetrics::class)->overview($rows->getCollection()->modelKeys());

        return view('clubunio.platform.index', ['rows' => $rows, 'metrics' => $metrics, 'q' => $data['q'] ?? '', 'actor' => $request->user(PlatformOidcController::guard($request))]);
    }

    public function create(Request $request, PlatformAdministrationService $service)
    {
        $tenant = $service->create($request->user(PlatformOidcController::guard($request)), $request->except('_token'));

        return redirect()->route('clubunio.platform.show', $tenant->id)->with('notice', __('Verein erstellt.'));
    }

    public function createAccount(Request $request, PlatformAdministrationService $service)
    {
        $service->createAccount($request->user(PlatformOidcController::guard($request)), $request->except('_token'));

        return redirect()->route('clubunio.platform.index')->with('notice', __('Persönliches Konto erstellt.'));
    }

    public function platformUsers(Request $request)
    {
        $actor = $request->user(PlatformOidcController::guard($request));
        $rows = DB::connection('clubunio')->table('clubora.platform_admins as p')
            ->join('clubora.accounts as a', 'a.id', '=', 'p.account_id')->where('a.api_only', false)
            ->orderBy('a.display_name')->orderBy('a.id')
            ->paginate(30, ['a.id', 'a.username', 'a.display_name', 'a.active', 'p.active as platform_active']);
        $mappedIds = array_merge(array_values((array) config('clubunio-platform-oidc.subjects')),
            array_values((array) config('clubunio-platform-oidc.migrated_subjects')));

        return response()->view('clubunio.platform.users', compact('actor', 'rows', 'mappedIds'))->header('Cache-Control', 'no-store');
    }

    public function createPlatformUser(Request $request, PlatformAdministrationService $service)
    {
        $service->createAccount($request->user(PlatformOidcController::guard($request)), $request->except('_token'), true);

        return redirect()->route('clubunio.platform.users')->with('notice', __('Persönliches Konto erstellt.'));
    }

    public function changePlatformUser(Request $request, int $account, PlatformAdministrationService $service)
    {
        $service->updatePlatformAccount($request->user(PlatformOidcController::guard($request)), $account, $request->except('_token'));

        return redirect()->route('clubunio.platform.users')->with('notice', __('Änderung gespeichert.'));
    }

    public function show(Request $request, int $id)
    {
        $tenant = Tenant::findOrFail($id);
        $db = DB::connection('clubunio');
        $accounts = $db->table('clubora.tenant_accounts as m')->join('clubora.accounts as a', 'a.id', '=', 'm.account_id')
            ->where('m.tenant_id', $id)->where('a.api_only', false)->orderBy('a.display_name')->limit(201)
            ->get(['a.id', 'a.username', 'a.display_name', 'a.active', 'm.role', 'm.portal_enabled', 'm.access_profile_id']);

        $migration = app(\App\ClubUnio\Services\PlatformKeycloakMigration::class);
        $migrationRows = $migration::enabled() && $accounts->isNotEmpty()
            ? collect($migration->preview($id, $accounts->take(200)->pluck('id')->map(fn ($key) => (int) $key)->all()))->keyBy('id')
            : collect();

        return view('clubunio.platform.show', ['tenant' => $tenant, 'actor' => $request->user(PlatformOidcController::guard($request)),
            'metrics' => app(\App\ClubUnio\Services\PlatformTenantMetrics::class)->overview([$id])[$id],
            'syncOperations' => app(\App\ClubUnio\Services\PlatformTenantMetrics::class)->operations($id),
            'brand' => app(SignageBrandService::class)->read($tenant), 'accounts' => $accounts, 'migrationRows' => $migrationRows,
            'storageProfile' => app(\App\ClubUnio\Services\TenantDocumentStorage::class)->active($tenant),
            'storageDestinations' => app(\App\ClubUnio\Services\TenantDocumentStorage::class)->destinations($tenant),
            'roles' => AccountRole::where('active', true)->orderBy('sort_order')->get(),
            'profiles' => AccessProfile::where('tenant_id', $id)->where('active', true)->orderBy('name')->get(['id', 'name']),
            'domains' => $db->table('clubora.tenant_domains')->where('tenant_id', $id)->orderBy('host')->get(),
            'changes' => $db->table('clubora.platform_changes as c')->join('clubora.accounts as a', 'a.id', '=', 'c.actor_id')
                ->where('c.tenant_id', $id)->orderByDesc('c.id')->limit(30)->get(['c.action', 'c.details', 'c.created_at', 'a.display_name'])]);
    }

    public function keycloakMigration(Request $request, int $id, \App\ClubUnio\Services\PlatformKeycloakMigration $migration)
    {
        abort_unless($migration::enabled(), 404);
        $actor = $request->user(PlatformOidcController::guard($request));
        $admins = app(PlatformAdministrationService::class);
        $admins->authorize($actor);
        $tenant = Tenant::findOrFail($id);
        if (!$request->boolean('confirm')) {
            $data = $request->validate(['account_ids' => 'required_without:account_id|array|min:1|max:10',
                'account_ids.*' => 'required|integer|min:1|distinct', 'account_id' => 'sometimes|integer|min:1']);
            $ids = isset($data['account_id']) ? [(int) $data['account_id']] : array_map('intval', $data['account_ids']);
            $rows = $migration->preview($id, $ids);
            $previewToken = bin2hex(random_bytes(24));
            $request->session()->put('platform.keycloak.preview', ['tenant' => $id, 'actor' => (int) $actor->id,
                'rows' => $rows, 'expires' => time() + 600, 'token' => $previewToken]);

            return response()->view('clubunio.platform.keycloak-migration', compact('tenant', 'actor', 'rows', 'previewToken'))
                ->header('Cache-Control', 'no-store');
        }
        $preview = $request->session()->pull('platform.keycloak.preview');
        abort_unless($preview && $preview['tenant'] === $id && $preview['actor'] === (int) $actor->id && $preview['expires'] >= time()
            && is_string($request->input('preview_token')) && hash_equals($preview['token'], $request->input('preview_token')), 409);
        $rows = [];
        foreach ($preview['rows'] as $row) {
            $admins->authorize($actor);
            try {
                $row['status'] = $migration->send($id, $row);
            } catch (\Exception) {
                $row['status'] = 'failed';
            }
            $rows[] = $row;
        }
        return response()->view('clubunio.platform.keycloak-migration', compact('tenant', 'actor', 'rows') + ['sent' => true])
            ->header('Cache-Control', 'no-store');
    }

    public function change(Request $request, int $id, PlatformAdministrationService $service)
    {
        $service->change($request->user(PlatformOidcController::guard($request)), $id, $request->except('_token'));

        return redirect()->route('clubunio.platform.show', $id)->with('notice', __('Änderung gespeichert.'));
    }

    public function logo(int $id, string $version, \App\ClubUnio\Services\TenantLogoService $logos)
    {
        return $logos->response($id, $version);
    }

    public function logout(Request $request)
    {
        if (PlatformOidcController::guard($request) === 'platform') {
            Auth::guard('platform')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return KeycloakLogout::redirect('clubunio-platform-oidc');
        }
        Auth::guard(PlatformOidcController::guard($request))->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('clubunio.platform.login');
    }
}
