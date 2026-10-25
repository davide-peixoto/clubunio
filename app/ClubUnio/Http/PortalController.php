<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Models\MemberStatus;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\MemberAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class PortalController
{
    public function loginForm()
    {
        return response()->view('clubunio.portal.login')->header('Cache-Control', 'private, no-store');
    }

    public function login(Request $request, MemberAccessService $access)
    {
        $data = $request->validate(['username' => 'required|string|max:255', 'password' => 'required|string|max:4096']);
        $username = mb_strtolower(trim($data['username']));
        $keys = ['portal-login:'.hash('sha256', $request->ip().'|'.$username) => 5,
            'portal-login-ip:'.hash('sha256', $request->ip()) => 30];
        foreach ($keys as $key => $limit) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                throw ValidationException::withMessages(['username' => 'Zu viele Versuche. Bitte in einer Minute erneut versuchen.']);
            }
            RateLimiter::hit($key, 60);
        }
        $guard = Auth::guard('portal');
        if ($guard->attempt(['username' => $username, 'password' => $data['password'], 'active' => true, 'api_only' => false])) {
            $actor = $guard->user();
            $tenants = $actor->tenants()->get()->filter(fn ($tenant) => $access->canEnter($actor, $tenant));
            if ($tenants->isNotEmpty()) {
                $request->session()->regenerate();
                RateLimiter::clear(array_key_first($keys));

                return redirect()->route('clubunio.portal.members', ['tenant' => $tenants->first()->slug]);
            }
            $guard->logout();
        }
        throw ValidationException::withMessages(['username' => 'Anmeldung nicht möglich. Zugangsdaten oder Mitgliedsfreigabe prüfen.']);
    }

    public function members(Request $request, Tenant $tenant, MemberAccessService $access)
    {
        $actor = $request->user('portal');
        abort_unless($access->canEnter($actor, $tenant), 403);
        $data = $request->validate(['q' => 'nullable|string|max:120', 'status' => 'nullable|integer']);
        $q = trim($data['q'] ?? '');
        $query = $access->visibleMembers($actor, $tenant);
        $members = $query->select(['clubora.members.id', 'first_name', 'last_name', 'company', 'city', 'email', 'mobile', 'status_id'])
            ->with('status:id,name')->when($q !== '', fn ($query) => $query->where(fn ($query) => $query->where('first_name', 'ilike', '%'.$q.'%')
            ->orWhere('last_name', 'ilike', '%'.$q.'%')->orWhere('company', 'ilike', '%'.$q.'%')))
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status_id', $status))
            ->orderBy('last_name')->orderBy('first_name')->orderBy('id')->paginate(25)->withQueryString();
        $tenants = $actor->tenants()->get()->filter(fn ($t) => $access->canEnter($actor, $t));
        $statuses = MemberStatus::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true)->orderBy('sort_order')->get(['id', 'name']);
        $editableMemberIds = $access->represented($actor, $tenant)->pluck('clubora.members.id')->all();

        return response()->view('clubunio.portal.members', compact('tenant', 'tenants', 'members', 'q', 'statuses', 'editableMemberIds'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function logout(Request $request)
    {
        Auth::guard('portal')->logout();
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return redirect()->route('clubunio.portal.login');
    }
}
