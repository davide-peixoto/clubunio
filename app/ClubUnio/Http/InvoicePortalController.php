<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Models\InvoiceStatus;
use App\ClubUnio\Models\Season;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\InvoiceAccessService;
use App\ClubUnio\Services\MemberAccessService;
use Illuminate\Http\Request;

class InvoicePortalController
{
    public function index(Request $request, Tenant $tenant, InvoiceAccessService $access)
    {
        abort_unless(app(\App\ClubUnio\Services\ExpenseService::class)->portalAllowed($request->user('portal'), $tenant), 403);
        $data = $request->validate(['q' => 'nullable|string|max:120', 'status' => 'nullable|integer|min:1', 'season' => 'nullable|integer|min:1', 'member' => 'nullable|integer|min:1']);
        $q = trim($data['q'] ?? '');
        $invoices = $access->visible($request->user('portal'), $tenant)
            ->with(['member', 'status', 'season'])->withSum('activeLines', 'net_amount')
            ->when($data['status'] ?? null, fn ($query, $id) => $query->where('status_id', $id))
            ->when($data['season'] ?? null, fn ($query, $id) => $query->where('season_id', $id))
            ->when($data['member'] ?? null, fn ($query, $id) => $query->where('member_id', $id))
            ->when($q !== '', fn ($query) => $query->where(function ($query) use ($q) {
                $query->whereHas('member', fn ($member) => $member->where(fn ($member) => $member
                    ->where('first_name', 'ilike', '%'.$q.'%')->orWhere('last_name', 'ilike', '%'.$q.'%')->orWhere('company', 'ilike', '%'.$q.'%')));
                if (ctype_digit($q) && strlen($q) <= 15) {
                    $query->orWhere('legacy_id', $q);
                }
            }))->orderByDesc('id')->paginate(25)->withQueryString();
        $statuses = InvoiceStatus::withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderBy('name')->get();
        $seasons = Season::withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderByDesc('name')->get();

        return response()->view('clubunio.portal.invoices', compact('tenant', 'invoices', 'statuses', 'seasons', 'q') + ['tenants' => collect([$tenant])])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, Tenant $tenant, int $invoice, InvoiceAccessService $access)
    {
        abort_unless(app(\App\ClubUnio\Services\ExpenseService::class)->portalAllowed($request->user('portal'), $tenant), 403);
        $record = $access->visible($request->user('portal'), $tenant)
            ->with(['member', 'status', 'season', 'activeLines.status'])->withSum('activeLines', 'net_amount')->findOrFail($invoice);

        return response()->view('clubunio.portal.invoice', compact('tenant', 'record') + ['tenants' => collect([$tenant])])->header('Cache-Control', 'private, no-store');
    }
}
