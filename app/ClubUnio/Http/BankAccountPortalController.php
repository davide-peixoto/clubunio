<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Models\Bank;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\BankAccountService;
use App\ClubUnio\Services\MemberAccessService;
use Illuminate\Http\Request;

class BankAccountPortalController
{
    public function index(Request $request, Tenant $tenant, BankAccountService $service)
    {
        $actor = $request->user('portal');
        abort_unless(app(MemberAccessService::class)->canEnter($actor, $tenant), 403);
        $member = app(MemberAccessService::class)->represented($actor, $tenant, true)->firstOrFail();
        $accounts = $service->visible($actor, $tenant)->where('member_id', $member->id)->with('bank')->orderByDesc('id')->get();
        $banks = Bank::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true)->orderBy('name')->get();

        return response()->view('clubunio.portal.banks', compact('tenant', 'member', 'accounts', 'banks') + ['tenants' => collect([$tenant])])->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request, Tenant $tenant, BankAccountService $service, ?int $account = null)
    {
        $actor = $request->user('portal');
        abort_unless(app(MemberAccessService::class)->canEnter($actor, $tenant), 403);
        $member = app(MemberAccessService::class)->represented($actor, $tenant, true)->firstOrFail();
        $service->save($actor, $tenant, $member->id, $account, $request->only(['revision', 'bank_id', 'iban', 'active']));

        return redirect()->route('clubunio.portal.banks', ['tenant' => $tenant->slug])->with('saved', true);
    }
}
