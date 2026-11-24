<?php
namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Account,Tenant};
use App\ClubUnio\Resources\{ExpenseResource,RegistrationResource,MemberChangeRequestResource};
use Illuminate\Support\Facades\DB;

class WorkNotificationHub
{
    private function query(Account $actor,Tenant $tenant)
    {
        abort_unless(app(WorkspaceAccess::class)->allowed($actor,$tenant),403);
        return DB::connection('clubunio')->table('clubora.notifications')->where('tenant_id',$tenant->id)->where('account_id',$actor->id);
    }
    public function target(Account $actor,Tenant $tenant,object $row): ?array
    {
        try{
            $id=(int)$row->object_id;
            if($row->object_type==='workspace_task'){$task=app(WorkTaskService::class)->get($actor,$tenant,$id);return ['label'=>$task->title,'url'=>\App\ClubUnio\Pages\TeamTasks::getUrl(['task'=>$id])];}
            if($row->object_type==='chat_mention')return app(WorkChatMentions::class)->target($actor,$tenant,$id);
            if($row->object_type==='expense'&&ExpenseResource::canViewAny()&&app(ExpenseService::class)->visible($actor,$tenant)->whereKey($id)->exists())return ['label'=>trans('workspace.expense_id',['id'=>$id]),'url'=>ExpenseResource::getUrl('view',['record'=>$id])];
            if($row->object_type==='registration'&&RegistrationResource::canViewAny()&&RegistrationResource::getEloquentQuery()->where('tenant_id',$tenant->id)->whereKey($id)->exists())return ['label'=>trans('work_hub.registration'),'url'=>RegistrationResource::getUrl('index',['search'=>(string)$id])];
            if($row->object_type==='member_change_request'&&MemberChangeRequestResource::canViewAny()&&MemberChangeRequestResource::getEloquentQuery()->where('tenant_id',$tenant->id)->whereKey($id)->exists())return ['label'=>trans('workspace.change_id',['id'=>$id]),'url'=>MemberChangeRequestResource::getUrl()];
            if($row->object_type==='pitch_planning'&&\App\ClubUnio\Pages\PitchPlanning::canAccess()){$target=app(PitchPlanningNotificationService::class)->target($actor,$tenant,$id);return ['label'=>trans('workspace.facilities'),'url'=>\App\ClubUnio\Pages\PitchPlanning::getUrl(['date'=>$target['date']])];}
            if($row->object_type==='team_event'){app(TeamPlanningService::class)->event($actor,$tenant,$id);return ['label'=>trans('work_hub.event'),'url'=>route('clubunio.planning.show',[$tenant->slug,$id])];}
            if($row->object_type==='bus_reservation'&&app(BusReservationService::class)->visible($actor,$tenant)->where('id',$id)->exists())return ['label'=>trans('workspace.bus_id',['id'=>$id]),'url'=>route('clubunio.bus.show',[$tenant->slug,$id])];
        }catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface|\Illuminate\Database\Eloquent\ModelNotFoundException|\Illuminate\Database\RecordNotFoundException){return null;}
        return null;
    }
    public function page(Account $actor,Tenant $tenant,int $page,bool $unread): array
    {
        $query=$this->query($actor,$tenant)->when($unread,fn($q)=>$q->whereNull('read_at'));
        $count=(clone $query)->count();$page=max(1,min($page,max(1,(int)ceil($count/20))));$rows=[];
        foreach($query->orderByDesc('id')->offset(($page-1)*20)->limit(20)->get() as $notice){
            if(!$target=$this->target($actor,$tenant,$notice))continue;
            $rows[]=['id'=>$notice->id,'title'=>$notice->title,'body'=>$notice->body,'created_at'=>$notice->created_at,'unread'=>$notice->read_at===null]+$target;
        }
        return ['rows'=>$rows,'page'=>$page,'has_more'=>$page*20<$count];
    }
    public function read(Account $actor,Tenant $tenant,int $id): void
    {
        DB::connection('clubunio')->transaction(function()use($actor,$tenant,$id){
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $row=$this->query($actor,$tenant)->where('id',$id)->first();abort_unless($row&&$this->target($actor,$tenant,$row),404);
            $this->query($actor,$tenant)->where('id',$id)->whereNull('read_at')->update(['read_at'=>now()]);
        });
    }
}
