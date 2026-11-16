<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\AccountingProvider;
use App\ClubUnio\Services\BexioConnectionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountingProviderController
{
    public function show(Request $request, Tenant $tenant)
    {
        abort_unless(BexioConnectionService::canManage($request->user('clubunio'), $tenant), 403);

        return view('clubunio.accounting-provider', [
            'tenant' => $tenant,
            'provider' => AccountingProvider::current($tenant),
            'providers' => AccountingProvider::OPTIONS,
        ]);
    }

    public function update(Request $request, Tenant $tenant, AccountingProvider $service)
    {
        $actor = $request->user('clubunio');
        abort_unless(BexioConnectionService::canManage($actor, $tenant), 403);
        $data = $request->validate([
            'provider' => ['required', 'string', Rule::in(AccountingProvider::OPTIONS)],
            'expected' => ['required', 'string', Rule::in(AccountingProvider::OPTIONS)],
        ]);
        $service->choose($actor, $tenant, $data['provider'], $data['expected']);

        return redirect()->route('clubunio.accounting-provider.show')
            ->with('notice', trans('accounting_provider.saved'));
    }
}
