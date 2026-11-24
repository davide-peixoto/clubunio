<?php
namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Account,Tenant};
use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\Crypt;

class DailyCockpit
{
    public function data(Account $actor,Tenant $tenant):array
    {
        abort_unless(app(WorkspaceAccess::class)->allowed($actor,$tenant),403);
        $tasks=[];$taskCount=0;$conflicts=[];$conflictCount=0;$conflictError=false;
        $service=app(WorkTaskService::class);$today=now('Europe/Zurich')->toDateString();
        $canTasks=$service->allows($actor,$tenant,'view');
        if($canTasks){
            $query=$service->visible($actor,$tenant)->where('assigned_to',$actor->id)->whereIn('state',['open','doing'])->where('due_on','<=',$today);
            $taskCount=(clone $query)->count();
            foreach($query->orderBy('due_on')->orderBy('id')->limit(6)->get() as $row)$tasks[]=['id'=>$row->id,'title'=>Crypt::decryptString($row->title_encrypted),'due'=>$row->due_on,'overdue'=>$row->due_on<$today];
        }
        $canConflicts=\App\ClubUnio\Pages\PlanningConflicts::canAccess();
        if($canConflicts){
            try{
                $data=app(WorkspaceConflicts::class)->report($actor,$tenant,$today);
                $future=array_values(array_filter($data['rows'],fn($row)=>Time::createFromFormat('!d.m.Y H:i',$row['date'],'Europe/Zurich')->toDateString()>=$today));
                $conflictCount=count($future);$conflicts=array_slice($future,0,4);
            }catch(\Illuminate\Validation\ValidationException){$conflictError=true;}
        }
        return compact('tasks','taskCount','conflicts','conflictCount','conflictError','canTasks','canConflicts');
    }
}
