<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function refundableCash(Account $actor, Tenant $tenant): int
    {
        abort_unless($actor->fresh()?->canAccessTenant($tenant), 403);

        return (int) DB::connection('clubunio')->table('clubora.bexio_balances as b')->join('clubora.invoices as i', fn ($j) => $j->on('i.id', '=', 'b.object_id')->on('i.tenant_id', '=', 'b.tenant_id'))
            ->where('b.tenant_id', $tenant->id)->where('b.object_type', 'invoice')->where('i.active', true)
            ->selectRaw("COALESCE(SUM(GREATEST(0,COALESCE(b.cash_excess_cents,0)-(SELECT COALESCE(SUM(r.amount_cents),0) FROM clubora.refunds r
                JOIN clubora.financial_adjustment_statuses s ON s.id=r.status_id AND s.tenant_id=r.tenant_id WHERE r.invoice_id=i.id AND r.tenant_id=i.tenant_id AND s.code<>'cancelled'))),0) AS amount")
            ->value('amount');
    }

    public function newMembersMonthly(Account $actor, Tenant $tenant): array
    {
        abort_unless($actor->canAccessTenant($tenant), 403);
        $first = now('Europe/Zurich')->startOfMonth()->subMonths(11);
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $months[$first->copy()->addMonths($i)->format('Y-m')] = 0;
        }
        $counts = DB::connection('clubunio')->table('clubora.members')->where('tenant_id', $tenant->id)->whereNull('archived_at')
            ->where('recorded_at', '>=', $first->toDateTimeString())->where('recorded_at', '<=', now('Europe/Zurich')->toDateTimeString())
            ->selectRaw("to_char(recorded_at, 'YYYY-MM') as month, count(*) as total")->groupBy('month')->pluck('total', 'month');

        return array_replace($months, $counts->map(fn ($count) => (int) $count)->all());
    }

    private function invoiceTotals(Account $actor, Tenant $tenant): \Illuminate\Database\Query\Builder
    {
        abort_unless($actor->canAccessTenant($tenant), 403);

        return DB::connection('clubunio')->table('clubora.invoices as i')
            ->leftJoin('clubora.invoice_lines as p', fn ($join) => $join->on('p.invoice_id', '=', 'i.id')->on('p.tenant_id', '=', 'i.tenant_id')->where('p.active', true))
            ->leftJoin('clubora.invoice_line_statuses as st', fn ($join) => $join->on('st.id', '=', 'p.status_id')->on('st.tenant_id', '=', 'p.tenant_id'))
            ->leftJoin('clubora.bexio_balances as b', fn ($join) => $join->on('b.object_id', '=', 'i.id')->on('b.tenant_id', '=', 'i.tenant_id')->where('b.object_type', 'invoice'))
            ->where('i.tenant_id', $tenant->id)->where('i.active', true)->groupBy('i.id', 'i.season_id')
            ->selectRaw("i.id, i.season_id, count(p.id) as position_count, COALESCE(sum(COALESCE(NULLIF(p.net_amount,0),NULLIF(p.gross_amount,0),0)),0) + MAX(i.rounding_cents)/100.0 as amount,
                count(p.id) FILTER (WHERE st.code IN ('O','T','U')) as open_count, count(p.id) FILTER (WHERE st.code='B') as paid_count,
                count(p.id) FILTER (WHERE st.code='T') as partial_count,
                COALESCE(MAX(b.remaining_cents)/100.0, sum(COALESCE(NULLIF(p.net_amount,0),NULLIF(p.gross_amount,0),0)) FILTER (WHERE st.code IN ('O','T','U')) + MAX(i.rounding_cents)/100.0,0) as open_amount");
    }

    public function invoicesByStatus(Account $actor, Tenant $tenant): array
    {
        return DB::connection('clubunio')->query()->fromSub($this->invoiceTotals($actor, $tenant), 'totals')
            ->selectRaw("CASE WHEN position_count=0 THEN 'Ohne Position' WHEN partial_count>0 THEN 'Teilbezahlt' WHEN open_count>0 THEN 'Offen' WHEN paid_count=position_count THEN 'Bezahlt' ELSE 'Teilweise' END as status, count(*) as total, sum(amount) as amount")
            ->groupBy('status')->orderBy('status')->get()->map(fn ($row) => (array) $row)->all();
    }

    public function invoicesBySeason(Account $actor, Tenant $tenant): array
    {
        return DB::connection('clubunio')->query()->fromSub($this->invoiceTotals($actor, $tenant), 'totals')
            ->leftJoin('clubora.seasons as s', fn ($join) => $join->on('s.id', '=', 'totals.season_id')->where('s.tenant_id', $tenant->id)->where('s.active', true))
            ->selectRaw("COALESCE(s.name,'Ohne Saison') as season, count(*) as total, sum(amount) as amount, sum(open_amount) as open_amount")
            ->groupBy('s.id', 's.name')->orderByDesc('s.id')->limit(10)->get()->map(fn ($row) => (array) $row)->all();
    }

    public function openInvoices(Account $actor, Tenant $tenant): Builder
    {
        abort_unless($actor->canAccessTenant($tenant), 403);

        return $this->scopeOpenInvoices(Invoice::where('tenant_id', $tenant->id)->where('active', true), $tenant);
    }

    public function scopeOpenInvoices(Builder $query, Tenant $tenant): Builder
    {
        return $query->where('clubora.invoices.tenant_id', $tenant->id)->where(fn ($q) => $q
            ->whereHas('bexioBalance', fn ($b) => $b->where('tenant_id', $tenant->id)->where('remaining_cents', '>', 0))
            ->orWhere(fn ($q) => $q->whereDoesntHave('bexioBalance')->whereHas('activeLines', fn ($p) => $p->whereHas('status', fn ($s) => $s->whereIn('code', ['O', 'T', 'U'])))))
            ->select('clubora.invoices.*')->selectRaw("COALESCE(
                (SELECT b.remaining_cents/100.0 FROM clubora.bexio_balances b WHERE b.object_type='invoice' AND b.object_id=invoices.id AND b.tenant_id=invoices.tenant_id),
                (SELECT COALESCE(sum(COALESCE(NULLIF(p.net_amount,0),NULLIF(p.gross_amount,0),0)),0) FROM clubora.invoice_lines p
                 JOIN clubora.invoice_line_statuses s ON s.id=p.status_id AND s.tenant_id=p.tenant_id
                 WHERE p.invoice_id=invoices.id AND p.tenant_id=invoices.tenant_id AND p.active AND s.code IN ('O','T','U')) + invoices.rounding_cents/100.0
                ) as open_amount");
    }

    public function openSummary(Account $actor, Tenant $tenant): array
    {
        $query = $this->openInvoices($actor, $tenant)->toBase();
        $totals = DB::connection('clubunio')->query()->fromSub($query, 'open_invoices')
            ->selectRaw('count(*) as total, COALESCE(sum(open_amount), 0) as amount')->first();

        return ['count' => (int) $totals->total, 'amount' => $totals->amount];
    }

    public function ages(Account $actor, Tenant $tenant): array
    {
        abort_unless($actor->canAccessTenant($tenant), 403);
        $today = now('Europe/Zurich')->toDateString();
        $age = 'extract(year from age(CAST(? AS date), birth_date))';
        $rows = app(MemberAccessService::class)->activeMembers($tenant)
            ->selectRaw("CASE WHEN birth_date IS NULL OR birth_date > CAST(? AS date) THEN 'Unbekannt'
                WHEN $age < 6 THEN '0-5' WHEN $age < 13 THEN '6-12'
                WHEN $age < 18 THEN '13-17' WHEN $age < 30 THEN '18-29'
                WHEN $age < 50 THEN '30-49' ELSE '50+' END AS age_group, count(*) AS total",
                array_fill(0, 6, $today))->groupBy('age_group')->pluck('total', 'age_group');

        return array_replace(array_fill_keys(['0-5', '6-12', '13-17', '18-29', '30-49', '50+', 'Unbekannt'], 0), $rows->map(fn ($n) => (int) $n)->all());
    }
}
