<?php
namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Account,Tenant};
use Carbon\CarbonImmutable as Time;

class WorkConflictSolver
{
    public function suggestions(Account $actor,Tenant $tenant,string $key,string $date): array
    {
        abort_unless(app(WorkspaceAccess::class)->allowed($actor,$tenant),403);
        validator(compact('date','key'),['date'=>'required|date_format:Y-m-d','key'=>'required|string|max:120'])->validate();
        $data=app(PitchPlanningService::class)->calendar($actor,$tenant,['from'=>$date,'to'=>$date]);
        $source=collect($data['entries'])->firstWhere('key',$key);abort_unless($source,404);
        abort_unless(in_array($source['source'],['training','request'],true)&&!in_array($source['status'],['rejected','cancelled'],true),422);
        $module=$source['source']==='training'?'training_slots':'competitions';
        abort_unless($data['capabilities']['manage']&&app(ModulePermissionService::class)->allows($actor,$tenant,$module,'update'),403);
        return ['source'=>$source,'items'=>$this->alternatives($source,$data['entries'],$data['fields'])];
    }
    public function alternatives(array $source,array $entries,array $fields): array
    {
        $result=[];$start=Time::parse($source['starts_at_iso']);
        foreach([0,30,-30,60,-60,90,120] as $offset)foreach($fields as $field){
            if(!$field['available'])continue;
            $at=$start->addMinutes($offset);$end=$at->addMinutes($source['duration_minutes']);
            if($at->toDateString()!==$start->toDateString()||$end->toDateString()!==$at->toDateString())continue;
            if($offset===0&&(int)$field['id']===(int)$source['field_id'])continue;
            $candidate=array_replace($source,['field_id'=>$field['id'],'area_id'=>(int)$field['id']===(int)$source['field_id']?$source['area_id']:null,
                'starts_at_iso'=>$at->toIso8601String(),'ends_at_iso'=>$end->toIso8601String()]);
            if(PitchPlanningGeometry::conflicts($candidate,$entries,$fields))continue;
            $teamConflict=false;
            foreach($entries as $other)if($other['key']!==$source['key']&&$other['status']==='approved'&&array_intersect($other['team_season_ids'],$source['team_season_ids'])
                &&strtotime($candidate['starts_at_iso'])<strtotime($other['ends_at_iso'])&&strtotime($other['starts_at_iso'])<strtotime($candidate['ends_at_iso'])){$teamConflict=true;break;}
            if($teamConflict)continue;
            $result[]=['label'=>$at->format('d.m.Y H:i').' – '.$end->format('H:i').' · '.$field['name'],
                'date'=>$at->toDateString(),'starts_at'=>$at->format('H:i'),'field_id'=>(int)$field['id'],'area_id'=>$candidate['area_id'],'duration_minutes'=>$source['duration_minutes']];
            if(count($result)>=5)return $result;
        }
        return $result;
    }
    public function apply(Account $actor,Tenant $tenant,array $source,array $proposal): void
    {
        \Illuminate\Support\Facades\DB::connection('clubunio')->transaction(function()use($actor,$tenant,$source,$proposal){
        Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
        // Keep team and pitch writers serialized while rebuilding the proposal.
        $fresh=$this->suggestions($actor,$tenant,$source['key'],$source['date']);
        if($fresh['source']['revision']!==$source['revision'])throw \Illuminate\Validation\ValidationException::withMessages(['revision'=>trans('workspace.stale')]);
        if(!in_array($proposal,$fresh['items'],true))throw \Illuminate\Validation\ValidationException::withMessages(['proposal'=>trans('work_hub.proposal_stale')]);
        $input=$proposal;unset($input['label']);$input['revision']=$source['revision'];
        app(PitchPlanningService::class)->move($actor,$tenant,$source['key'],$input);
        });
    }
}
