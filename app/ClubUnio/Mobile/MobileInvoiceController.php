<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\{InvoiceAccessService, MemberAccessService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MobileInvoiceController
{
    public function query(Account $actor, Tenant $tenant)
    {
        $access = app(MobileAccess::class);
        $access->enter($actor, $tenant);
        $query = app(InvoiceAccessService::class)->visible($actor, $tenant);
        if (! $access->admin($actor, $tenant)) {
            $query->whereIn('member_id', app(MemberAccessService::class)->represented($actor, $tenant)->select('clubora.members.id'));
        }

        return app(MobileSeasonScope::class)->finance($query, $tenant);
    }

    public function index(Request $request)
    {
        $d = $request->validate(['start' => 'nullable|integer|min:0|max:100000', 'limit' => 'nullable|integer|min:1|max:100',
            'member_id' => 'nullable|integer|min:1', 'status_id' => 'nullable|integer|min:1']);
        $tenant = $request->attributes->get('mobile.tenant');
        $query = $this->query($request->attributes->get('mobile.actor'), $tenant);
        foreach (['member_id', 'status_id'] as $field) { if (isset($d[$field])) { $query->where($field, $d[$field]); } }
        $total = (clone $query)->count();
        $rows = $query->select(['id', 'tenant_id', 'member_id', 'season_id', 'status_id', 'bexio_invoice_id', 'tax_mode', 'cash_rounding'])
            ->withSum('activeLines', 'net_amount')->with('status:id,name')->orderByDesc('id')->offset($d['start'] ?? 0)->limit($d['limit'] ?? 20)->get()
            ->map(function ($row) {
                $summary = app(\App\ClubUnio\Services\InvoiceTaxService::class)->calculate([
                    ['cents' => app(\App\ClubUnio\Services\BexioBalanceService::class)->cents((string) ($row->active_lines_sum_net_amount ?? '0'))]],
                    $row->tax_mode === 'inclusive', (bool) $row->cash_rounding);

                return ['id' => (int) $row->id, 'member_id' => (int) $row->member_id, 'season_id' => (int) $row->season_id,
                    'status_id' => $row->status_id, 'status' => $row->status?->name, 'total_cents' => $summary['total_cents'],
                    'currency' => 'CHF', 'has_pdf' => (bool) $row->bexio_invoice_id];
            });

        return response()->json(['data' => ['rows' => $rows, 'total' => $total]]);
    }

    public function options(Request $request)
    {
        $d = $request->validate(['search' => 'nullable|string|max:100']);
        $tenant = $request->attributes->get('mobile.tenant');
        $query = $this->query($request->attributes->get('mobile.actor'), $tenant);
        $db = DB::connection('clubunio');
        $members = $db->table('clubora.members')->where('tenant_id', $tenant->id)->whereIn('id', (clone $query)->select('member_id'));
        if (! empty($d['search'])) {
            $search = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $d['search']).'%';
            $members->where(fn ($q) => $q->where('first_name', 'like', $search)->orWhere('last_name', 'like', $search));
        }
        $season = app(MobileSeasonScope::class)->current($tenant);

        return response()->json(['data' => [
            'seasons' => $season ? [['id' => (int) $season->id, 'label' => $season->name]] : [],
            'members' => $members->orderBy('last_name')->orderBy('first_name')->orderBy('id')->limit(100)->get(['id', 'first_name', 'last_name']),
            'statuses' => $db->table('clubora.invoice_statuses')->where('tenant_id', $tenant->id)->where('active', true)->orderBy('id')->get(['id', 'name']),
        ]]);
    }
}
