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
    public function event(Account $actor, Tenant $tenant, object $e): array {
        $s=app(SponsorRunService::class); $rights=$s->capabilities($actor,$tenant,$e->id);
        $teams=$this->table($tenant,'sponsor_run_event_teams')->where('event_id',$e->id)->pluck('team_season_id')->map(fn($v)=>(int)$v)->all();
        $season=$this->table($tenant,'seasons')->where('id',$e->season_id)->value('name');
        return ['EventID'=>(int)$e->id,'SaisonID'=>(int)$e->season_id,'Saison'=>$season,'Title'=>$e->title,'Place'=>$e->place,
            'EventDate'=>$e->event_date->toDateString(),'RegistrationDeadline'=>$e->registration_deadline->toDateString(),
            'Status'=>strtoupper($e->status_code),'Revision'=>(int)$e->revision,'TeamSaisonIDs'=>$teams,'TeamsConfigured'=>count($teams)>0,
            'CanJoin'=>$e->status_code==='open' && $e->registration_deadline->toDateString()>=$s->today(),
            'CanCheckIn'=>$rights['checkin'],'CanRecordLaps'=>$rights['laps'],'CanApprove'=>$rights['approve'],
            'CanUpdate'=>$s->allows($actor,$tenant,'update'),'CanManageHelpers'=>$s->allows($actor,$tenant,'update'),
            'CanCancel'=>$s->allows($actor,$tenant,'cancel')];
    }
    public function summary(?array $r): ?array {
        return $r===null ? null : ['PledgeCount'=>(int)$r['pledge_count'],'PerLapCents'=>(int)$r['per_lap_cents'],
            'FixedCents'=>(int)$r['fixed_cents'],'CappedCount'=>(int)$r['capped_count'],'TotalCents'=>$r['total_cents']===null?null:(int)$r['total_cents']];
    }
    public function participant(Account $actor,Tenant $tenant,object $p,?array $summary): array {
        $s=app(SponsorRunService::class); $model=SponsorRunParticipant::where('tenant_id',$tenant->id)->findOrFail($p->id);
        $own=app(MemberAccessService::class)->represented($actor,$tenant)->whereKey($p->member_id)->exists();
        return ['ParticipantID'=>(int)$p->id,'MitgliederID'=>(int)$p->member_id,'TeamSaisonID'=>(int)$p->team_season_id,
            'Vorname'=>$p->first_name,'Nachname'=>$p->last_name,'Team'=>$p->team_label,'ParticipationRequired'=>(bool)$p->required,
            'CheckedInAt'=>$p->checked_in_at,'Laps'=>$p->final_laps ?? $p->draft_laps,'ApprovedAt'=>$p->approved_at,'Revision'=>(int)$p->revision,
            'CanViewPledges'=>$s->canViewPledges($actor,$tenant,$model),'CanAddPledge'=>$s->canAddPledge($actor,$tenant,$model),
            'CanShare'=>(bool)$p->required && !$p->approved_at && ($own||$s->allows($actor,$tenant,'send')) && app(SponsorRunPublicService::class)->publicEnabled($tenant),
            'SponsorCount'=>$summary===null?null:(int)$summary['pledge_count']];
    }
}
