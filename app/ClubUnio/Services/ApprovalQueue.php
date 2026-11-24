<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Account, Tenant, MemberChangeRequest, ExpenseStatus};
use App\ClubUnio\Resources\{MemberChangeRequestResource, ExpenseResource};
use Illuminate\Support\Facades\{DB, Validator};

class ApprovalQueue
{
    public function kinds(Account $actor, Tenant $tenant): array
    {
        abort_unless(app(WorkspaceAccess::class)->allowed($actor, $tenant), 403);
        $permissions = app(ModulePermissionService::class);
        $kinds = [];
        foreach (['expense' => ['expenses','approve'], 'change' => ['member_changes','approve'], 'bus' => ['bus','approve'], 'pitch' => ['competitions','update']] as $kind => [$module,$operation]) {
            if (! $permissions->allows($actor, $tenant, $module, 'view') || ! $permissions->allows($actor, $tenant, $module, $operation)) continue;
            if ($kind === 'pitch' && ! in_array(DB::connection('clubunio')->table('clubora.tenant_accounts')->where('tenant_id', $tenant->id)->where('account_id', $actor->id)->value('role'), ['admin','office'], true)) continue;
            if ($kind === 'expense' && ! ExpenseResource::canViewAny()) continue;
            if ($kind === 'change' && ! MemberChangeRequestResource::canViewAny()) continue;
            $kinds[$kind] = trans('workspace.kind_'.$kind);
        }
        return $kinds;
    }

    private function query(Account $actor, Tenant $tenant, string $kind)
    {
        abort_unless(isset($this->kinds($actor, $tenant)[$kind]), 403);
        return match ($kind) {
            'expense' => app(ExpenseService::class)->visible($actor, $tenant)->where('active', true)
                ->whereHas('status', fn ($q) => $q->where('tenant_id', $tenant->id)->where('code', 'F')),
            'change' => MemberChangeRequestResource::getEloquentQuery()->where('tenant_id', $tenant->id)->where('state', 'pending'),
            'bus' => app(BusReservationService::class)->visible($actor, $tenant)->where('status', 'ANGEFRAGT')->where('ends_at', '>', now()->toIso8601String()),
            'pitch' => DB::connection('clubunio')->table('clubora.pitch_requests')->where('tenant_id', $tenant->id)->where('status','pending'),
        };
    }

    public function counts(Account $actor, Tenant $tenant): array
    {
        $counts = [];
        foreach ($this->kinds($actor, $tenant) as $kind => $label) $counts[$kind] = ['label' => $label, 'count' => $this->query($actor, $tenant, $kind)->count()];
        return $counts;
    }

    public function rows(Account $actor, Tenant $tenant, string $kind, int $page): array
    {
        $query = $this->query($actor, $tenant, $kind);
        $count = (clone $query)->count();
        $page = max(1, min($page, max(1, (int) ceil($count / 10))));
        $rows = $query->orderBy('id')->offset(($page - 1) * 10)->limit(10)->get()->map(fn ($row) => [
            'id' => $row->id, 'label' => match ($kind) {
                'expense' => trans('workspace.expense_id', ['id' => $row->legacy_id ?? $row->id]),
                'change' => trans('workspace.change_id', ['id' => $row->id]),
                'bus' => trans('workspace.bus_id', ['id' => $row->id]).' · '.$row->location,
                'pitch' => trans('workspace.pitch_id', ['id' => $row->id]).' · '.$row->opponent,
            },
        ])->all();
        return compact('rows','count','page');
    }

    public function preview(Account $actor, Tenant $tenant, string $kind, int $id): array
    {
        $query = $this->query($actor, $tenant, $kind);
        $row = $query->where('id', $id)->first();
        abort_unless($row, 404);
        $facts = []; $lines = []; $comparison = []; $url = null; $decisions = ['approve','reject'];
        if ($kind === 'expense') {
            $targets = app(ExpenseService::class)->targets($actor,$tenant,$row);
            $decisions = array_values(array_filter(['approve','reject'],fn($decision)=>in_array($decision==='approve'?'Z':'R',$targets,true)));
            $row->loadSum('activeLines', 'amount');
            $facts = [['label' => trans('workspace.amount'), 'value' => ModulePresentation::money((int) round((float) $row->active_lines_sum_amount * 100))],
                ['label' => trans('workspace.notes'), 'value' => $row->notes ?: '–']];
            $lines = $row->activeLines()->orderBy('id')->limit(101)->get(['id','amount','notes','opponent'])->map(fn ($line) => ['label' => trim(($line->opponent ?? '').' · '.($line->notes ?? ''), ' ·'), 'value' => ModulePresentation::money((int) round((float) $line->amount * 100))])->all();
            $url = ExpenseResource::getUrl('view', ['record' => $row]);
        } elseif ($kind === 'change') {
            $comparison = app(MemberChangeService::class)->comparison($row, $tenant);
            $url = MemberChangeRequestResource::getUrl();
        } else {
            $facts = [['label' => trans('workspace.start'), 'value' => $kind === 'bus' ? \Carbon\CarbonImmutable::parse($row->starts_at)->tz('Europe/Zurich')->format('d.m.Y H:i') : $row->date.' '.$row->starts_at],
                ['label' => trans('workspace.details'), 'value' => $kind === 'bus' ? $row->location : $row->opponent],
                ['label' => trans('workspace.resource'), 'value' => $kind === 'bus' ? '#'.$row->bus_id : '#'.$row->field_id]];
            $url = $kind === 'bus' ? route('clubunio.bus.show', [$tenant->slug,$id]) : \App\ClubUnio\Pages\PitchPlanning::getUrl();
        }
        return ['kind' => $kind, 'id' => $id, 'revision' => (string) $row->revision, 'facts' => $facts, 'lines' => $lines, 'comparison' => $comparison, 'url' => $url, 'decisions'=>$decisions];
    }

    public function decide(Account $actor, Tenant $tenant, string $kind, int $id, string $revision, string $decision, string $note): void
    {
        Validator::make(compact('decision','note','revision'), ['decision' => 'required|in:approve,reject', 'note' => 'required|string|min:10|max:500', 'revision' => 'required|integer|min:1'])->validate();
        // Recheck the pending queue and all rights; the domain service locks and checks the supplied revision.
        $preview = $this->preview($actor, $tenant, $kind, $id);
        if ($preview['revision'] !== $revision) throw \Illuminate\Validation\ValidationException::withMessages(['revision' => trans('workspace.stale')]);
        abort_unless(in_array($decision,$preview['decisions'],true),403);
        if ($kind === 'expense') {
            $code = $decision === 'approve' ? 'Z' : 'R';
            $status = ExpenseStatus::where('tenant_id', $tenant->id)->where('active', true)->where('code', $code)->sole();
            app(ExpenseService::class)->transition($actor, $tenant, $id, ['revision' => (int) $revision, 'status_id' => $status->id, 'reason' => $note]);
        } elseif ($kind === 'change') {
            app(MemberChangeService::class)->review($actor, $tenant, $id, (int) $revision, $decision === 'approve', $note);
        } elseif ($kind === 'bus') {
            app(BusReservationService::class)->decide($actor, $tenant, $id, (int) $revision, $decision === 'approve' ? 'GENEHMIGT' : 'ABGELEHNT', $note);
        } else {
            app(PitchPlanningService::class)->decide($actor, $tenant, $id, ['revision' => $revision, 'decision' => $decision === 'approve' ? 'approved' : 'rejected']);
        }
    }
}
