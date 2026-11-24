<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Account, Competition, Tenant};
use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Reconciles already fetched facts. Each match commits or rolls back as one unit. */
class SfvReconcileService
{
    public function reconcile(Account $actor, Tenant $tenant, string $from, string $to): array
    {
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'competitions', 'sync');
        validator(compact('from', 'to'), ['from'=>'required|date_format:Y-m-d', 'to'=>'required|date_format:Y-m-d|after_or_equal:from'])->validate();
        $rows = app(TeamPlanningService::class)->table($tenant, 'sfv_schedule')
            ->where(fn($query)=>$query->where(fn($dated)=>$dated
                ->where('starts_at', '>=', Time::parse($from, 'Europe/Zurich')->toIso8601String())
                ->where('starts_at', '<', Time::parse($to, 'Europe/Zurich')->addDay()->toIso8601String()))->orWhereNull('starts_at'))
            ->orderBy('starts_at')->orderBy('id')->limit(501)->get();
        if ($rows->count() > 500) throw ValidationException::withMessages(['to'=>trans('daily.sfv_limit')]);
        $result = ['new'=>0, 'updated'=>0, 'unchanged'=>0, 'issues'=>[], 'fetched'=>$rows->count()];
        foreach ($rows as $row) {
            if (!$row->starts_at) {$result['issues'][]=['id'=>(int)$row->id,'title'=>$row->title,'message'=>trans('daily.sfv_time')];continue;}
            if (Time::parse($row->starts_at)->isPast()) {$result['unchanged']++;continue;}
            try {
                $outcome = DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $row) {
                    Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                    app(ModulePermissionService::class)->authorize($actor,$tenant,'competitions','sync');
                    $fresh = app(TeamPlanningService::class)->table($tenant, 'sfv_schedule')->where('id', $row->id)->firstOrFail();
                    return $this->one($actor, $tenant, $fresh);
                });
                $result[$outcome]++;
            } catch (ValidationException $error) {
                $result['issues'][] = ['id'=>(int)$row->id, 'title'=>$row->title, 'message'=>implode(' ', array_merge(...array_values($error->errors())))];
            } catch (HttpExceptionInterface $error) {
                if (!in_array($error->getStatusCode(), [403,404,409,422], true)) throw $error;
                $result['issues'][] = ['id'=>(int)$row->id, 'title'=>$row->title, 'message'=>trans('daily.sfv_review')];
            } catch(\Throwable $error){
                $reference=(string)\Illuminate\Support\Str::uuid();
                \Illuminate\Support\Facades\Log::warning('clubunio.sfv.reconcile_failed',['reference'=>$reference,'tenant_id'=>$tenant->id,'actor_id'=>$actor->id,'exception'=>$error::class]);
                $result['issues'][]=['id'=>(int)$row->id,'title'=>$row->title,'message'=>trans('daily.sfv_error',['reference'=>$reference])];
            }
        }
        app(ModulePermissionService::class)->authorize($actor,$tenant,'competitions','view');
        if($result['new']+$result['updated']>0)app(PlanningChangeSignal::class)->changed($tenant);
        return $result;
    }

    protected function one(Account $actor, Tenant $tenant, object $match): string
    {
        $planning = app(TeamPlanningService::class);
        $starts = Time::parse($match->starts_at)->tz('Europe/Zurich');
        if (!in_array(mb_strtolower(trim((string)$match->state)), ['angesetzt','neu angesetzt','noch nicht ausgetragen'], true)) $this->review('sfv_state');
        // The club can have several seasons, but a game must resolve to exactly one own team.
        $teamIds = $planning->table($tenant, 'teams')->where('active', true)
            ->whereIn('sfv_team_id', array_filter([$match->team_a_id, $match->team_b_id]))->pluck('id');
        $seasonIds = $planning->table($tenant, 'seasons')->where('active', true)
            ->where('starts_on', '<=', $starts->toDateString())->where('ends_on', '>=', $starts->toDateString())->pluck('id');
        $teams = $planning->table($tenant, 'team_seasons')->where('active', true)->whereIn('team_id', $teamIds)->whereIn('season_id', $seasonIds)->get();
        if ($teams->count() !== 1) $this->review('sfv_team');
        $team = $teams->sole();
        $linked = $planning->table($tenant, 'team_events', true)->where('sfv_match_id', $match->match_id)->get();
        if ($linked->count() > 1) $this->review('sfv_team');
        $event = $linked->first();
        if ($event && ($event->deleted_at || (int)$event->team_season_id !== (int)$team->id || $event->status !== 'AKTIV')) $this->review('sfv_local');
        $location = $match->location;
        $preview = null;
        if ($match->home) {
            $preview = app(SfvCompetitionService::class)->preview($actor, $tenant, (int)$match->id, authoritative: true);
            $location = $preview['field'];
        } elseif (Competition::where('tenant_id',$tenant->id)->where('association_match_id',$match->match_id)->exists()) {
            $this->review('sfv_changed');
        }
        $eventChanged=$event && (Time::parse($event->starts_at)->timestamp !== $starts->timestamp || $event->location !== $location);
        $ends = $event ? $starts->addSeconds(Time::parse($event->ends_at)->timestamp-Time::parse($event->starts_at)->timestamp) : $starts->addMinutes(120);
        $changed = $preview && $preview['before'] != $preview['after'];
        // Federation placement is authoritative. Update the existing record, never replace it:
        // rooms, room sides, notes and other local metadata are outside the SFV field set.
        if ($changed) app(SfvCompetitionService::class)->apply($actor,$tenant,$preview,authoritative:true);
        if ($eventChanged) {
            $input=array_intersect_key((array)$event,array_flip(['team_season_id','title','kind','mode','status','capacity','description','revision']));
            $delta=$starts->timestamp-Time::parse($event->starts_at)->timestamp;
            $input+=['starts_at'=>$starts->format('Y-m-d\TH:i'),'ends_at'=>$ends->format('Y-m-d\TH:i'),
                'deadline_at'=>$event->deadline_at ? Time::parse($event->deadline_at)->addSeconds($delta)->tz('Europe/Zurich')->format('Y-m-d\TH:i') : $starts->subHour()->format('Y-m-d\TH:i'),
                'location'=>$location];
            $planning->saveEvent($actor,$tenant,(int)$event->id,$input);
        }
        if (!$event) {
            // Use the existing import contract, including sync/team scope and revision checks.
            app(SfvPlanningService::class)->import($actor,$tenant,(int)$match->id,[
                'sfv_revision'=>$match->revision, 'team_season_id'=>$team->id, 'title'=>$match->title,
                'kind'=>$this->eventKind($match->match_type), 'mode'=>'ANMELDEN', 'status'=>'AKTIV', 'capacity'=>18,
                'starts_at'=>$starts->format('Y-m-d\TH:i'), 'ends_at'=>$ends->tz('Europe/Zurich')->format('Y-m-d\TH:i'),
                'deadline_at'=>$starts->subHour()->format('Y-m-d\TH:i'), 'location'=>$location,
            ]);
            return 'new';
        }
        return $changed || $eventChanged ? 'updated' : 'unchanged';
    }

    protected function eventKind(?string $type): string
    {
        $type = mb_strtolower($type??'');
        return str_contains($type,'freundschaft') ? 'FREUNDSCHAFTSSPIEL' : (str_contains($type,'cup') ? 'CUP' : (str_contains($type,'turnier') ? 'TURNIER' : 'MEISTERSCHAFT'));
    }

    private function review(string $key): never
    {
        throw ValidationException::withMessages(['match'=>trans('daily.'.$key)]);
    }
}
