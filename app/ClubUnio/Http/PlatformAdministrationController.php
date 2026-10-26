<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Models\AccessProfile;
use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\AccountRole;
use App\ClubUnio\Models\Tenant;
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
        if ($service->allowed($request->user('clubunio'))) {
            return redirect()->route('clubunio.platform.index');
        }

        return response()->view('clubunio.platform.login')->header('Cache-Control', 'private, no-store');
    }

    public function login(Request $request, PlatformAdministrationService $service)
    {
        abort_if(app(TenantDomainService::class)->resolve($request), 404);
        $data = $request->validate(['username' => 'required|string|max:255', 'password' => 'required|string|max:255']);
        $actor = Account::whereRaw('lower(username) = ?', [mb_strtolower(trim($data['username']))])->first();
        if (! $actor || ! Hash::check($data['password'], $actor->password) || ! $service->allowed($actor)) {
            throw ValidationException::withMessages(['username' => 'Anmeldung nicht möglich.']);
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

        return view('clubunio.platform.index', ['rows' => $rows, 'q' => $data['q'] ?? '', 'actor' => $request->user('clubunio')]);
    }

    public function create(Request $request, PlatformAdministrationService $service)
    {
        $tenant = $service->create($request->user('clubunio'), $request->except('_token'));

        return redirect()->route('clubunio.platform.show', $tenant->id)->with('notice', 'Verein erstellt.');
    }

    public function createAccount(Request $request, PlatformAdministrationService $service)
    {
        $service->createAccount($request->user('clubunio'), $request->except('_token'));

        return redirect()->route('clubunio.platform.index')->with('notice', 'Persönliches Konto erstellt.');
    }

    public function show(Request $request, int $id)
    {
        $tenant = Tenant::findOrFail($id);
        $db = DB::connection('clubunio');
        $accounts = $db->table('clubora.tenant_accounts as m')->join('clubora.accounts as a', 'a.id', '=', 'm.account_id')
            ->where('m.tenant_id', $id)->where('a.api_only', false)->orderBy('a.display_name')->limit(201)
            ->get(['a.username', 'a.display_name', 'a.active', 'm.role', 'm.portal_enabled', 'm.access_profile_id']);

        return view('clubunio.platform.show', ['tenant' => $tenant, 'actor' => $request->user('clubunio'),
            'brand' => app(SignageBrandService::class)->read($tenant), 'accounts' => $accounts,
            'roles' => AccountRole::where('active', true)->orderBy('sort_order')->get(),
            'profiles' => AccessProfile::where('tenant_id', $id)->where('active', true)->orderBy('name')->get(['id', 'name']),
            'domains' => $db->table('clubora.tenant_domains')->where('tenant_id', $id)->orderBy('host')->get(),
            'changes' => $db->table('clubora.platform_changes as c')->join('clubora.accounts as a', 'a.id', '=', 'c.actor_id')
                ->where('c.tenant_id', $id)->orderByDesc('c.id')->limit(30)->get(['c.action', 'c.details', 'c.created_at', 'a.display_name'])]);
    }

    public function change(Request $request, int $id, PlatformAdministrationService $service)
    {
        $service->change($request->user('clubunio'), $id, $request->except('_token'));

        return redirect()->route('clubunio.platform.show', $id)->with('notice', 'Änderung gespeichert.');
    }

    public function logout(Request $request)
    {
        Auth::guard('clubunio')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('clubunio.platform.login');
    }
}
