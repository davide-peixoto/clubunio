<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, Member, MemberChangeRequest, Tenant};
use App\ClubUnio\Services\{MemberAccessService, MemberChangeService, ModulePermissionService};
use Illuminate\Http\Request;

final class MobileMemberDirectoryData
{
    public function index(Request $request, Account $actor, Tenant $tenant): array
    {
        $data = $request->validate(['q' => 'nullable|string|max:120', 'start' => 'nullable|integer|min:0|max:100000', 'recperpage' => 'nullable|integer|min:1|max:50']);
        app(MobileAccess::class)->enter($actor, $tenant);
        // The original personal directory lists self/represented children.
        // Trainer roster contact visibility belongs to the team detail route.
        $query = app(ModulePermissionService::class)->allows($actor, $tenant, 'members', 'view')
            ? Member::withoutGlobalScopes()->where('tenant_id', $tenant->id)
            : app(MemberAccessService::class)->represented($actor, $tenant);
        $search = trim($data['q'] ?? '');
        if ($search !== '') {
            // Literal search: wildcard characters must not broaden the requested name.
            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(fn ($q) => $q->where('first_name', 'ilike', $pattern)->orWhere('last_name', 'ilike', $pattern));
        }
        $total = (clone $query)->count();
        $rows = $query->orderBy('last_name')->orderBy('first_name')->orderBy('id')
            ->offset($data['start'] ?? 0)->limit($data['recperpage'] ?? 20)->get(['id', 'first_name', 'last_name', 'email', 'mobile', 'city']);
        $pending=\Illuminate\Support\Facades\DB::connection('clubunio')->table('clubora.account_member_links')->where('tenant_id',$tenant->id)
            ->where('account_id',$actor->id)->where('state','pending')->where('kind','parent')->count();
        return ['Members' => $rows->map(fn ($member) => ['MitgliederID' => (int) $member->id,
            'Vorname' => $member->first_name, 'Nachname' => $member->last_name, 'Email' => $member->email,
                'Mobile' => $member->mobile, 'Ort' => $member->city, 'CanOpenDossier' => true])->all(), 'totalRecordCount' => $total,
            'CanReviewChangeRequests' => app(ModulePermissionService::class)->allows($actor, $tenant, 'member_changes', 'view'),
            'PendingParentLinks'=>(int)$pending];
    }
    public function changes(Request $request, Account $actor, Tenant $tenant): array
    {
        $scope = app(MobileMemberData::class)->scope($actor, $tenant);
        $permissions = app(ModulePermissionService::class); $permissions->authorize($actor, $tenant, 'member_changes', 'view');
        $d = $request->validate(['status' => 'nullable|in:OFFEN,GENEHMIGT,ABGELEHNT,ZURUECKGEZOGEN',
            'start' => 'nullable|integer|min:0|max:100000', 'recperpage' => 'nullable|integer|min:1|max:100']);
        $query = MemberChangeRequest::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereIn('member_id', $scope->select('clubora.members.id'));
        if (!empty($d['status'])) { $query->where('state', array_search($d['status'], MobileMemberData::STATES, true)); }
        $total = (clone $query)->count(); $approve = $permissions->allows($actor, $tenant, 'member_changes', 'approve');
        $update = $permissions->allows($actor, $tenant, 'members', 'update');
        $rows = $query->with(['requester', 'member' => fn ($q) => $q->withoutGlobalScopes()->where('tenant_id', $tenant->id)])
            ->orderByDesc('id')->offset($d['start'] ?? 0)->limit($d['recperpage'] ?? 20)->get();
        return ['requests' => $rows->map(fn ($change) => ['AenderungsantragID' => (int) $change->id, 'MitgliederID' => (int) $change->member_id,
            'Revision' => (int) $change->revision, 'Mitglied' => trim($change->member->first_name.' '.$change->member->last_name),
            'Status' => MobileMemberData::STATES[$change->state], 'CreatedAt' => $change->created_at?->toIso8601String(),
            'AntragstellerUsername' => $change->requester?->display_name, 'EntscheidAm' => $change->reviewed_at?->toIso8601String(),
            'EntscheidBegruendung' => $change->decision_note, 'CanApprove' => $approve && $update && $change->state === 'pending',
            'CanReject' => $approve && $change->state === 'pending',
            'Aenderungen' => array_map(fn ($comparison) => ['field' => $comparison['field'], 'old' => $comparison['before'], 'new' => $comparison['after']],
                app(MemberChangeService::class)->comparison($change, $tenant))])->all(), 'totalRecordCount' => $total];
    }
}
