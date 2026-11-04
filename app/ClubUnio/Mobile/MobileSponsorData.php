<?php
namespace App\ClubUnio\Mobile;
use App\ClubUnio\Models\{Account, Tenant, SponsorRunParticipant};
use App\ClubUnio\Services\{SponsorRunService, SponsorRunPublicService, MemberAccessService};
final class MobileSponsorData {
    public function table(Tenant $tenant, string $table) { return app(SponsorRunService::class)->db()->table('clubora.'.$table)->where('tenant_id', $tenant->id); }
    public function views(Account $actor, Tenant $tenant): array {
        $views = [['Key'=>'mine','Label'=>'Ich / meine Kinder'],['Key'=>'teams','Label'=>'Meine Mannschaften'],['Key'=>'helper','Label'=>'Helferdienst']];
        if (app(SponsorRunService::class)->allows($actor,$tenant)) $views[]=['Key'=>'manage','Label'=>'Verwaltung'];
        return $views;
    }
    public function events(Account $actor, Tenant $tenant, $rows): array {
        if ($rows->isEmpty()) return [];
        foreach ($rows as $row) abort_unless((int)$row->tenant_id===(int)$tenant->id,404);
        $s=app(SponsorRunService::class); $access=app(MemberAccessService::class);
        $update=$s->allows($actor,$tenant,'update'); $approve=$s->allows($actor,$tenant,'approve'); $cancel=$s->allows($actor,$tenant,'cancel');
        $teams=$this->table($tenant,'sponsor_run_event_teams')->whereIn('event_id',$rows->pluck('id'))->get(['event_id','team_season_id'])->groupBy('event_id');
        $seasons=$this->table($tenant,'seasons')->whereIn('id',$rows->pluck('season_id'))->pluck('name','id');
        $helpers=$access->membership($actor,$tenant) ? $this->table($tenant,'sponsor_run_helpers')->where('account_id',$actor->id)->whereIn('event_id',$rows->pluck('id'))
            ->where('valid_from','<=',now()->toIso8601String())->where('valid_until','>=',now()->toIso8601String())->get()->groupBy('event_id') : collect();
        return $rows->map(function($e)use($actor,$tenant,$s,$update,$approve,$cancel,$teams,$seasons,$helpers){
            $operational=in_array($e->status_code,['open','closed'],true)&&$e->event_date->toDateString()<=$s->today();
            $helper=$helpers->get($e->id,collect());
            return $this->event($actor,$tenant,$e,['teams'=>$teams->get($e->id,collect())->pluck('team_season_id')->map(fn($v)=>(int)$v)->all(),
                'season'=>$seasons[$e->season_id]??null,'update'=>$update,'cancel'=>$cancel,
                'rights'=>['checkin'=>$operational&&($update||$helper->contains(fn($h)=>(bool)$h->can_checkin)),
                    'laps'=>$operational&&($update||$helper->contains(fn($h)=>(bool)$h->can_record_laps)),'approve'=>$operational&&$approve]]);
        })->all();
    }
    public function event(Account $actor, Tenant $tenant, object $e, ?array $read=null): array {
        $s=app(SponsorRunService::class); $rights=$read['rights']??$s->capabilities($actor,$tenant,$e->id);
        $teams=$read===null ? $this->table($tenant,'sponsor_run_event_teams')->where('event_id',$e->id)->pluck('team_season_id')->map(fn($v)=>(int)$v)->all() : $read['teams'];
        $season=$read===null ? $this->table($tenant,'seasons')->where('id',$e->season_id)->value('name') : $read['season'];
        return ['EventID'=>(int)$e->id,'SaisonID'=>(int)$e->season_id,'Saison'=>$season,'Title'=>$e->title,'Place'=>$e->place,
            'EventDate'=>$e->event_date->toDateString(),'RegistrationDeadline'=>$e->registration_deadline->toDateString(),
            'Status'=>strtoupper($e->status_code),'Revision'=>(int)$e->revision,'TeamSaisonIDs'=>$teams,'TeamsConfigured'=>count($teams)>0,
            'CanJoin'=>$e->status_code==='open' && $e->registration_deadline->toDateString()>=$s->today(),
            'CanCheckIn'=>$rights['checkin'],'CanRecordLaps'=>$rights['laps'],'CanApprove'=>$rights['approve'],
            'CanUpdate'=>$read['update']??$s->allows($actor,$tenant,'update'),'CanManageHelpers'=>$read['update']??$s->allows($actor,$tenant,'update'),
            'CanCancel'=>$read['cancel']??$s->allows($actor,$tenant,'cancel')];
    }
    public function summary(?array $r): ?array {
        return $r===null ? null : ['PledgeCount'=>(int)$r['pledge_count'],'PerLapCents'=>(int)$r['per_lap_cents'],
            'FixedCents'=>(int)$r['fixed_cents'],'CappedCount'=>(int)$r['capped_count'],'TotalCents'=>$r['total_cents']===null?null:(int)$r['total_cents']];
    }
    public function participants(Account $actor,Tenant $tenant,int $event,$rows,array $summary): array {
        if ($rows->isEmpty()) return [];
        $s=app(SponsorRunService::class); $access=app(MemberAccessService::class); $ids=$rows->pluck('id');
        $models=SponsorRunParticipant::where('tenant_id',$tenant->id)->where('event_id',$event)->whereIn('id',$ids)->get()->keyBy('id');
        abort_unless($models->count()===$rows->count(),404);
        $view=$s->allows($actor,$tenant); $create=$s->allows($actor,$tenant,'create'); $send=$s->allows($actor,$tenant,'send'); $enter=$access->canEnter($actor,$tenant);
        abort_unless($view||$enter,403);
        $mine=$enter&&(!$view||!$create) ? array_fill_keys($s->participants($actor,$tenant,$event,['scope'=>'mine'])->whereIn('p.id',$ids)->pluck('p.id')->all(),true) : [];
        $team=!$view&&$enter ? array_fill_keys($s->participants($actor,$tenant,$event,['scope'=>'teams'])->whereIn('p.id',$ids)->pluck('p.id')->all(),true) : [];
        $own=array_fill_keys($access->represented($actor,$tenant)->whereIn('id',$rows->pluck('member_id'))->pluck('clubora.members.id')->all(),true);
        $public=app(SponsorRunPublicService::class)->publicEnabled($tenant);
        return $rows->map(fn($p)=>$this->participant($actor,$tenant,$p,$summary[$p->id]??null,[
            'view'=>$view||isset($mine[$p->id])||isset($team[$p->id]),'add'=>$create||($enter&&isset($mine[$p->id])),
            'share'=>(bool)$p->required&&!$p->approved_at&&(isset($own[$p->member_id])||$send)&&$public]))->all();
    }
    public function participant(Account $actor,Tenant $tenant,object $p,?array $summary,?array $read=null): array {
        $s=app(SponsorRunService::class);
        $model=$read===null ? SponsorRunParticipant::where('tenant_id',$tenant->id)->findOrFail($p->id) : null;
        $own=$read===null && app(MemberAccessService::class)->represented($actor,$tenant)->whereKey($p->member_id)->exists();
        return ['ParticipantID'=>(int)$p->id,'MitgliederID'=>(int)$p->member_id,'TeamSaisonID'=>(int)$p->team_season_id,
            'Vorname'=>$p->first_name,'Nachname'=>$p->last_name,'Team'=>$p->team_label,'ParticipationRequired'=>(bool)$p->required,
            'CheckedInAt'=>$p->checked_in_at,'Laps'=>$p->final_laps ?? $p->draft_laps,'ApprovedAt'=>$p->approved_at,'Revision'=>(int)$p->revision,
            'CanViewPledges'=>$read['view']??$s->canViewPledges($actor,$tenant,$model),'CanAddPledge'=>$read['add']??$s->canAddPledge($actor,$tenant,$model),
            'CanShare'=>$read['share']??((bool)$p->required && !$p->approved_at && ($own||$s->allows($actor,$tenant,'send')) && app(SponsorRunPublicService::class)->publicEnabled($tenant)),
            'SponsorCount'=>$summary===null?null:(int)$summary['pledge_count']];
    }
}
