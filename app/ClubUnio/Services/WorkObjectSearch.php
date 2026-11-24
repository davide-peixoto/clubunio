<?php
namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Account,Tenant};
use App\ClubUnio\Resources\{MemberResource,InvoiceResource,MemberDocumentResource};
use Illuminate\Support\Facades\Validator;

class WorkObjectSearch
{
    public static function pattern(string $term): string { return '%'.str_replace(['!','%','_'],['!!','!%','!_'],mb_strtolower(trim($term))).'%'; }
    public function results(Account $actor,Tenant $tenant,string $term): array
    {
        abort_unless(app(WorkspaceAccess::class)->allowed($actor,$tenant),403);
        Validator::make(compact('term'),['term'=>'nullable|string|max:100'])->validate();
        if(mb_strlen(trim($term))<2)return [];
        $needle=self::pattern($term);$results=[];
        if(MemberResource::canViewAny()) foreach(MemberResource::getEloquentQuery()->where('tenant_id',$tenant->id)->where(function($q)use($term,$needle){
            $q->whereRaw("LOWER(COALESCE(first_name,'') || ' ' || COALESCE(last_name,'') || ' ' || COALESCE(company,'')) LIKE ? ESCAPE '!'",[$needle]);
            if(ctype_digit($term))$q->orWhere('member_number',(int)$term)->orWhere('id',(int)$term);
        })->orderBy('last_name')->limit(6)->get() as $row)$results[]=['kind'=>trans('work_hub.members'),'label'=>$row->display_name,'detail'=>'#'.$row->id,'url'=>MemberResource::getUrl('view',['record'=>$row])];
        if(InvoiceResource::canViewAny()) foreach(InvoiceResource::getEloquentQuery()->where('tenant_id',$tenant->id)->where(function($q)use($term,$needle){
            $q->whereRaw("LOWER(COALESCE(CAST(legacy_id AS TEXT),'')) LIKE ? ESCAPE '!'",[$needle]);if(ctype_digit($term))$q->orWhere('id',(int)$term);
        })->orderByDesc('id')->limit(6)->get() as $row)$results[]=['kind'=>trans('work_hub.invoices'),'label'=>trans('workspace.invoice_id',['id'=>$row->legacy_id??$row->id]),'detail'=>ModulePresentation::money((int)round((float)$row->active_lines_sum_net_amount*100)+(int)$row->rounding_cents),'url'=>InvoiceResource::getUrl('view',['record'=>$row])];
        if(MemberDocumentResource::canViewAny()) foreach(MemberDocumentResource::getEloquentQuery()->where('tenant_id',$tenant->id)->where('active',true)->whereRaw("LOWER(original_filename) LIKE ? ESCAPE '!'",[$needle])->orderByDesc('id')->limit(6)->get() as $row)
            $results[]=['kind'=>trans('work_hub.documents'),'label'=>$row->original_filename,'detail'=>'#'.$row->id,'url'=>MemberDocumentResource::getUrl('index',['search'=>$row->original_filename])];
        if(\App\ClubUnio\Pages\TeamPlanning::canAccess()) foreach(app(PlanningCalendarService::class)->visible($actor,$tenant)->whereRaw("LOWER(title) LIKE ? ESCAPE '!'",[$needle])->orderByDesc('starts_at')->limit(6)->get(['id','title','starts_at']) as $row)
            $results[]=['kind'=>trans('work_hub.events'),'label'=>$row->title,'detail'=>\Carbon\CarbonImmutable::parse($row->starts_at)->tz('Europe/Zurich')->format('d.m.Y H:i'),'url'=>route('clubunio.planning.show',[$tenant->slug,$row->id])];
        return $results;
    }
}
