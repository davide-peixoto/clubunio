<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\TenantAccount;
use App\ClubUnio\Services\AccountAdministrationService;
use App\ClubUnio\Services\ActiveTenant;
use App\ClubUnio\Services\ImpersonationService;
use Illuminate\Http\Request;

class ImpersonationController
{
    public function index(Request $request, ImpersonationService $service)
    {
        $tenant = app(ActiveTenant::class)->resolve($request);
        abort_unless(app(AccountAdministrationService::class)->allowed($request->user('clubunio'), $tenant), 403);
        return response()->view('clubunio.support.index', ['tenant' => $tenant,
            'accounts' => TenantAccount::where('tenant_id', $tenant->id)->with('account')->get(),
            'grants' => $service->table('grants')->where('tenant_id', $tenant->id)->get()->keyBy('account_id'),
            'allowed' => $service->allowed($request->user('clubunio'), $tenant),
            'events' => $service->table('events')->where('tenant_id', $tenant->id)->orderByDesc('id')->limit(50)->get()])->header('Cache-Control', 'private, no-store');
    }

    public function start(Request $request, ImpersonationService $service)
    {
        $data = $request->validate(['target_id' => 'required|integer', 'reason' => 'required|string|min:10|max:500', 'password' => 'required|string|max:200']);
        $actor = $request->user('clubunio');
        $service->password($actor, $data['password']);
        $url = $service->start($request, $actor, app(ActiveTenant::class)->resolve($request), Account::findOrFail($data['target_id']), $data['reason']);
        return redirect($url, 303);
    }

    public function grant(Request $request, ImpersonationService $service)
    {
        $data = $request->validate(['target_id' => 'required|integer', 'enabled' => 'required|boolean', 'revision' => 'required|integer|min:0',
            'reason' => 'required|string|min:10|max:500', 'password' => 'required|string|max:200']);
        $actor = $request->user('clubunio');
        $service->password($actor, $data['password']);
        $service->grant($actor, app(ActiveTenant::class)->resolve($request), $data['target_id'], (bool) $data['enabled'], $data['revision'], $data['reason']);
        return redirect('/support/impersonation', 303);
    }

    public function stop(Request $request, ImpersonationService $service)
    {
        $service->stop($request);
        return redirect('/support/impersonation', 303);
    }
}
