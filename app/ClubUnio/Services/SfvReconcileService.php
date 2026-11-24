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
            $preview = app(SfvCompetitionService::class)->preview($actor, $tenant, (int)$match->id);
            if (SfvRescheduleImpact::requiresReview($preview)) $this->review('sfv_changed');
            $location = $preview['field'];
            $projection = app(PitchPlanningProjection::class);
            if (!$projection->ready()) $this->review('sfv_review');
            $record = $preview['target_id'] ? Competition::where('tenant_id',$tenant->id)->findOrFail($preview['target_id']) : new Competition;
            $record->forceFill($preview['after'] + ['tenant_id'=>$tenant->id]);
            if (!$record->exists) $record->id = 0;
            $settings = $preview['target_id'] ? $projection->table('pitch_competition_settings',$tenant)->where('competition_id',$record->id)->first() : null;
            $entry = $projection->competition($record, $settings, [(int)$team->id]);
            if (PitchPlanningGeometry::conflicts($entry, $projection->entries($tenant,$entry['date'],Time::parse($entry['ends_at_iso'])->toDateString()), $projection->fields($tenant))) $this->review('sfv_conflict');
        } elseif (Competition::where('tenant_id',$tenant->id)->where('association_match_id',$match->match_id)->exists()) {
            $this->review('sfv_changed');
        }
        if ($event && (Time::parse($event->starts_at)->timestamp !== $starts->timestamp || $event->location !== $location)) $this->review('sfv_changed');
        $ends = $event ? Time::parse($event->ends_at) : $starts->addMinutes(120);
        if ($planning->table($tenant,'team_events')->where('team_season_id',$team->id)->where('status','AKTIV')
            ->when($event,fn($q)=>$q->where('id','<>',$event->id))->where('starts_at','<',$ends->toIso8601String())->where('ends_at','>',$starts->toIso8601String())->exists()) $this->review('sfv_conflict');
        $changed = $preview && $preview['before'] != $preview['after'];
        if ($changed) app(SfvCompetitionService::class)->apply($actor,$tenant,$preview,requireSingleReview:true);
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
        return $changed ? 'updated' : 'unchanged';
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
