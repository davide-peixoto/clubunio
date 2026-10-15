<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\LegacyMaterialReceiptService;
use App\ClubUnio\Services\MaterialReceiptService;
use Illuminate\Http\Request;

class MaterialReceiptPortalController
{
    public function index(Request $request, Tenant $tenant, MaterialReceiptService $service)
    {
        $receipts = $service->visible($request->user('portal'), $tenant)->with('member')->orderByDesc('id')->paginate(20);
        $states = $service->states($tenant);
        $history = app(LegacyMaterialReceiptService::class)->visible($request->user('portal'), $tenant)->with('member')->orderByDesc('id')->paginate(20, ['*'], 'historyPage');

        return response()->view('clubunio.material.index', compact('tenant', 'receipts', 'states', 'history') + ['tenants' => collect([$tenant])])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, Tenant $tenant, int $receipt, MaterialReceiptService $service)
    {
        $receipt = $service->visible($request->user('portal'), $tenant)->findOrFail($receipt);
        $states = $service->states($tenant);

        return response()->view('clubunio.material.show', compact('tenant', 'receipt', 'states') + ['tenants' => collect([$tenant])])->header('Cache-Control', 'private, no-store');
    }

    public function confirm(Request $request, Tenant $tenant, int $receipt, MaterialReceiptService $service)
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'accepted' => 'required|accepted']);
        $service->transition($request->user('portal'), $tenant, $receipt, $data['revision'], 'confirm', $data);

        return redirect()->route('clubunio.portal.material.show', [$tenant->slug, $receipt])->with('status', 'Empfang bestätigt.');
    }
}
