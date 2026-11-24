<?php
namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Account,Tenant};
use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DailyPlanningAlternatives
{
    public function suggestions(Account $actor,Tenant $tenant,string $kind,int $id,string $date):array
    {
        abort_unless(app(WorkspaceAccess::class)->allowed($actor,$tenant),403);
        $planning=app(TeamPlanningService::class);$bus=app(BusReservationService::class);
        if($kind==='team'){
            $planning->authorizeOperation($actor,$tenant,'update');$row=$planning->event($actor,$tenant,$id,true);
            abort_unless($row->status==='AKTIV'&&!$row->sfv_match_id&&$row->deadline_at!==null,422,trans('daily.linked_sfv'));
            $label=$row->title;
        }else{
            abort_unless($kind==='bus',422);$row=$bus->visible($actor,$tenant)->where('id',$id)->first();abort_unless($row,404);
            abort_unless($bus->canReschedule($actor,$tenant,$row),403);$label=trans('workspace.bus_id',['id'=>$id]);
        }
        $start=Time::parse($row->starts_at)->tz('Europe/Zurich');$end=Time::parse($row->ends_at)->tz('Europe/Zurich');
        abort_unless($start->isFuture()&&$start->toDateString()===$date,422);
        $source=['key'=>$kind.':'.$id,'work_kind'=>$kind,'source_id'=>$id,'date'=>$date,'revision'=>(string)$row->revision,'title'=>$label,'starts_at_iso'=>$start->toIso8601String()];
        $buses=$kind==='bus'?$bus->table($tenant,'buses')->where('active',true)->orderBy('id')->get():collect([(object)['id'=>0,'name'=>$row->location??'']]);
        $items=[];
        foreach([0,30,-30,60,-60,90,-90,120,-120] as $offset)foreach($buses as $vehicle){
            if($offset===0&&($kind==='team'||(int)$vehicle->id===(int)$row->bus_id))continue;
            $at=$start->addMinutes($offset);$until=$end->addMinutes($offset);
            if(!$at->isFuture()||$at->toDateString()!==$date||$until->toDateString()!==$date)continue;
            if($kind==='bus'){
                $busy=$bus->table($tenant,'bus_reservations')->where('active',true)->where('id','<>',$id)->where('bus_id',$vehicle->id)
                    ->whereIn('status',['ANGEFRAGT','GENEHMIGT','UNBEKANNT'])->where('starts_at','<',$until->toIso8601String())->where('ends_at','>',$at->toIso8601String())->exists();
            }else{
                $busy=$planning->table($tenant,'team_events')->where('id','<>',$id)->where('team_season_id',$row->team_season_id)->where('status','AKTIV')
                    ->where('starts_at','<',$until->toIso8601String())->where('ends_at','>',$at->toIso8601String())->exists();
                if(!$busy)$busy=$this->pitchBusy($tenant,$row,$at,$until);
            }
            if($busy)continue;
            $items[]=['label'=>$at->format('d.m.Y H:i').' – '.$until->format('H:i').($vehicle->name?' · '.$vehicle->name:''),
                'starts_at'=>$at->format('Y-m-d\TH:i'),'ends_at'=>$until->format('Y-m-d\TH:i'),'bus_id'=>$kind==='bus'?(int)$vehicle->id:null];
            if(count($items)===5)break 2;
        }
        return compact('source','items');
    }
    private function pitchBusy(Tenant $tenant,object $row,Time $from,Time $to):bool
    {
        $pitch=app(PitchPlanningProjection::class);if(!$pitch->ready())return true;
        $fields=$pitch->fields($tenant);$field=collect($fields)->firstWhere('name',$row->location);
        $entries=$pitch->entries($tenant,$from->toDateString(),$to->toDateString());
        if($field){
            $probe=['key'=>'team:'.$row->id,'field_id'=>$field['id'],'area_id'=>null,'starts_at_iso'=>$from->toIso8601String(),'ends_at_iso'=>$to->toIso8601String()];
            if(PitchPlanningGeometry::conflicts($probe,$entries,$fields))return true;
        }
        foreach($entries as $entry)if($entry['status']==='approved'&&in_array((int)$row->team_season_id,array_map('intval',$entry['team_season_ids']),true)
            &&strtotime($entry['starts_at_iso'])<$to->timestamp&&strtotime($entry['ends_at_iso'])>$from->timestamp)return true;
        return false;
    }
    public function apply(Account $actor,Tenant $tenant,array $source,array $proposal):void
    {
        DB::connection('clubunio')->transaction(function()use($actor,$tenant,$source,$proposal){
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $fresh=$this->suggestions($actor,$tenant,$source['work_kind'],$source['source_id'],$source['date']);
            if($fresh['source']['revision']!==$source['revision']||!in_array($proposal,$fresh['items'],true))throw ValidationException::withMessages(['proposal'=>trans('work_hub.proposal_stale')]);
            if($source['work_kind']==='bus'){
                $bus=app(BusReservationService::class);$row=$bus->visible($actor,$tenant)->where('id',$source['source_id'])->firstOrFail();
                $bus->reschedule($actor,$tenant,$row->id,['revision'=>$row->revision,'bus_id'=>$proposal['bus_id'],'starts_at'=>$proposal['starts_at'],'ends_at'=>$proposal['ends_at'],
                    'location'=>$row->location,'description'=>$row->description,'reason'=>trans('daily.alternative_reason')]);
            }else{
                $planning=app(TeamPlanningService::class);$row=$planning->event($actor,$tenant,$source['source_id'],true);
                $input=array_intersect_key((array)$row,array_flip(['team_season_id','title','kind','mode','status','capacity','description','location','revision']));
                $shift=Time::parse($proposal['starts_at'],'Europe/Zurich')->timestamp-Time::parse($row->starts_at)->timestamp;
                $input+=['starts_at'=>$proposal['starts_at'],'ends_at'=>$proposal['ends_at'],'deadline_at'=>Time::parse($row->deadline_at)->addSeconds($shift)->tz('Europe/Zurich')->format('Y-m-d\TH:i')];
                $planning->saveEvent($actor,$tenant,$row->id,$input);
            }
            app(PlanningChangeSignal::class)->changed($tenant);
        });
    }
}
