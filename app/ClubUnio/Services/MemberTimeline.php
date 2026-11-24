<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Account, Member, Tenant};
use App\ClubUnio\Resources\{MemberResource, MemberDocumentResource, InvoiceResource, ObjectChangeResource};
use Carbon\CarbonImmutable as Time;

class MemberTimeline
{
    public function entries(Account $actor, Tenant $tenant, int $id): array
    {
        abort_unless(app(WorkspaceAccess::class)->allowed($actor,$tenant) && MemberResource::canViewAny(),403);
        $member=MemberResource::getEloquentQuery()->where('tenant_id',$tenant->id)->findOrFail($id);
        abort_unless(MemberResource::canView($member),403);
        $rows=[]; $sources=[];
        $add=function ($date,$kind,$title,$detail,$url=null) use (&$rows) {
            if ($date) $rows[]=['at'=>Time::parse($date),'kind'=>trans('workspace.timeline_'.$kind),'title'=>$title,'detail'=>$detail,'url'=>$url];
        };
        if (InvoiceResource::canViewAny()) {
            $sources[]=trans('workspace.timeline_invoice');
            foreach (InvoiceResource::getEloquentQuery()->where('tenant_id',$tenant->id)->where('member_id',$id)->orderByDesc('created_at')->limit(60)->get() as $row) {
                $add($row->created_at,'invoice',trans('workspace.invoice_id',['id'=>$row->invoice_number ?? $row->id]),
                    trans('workspace.document_created').' · '.ModulePresentation::money((int) round((float) $row->active_lines_sum_net_amount * 100) + (int) $row->rounding_cents),InvoiceResource::getUrl('view',['record'=>$row]));
            }
        }
        if (MemberDocumentResource::canViewAny()) {
            $sources[]=trans('workspace.timeline_document');
            foreach (MemberDocumentResource::getEloquentQuery()->where('tenant_id',$tenant->id)->where('member_id',$id)->where('active',true)->orderByDesc('source_created_at')->limit(60)->get() as $row) {
                $add($row->source_created_at ?? $row->created_at,'document',$row->original_filename,trans('workspace.document_created'));
            }
        }
        if (ObjectChangeResource::canViewAny()) {
            $sources[]=trans('workspace.timeline_change');
            foreach (ObjectChangeResource::getEloquentQuery()->where('tenant_id',$tenant->id)->where('object_type','members')->where('object_id',$id)->orderByDesc('created_at')->limit(60)->get(['id','created_at']) as $row) {
                $add($row->created_at,'change',trans('workspace.member_updated'),trans('workspace.audit_id',['id'=>$row->id]));
            }
        }
        if (\App\ClubUnio\Pages\TeamPlanning::canAccess()) {
            $sources[]=trans('workspace.timeline_event');
            $planning=app(TeamPlanningService::class);
            $events=app(PlanningCalendarService::class)->visible($actor,$tenant)->whereIn('id',$planning->table($tenant,'team_participations')->where('member_id',$id)->select('event_id'))
                ->where('starts_at','<=',now()->toIso8601String())->orderByDesc('starts_at')->limit(60)->get(['id','title','starts_at']);
            foreach ($events as $row) $add($row->starts_at,'event',$row->title,trans('workspace.participation_record'),route('clubunio.planning.show',[$tenant->slug,$row->id]));
        }
        return ['rows'=>collect($rows)->sortByDesc(fn($row)=>$row['at']->getTimestamp())->take(60)->values()->all(),'sources'=>$sources];
    }
}
