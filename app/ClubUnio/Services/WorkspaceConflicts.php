<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Pages\{TeamPlanning, BusReservations, PitchPlanning};
use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\Validator;

class WorkspaceConflicts
{
    public function report(Account $actor, Tenant $tenant, string $date): array
    {
        abort_unless(app(WorkspaceAccess::class)->allowed($actor,$tenant),403);
        Validator::make(compact('date'), ['date' => 'required|date_format:Y-m-d|after_or_equal:2000-01-01|before_or_equal:2100-12-20'])->validate();
        $from = Time::parse($date,'Europe/Zurich')->startOfWeek(); $until = $from->addWeek();
        $rows = []; $sources = [];
        if (TeamPlanning::canAccess()) {
            $data = app(PlanningCalendarService::class)->calendar($actor,$tenant,['view'=>'week','date'=>$date]);
            $sources[] = trans('workspace.teams'); $seen = [];
            foreach ($data['conflicts'] as $id => $others) {
                $event = $data['events']->firstWhere('id',$id);
                foreach ($others as $other) {
                    $pair = [min($id,$other->id),max($id,$other->id)]; $key = implode(':',$pair);
                    if (isset($seen[$key])) continue; $seen[$key] = true;
                    $rows[] = ['kind'=>'team','date'=>Time::parse($event->starts_at)->tz('Europe/Zurich')->format('d.m.Y H:i'),
                        'title'=>$event->title.' · '.$other->title, 'reason'=>trans('workspace.overlap_team'), 'url'=>route('clubunio.planning.show',[$tenant->slug,$id])];
                }
            }
        }
        if (BusReservations::canAccess()) {
            $sources[] = trans('workspace.buses');
            $buses = app(BusReservationService::class)->visible($actor,$tenant)->whereIn('status',['ANGEFRAGT','GENEHMIGT'])
                ->where('starts_at','<',$until->toIso8601String())->where('ends_at','>',$from->toIso8601String())->orderBy('starts_at')->limit(501)->get();
            if ($buses->count()>500) throw \Illuminate\Validation\ValidationException::withMessages(['date'=>trans('workspace.too_many')]);
            foreach ($this->busPairs($buses->all()) as [$left,$right]) $rows[] = ['kind'=>'bus','date'=>Time::parse($left->starts_at)->tz('Europe/Zurich')->format('d.m.Y H:i'),
                'title'=>trans('workspace.bus_pair',['left'=>$left->id,'right'=>$right->id]), 'reason'=>trans('workspace.overlap_bus'), 'url'=>route('clubunio.bus.show',[$tenant->slug,$left->id])];
        }
        if (PitchPlanning::canAccess()) {
            $sources[] = trans('workspace.facilities');
            $data = app(PitchPlanningService::class)->calendar($actor,$tenant,['from'=>$from->toDateString(),'to'=>$until->subDay()->toDateString()]);
            $seen = [];
            foreach ($data['entries'] as $entry) {
                if (in_array($entry['status'],['rejected','cancelled'],true) || $entry['source']==='block') continue;
                foreach ($entry['conflicts'] as $conflict) {
                    $pair = [$entry['key'],$conflict['key'] ?? 'unavailable']; sort($pair); $key = implode('|',$pair);
                    if (isset($seen[$key])) continue; $seen[$key]=true;
                    $rows[] = ['kind'=>'pitch','date'=>Time::parse($entry['starts_at_iso'])->tz('Europe/Zurich')->format('d.m.Y H:i'),
                        'title'=>$entry['title'], 'reason'=>trans('workspace.conflict_'.$conflict['type']), 'url'=>PitchPlanning::getUrl(['date'=>$entry['date']])];
                }
            }
        }
        $rows=collect($rows)->sortBy(fn($row)=>Time::createFromFormat('!d.m.Y H:i',$row['date'],'Europe/Zurich')->getTimestamp())->values()->all();
        return ['from'=>$from,'until'=>$until->subDay(),'sources'=>$sources,'rows'=>$rows];
    }

    /** Half-open intervals: adjacent reservations do not conflict. Inputs are already authorized. */
    public function busPairs(array $rows): array
    {
        $pairs=[];
        foreach ($rows as $i=>$left) foreach (array_slice($rows,$i+1) as $right) {
            if ((int)$left->bus_id !== (int)$right->bus_id) continue;
            if (Time::parse($left->starts_at)<Time::parse($right->ends_at) && Time::parse($right->starts_at)<Time::parse($left->ends_at)) $pairs[]=[$left,$right];
        }
        return $pairs;
    }
}
