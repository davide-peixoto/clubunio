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
        return DB::connection('clubunio')->table('clubunio.notifications')->where('tenant_id',$tenant->id)->where('account_id',$actor->id);
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
    /** Group metadata is streamed; only the current page loads message bodies. */
    private function groups(Account $actor,Tenant $tenant,bool $unread): \Generator
    {
        $query=$this->query($actor,$tenant)->selectRaw('object_type, object_id, MAX(id) AS latest_id, COUNT(*) AS message_count, SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END) AS unread_count')
            ->groupBy('object_type','object_id')->when($unread,fn($q)=>$q->havingRaw('SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END)>0'))->orderByDesc('latest_id');
        foreach($query->cursor() as $group){
            $notice=(object)['object_type'=>$group->object_type,'object_id'=>$group->object_id];
            if($target=$this->target($actor,$tenant,$notice))yield ['id'=>(int)$group->latest_id,'count'=>(int)$group->message_count,'unread'=>(int)$group->unread_count>0]+$target;
        }
    }
    public function unreadCount(Account $actor,Tenant $tenant):int
    {
        // Apply the native task visibility scope in SQL; never decrypt every task for a badge.
        $notices=$this->query($actor,$tenant);$tasks=app(WorkTaskService::class);
        $visible=$tasks->allows($actor,$tenant,'view')?$tasks->visible($actor,$tenant)->select('id'):null;
        $groups=$notices->whereNull('read_at')->where(function($q)use($visible){
            $q->where('object_type','<>','workspace_task');
            if($visible)$q->orWhere(fn($tasks)=>$tasks->where('object_type','workspace_task')->whereIn('object_id',$visible));
        })->select('object_type','object_id')->groupBy('object_type','object_id')->cursor();
        $count=0;
        foreach($groups as $group)if($group->object_type==='workspace_task'||$this->target($actor,$tenant,$group))$count++;
        return $count;
    }
    public function page(Account $actor,Tenant $tenant,int $page,bool $unread): array
    {
        $groups=iterator_to_array($this->groups($actor,$tenant,$unread),false);$count=count($groups);
        $page=max(1,min($page,max(1,(int)ceil($count/20))));$rows=[];
        $current=array_slice($groups,($page-1)*20,20);
        $bodies=$this->query($actor,$tenant)->whereIn('id',array_column($current,'id'))->get()->keyBy('id');
        foreach($current as $group){
            $notice=$bodies->get($group['id']);
            if($notice)$rows[]=$group+['title'=>$notice->title,'body'=>$notice->body,'created_at'=>$notice->created_at];
        }
        return ['rows'=>$rows,'page'=>$page,'has_more'=>$page*20<$count,'count'=>$count];
    }
    public function details(Account $actor,Tenant $tenant,int $id):array
    {
        $row=$this->query($actor,$tenant)->where('id',$id)->first();abort_unless($row&&$this->target($actor,$tenant,$row),404);
        return $this->query($actor,$tenant)->where('object_type',$row->object_type)->where('object_id',$row->object_id)->where('id','<=',$id)
            ->orderByDesc('id')->limit(10)->get(['id','title','body','created_at'])->map(fn($entry)=>(array)$entry)->all();
    }
    public function readGroup(Account $actor,Tenant $tenant,int $id):void
    {
        DB::connection('clubunio')->transaction(function()use($actor,$tenant,$id){
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $row=$this->query($actor,$tenant)->where('id',$id)->first();abort_unless($row&&$this->target($actor,$tenant,$row),404);
            $this->query($actor,$tenant)->where('object_type',$row->object_type)->where('object_id',$row->object_id)->where('id','<=',$id)->whereNull('read_at')->update(['read_at'=>now()]);
        });
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
