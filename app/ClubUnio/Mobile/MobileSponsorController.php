<?php
namespace App\ClubUnio\Mobile;
use App\ClubUnio\Models\{Account, Tenant, SponsorRunParticipant};
use App\ClubUnio\Services\{SponsorRunService, SponsorRunPublicService, SponsorRunBillingService, MemberAccessService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MobileSponsorController implements \Illuminate\Routing\Controllers\HasMiddleware {
    public static function middleware(): array { return [MobileSponsorReady::class]; }
    private function context(Request $r): array { return [$r->attributes->get('mobile.actor'),$r->attributes->get('mobile.tenant'),app(SponsorRunService::class),app(MobileSponsorData::class)]; }
    private function json(array $data) { return response()->json(['data'=>['success'=>true]+$data]); }
    private function page(Request $r): array { return $r->validate(['start'=>'sometimes|integer|min:0|max:100000','view'=>'sometimes|in:mine,teams,helper,manage','search'=>'sometimes|nullable|string|max:100','TeamSaisonID'=>'sometimes|integer|min:1','SaisonID'=>'sometimes|integer|min:1']); }
    private function season(Tenant $tenant,?int $id): ?int {
        if ($id===null) return app(MobileSeasonScope::class)->current($tenant)?->id;
        abort_unless(app(MobileSponsorData::class)->table($tenant,'seasons')->where('id',$id)->where('active',true)->exists(),422);
        return $id;
    }
    public function events(Request $r) {
        [$a,$t,$s,$d]=$this->context($r); $p=$this->page($r); $season=$this->season($t,isset($p['SaisonID'])?(int)$p['SaisonID']:null);
        $q=$s->events($a,$t,['scope'=>$p['view']??'mine','season_id'=>$season]); if (!$season) $q->whereRaw('1=0');
        return $this->json(['Events'=>$q->offset($p['start']??0)->limit(20)->get()->map(fn($e)=>$d->event($a,$t,$e))->all(),
            'CanManage'=>$s->allows($a,$t),'CanCreate'=>$s->allows($a,$t,'create'),'Views'=>$d->views($a,$t),
            'SelectedSaisonID'=>$season,'Seasons'=>$d->table($t,'seasons')->where('active',true)->orderByDesc('id')->get(['id','name'])->map(fn($v)=>['SaisonID'=>(int)$v->id,'Saison'=>$v->name])->all()]);
    }
    private function visibleEvent($a,$t,$s,int $id): object {
        abort_unless($s->events($a,$t,['scope'=>'all'])->where('id',$id)->exists(),404);
        return $s->event($t,$id);
    }
    public function participants(Request $r) {
        [$a,$t,$s,$d]=$this->context($r); $id=(int)$r->route('event'); $e=$this->visibleEvent($a,$t,$s,$id); $p=$this->page($r);
        $rows=$s->participants($a,$t,$id,['scope'=>$p['view']??'mine','search'=>$p['search']??'','team_season_id'=>$p['TeamSaisonID']??null])->offset($p['start']??0)->limit(20)->get();
        $summary=$s->summaries($a,$t,$rows->pluck('id')->all());
        $teams=$s->participants($a,$t,$id,['scope'=>$p['view']??'mine'])->reorder()->select('p.team_season_id','t.label as team_label')->distinct()->limit(500)->get();
        return $this->json(['Event'=>$d->event($a,$t,$e),'Views'=>$d->views($a,$t),'Teams'=>$teams->map(fn($v)=>['TeamSaisonID'=>(int)$v->team_season_id,'Mannschaftsname'=>$v->team_label])->values()->all(),
            'Participants'=>$rows->map(fn($v)=>$d->participant($a,$t,$v,$summary[$v->id]??null))->all()]);
    }
    public function teams(Request $r) {
        [$a,$t,$s,$d]=$this->context($r); $s->authorize($a,$t,'create'); $v=$r->validate(['SaisonID'=>'required|integer|min:1']); $id=$this->season($t,(int)$v['SaisonID']);
        $q=$s->db()->table('clubora.team_seasons as ts')->join('clubora.teams as tm',fn($j)=>$j->on('tm.id','=','ts.team_id')->on('tm.tenant_id','=','ts.tenant_id'))->where('ts.tenant_id',$t->id)->where('ts.season_id',$id)->where('ts.active',true)->where('tm.active',true);
        return $this->json(['Teams'=>$q->orderBy('ts.label')->get(['ts.id','ts.label'])->map(fn($v)=>['TeamSaisonID'=>(int)$v->id,'Mannschaftsname'=>$v->label])->all()]);
    }
    public function saveEvent(Request $r) {
        return $this->write($r,function($a,$t,$s,$d)use($r){
            $id=$r->route('event')?(int)$r->route('event'):null;
            $v=$r->validate(['SaisonID'=>'required|integer|min:1','Title'=>'required|string|max:160','Place'=>'required|string|max:160','EventDate'=>'required|date_format:Y-m-d',
                'RegistrationDeadline'=>'required|date_format:Y-m-d','Status'=>'required|in:DRAFT,OPEN,CLOSED,CANCELLED','Revision'=>'sometimes|integer|min:0',
                'TeamSaisonIDs'=>'required|array|min:1|max:500','TeamSaisonIDs.*'=>'required|integer|min:1|distinct','Reason'=>'nullable|string|max:1000']);
            $s->authorize($a,$t,$id?'update':'create');
            $input=['season_id'=>(int)$v['SaisonID'],'title'=>$v['Title'],'place'=>$v['Place'],'event_date'=>$v['EventDate'],'registration_deadline'=>$v['RegistrationDeadline'],
                'status_code'=>strtolower($v['Status']),'revision'=>(int)($v['Revision']??0),'team_ids'=>array_map('intval',$v['TeamSaisonIDs']),'reason'=>$v['Reason']??null]; sort($input['team_ids']);
            if($id) $event=$s->saveEvent($a,$t,$id,$input);
            else {
                $key=$r->validate(['RequestKey'=>'required|uuid'])['RequestKey']; $hash=$this->fingerprint($input); $old=$this->oldCommand($a,$t,$key,'create_event',$hash);
                if($old) $event=$s->event($t,$old->event_id);
                else { $event=$s->saveEvent($a,$t,null,$input); $this->record($a,$t,$key,'create_event',$hash,$event->id,null,$event->revision); }
            }
            return $this->json(['Event'=>$d->event($a,$t,$event)]);
        });
    }
    private function participant($a,$t,$s,int $id): object {
        $p=SponsorRunParticipant::where('tenant_id',$t->id)->findOrFail($id);
        abort_unless($s->participants($a,$t,$p->event_id)->where('p.id',$id)->exists(),404);
        return $p;
    }
    public function pledges(Request $r) {
        [$a,$t,$s,$d]=$this->context($r); $p=$this->participant($a,$t,$s,(int)$r->route('participant')); $summary=$s->summaries($a,$t,[$p->id]);
        $v=$r->validate(['start'=>'sometimes|integer|min:0|max:100000']);
        $rows=$s->pledges($a,$t,$p->id)->offset($v['start']??0)->limit(21)->get();
        return $this->json(['HasMore'=>$rows->count()>20,'Pledges'=>$rows->take(20)->map(fn($g)=>[
            'PledgeID'=>(int)$g->id,'SponsorName'=>$g->sponsor_name,'AmountType'=>strtoupper($g->amount_type),'AmountCents'=>(int)$g->amount_cents,
            'CapCents'=>$g->cap_cents===null?null:(int)$g->cap_cents,'TotalCents'=>SponsorRunService::totalCents($g,$p->final_laps)])->all(),
            'Summary'=>$d->summary($summary[$p->id]??null),'BillingNotice'=>'CHF-Zusagen. Endbetrag erst nach verbindlicher Rundenfreigabe. Es wird keine Zahlung ausgeloest.']);
    }
    public function pledge(Request $r) {
        return $this->write($r,function($a,$t,$s,$d)use($r){
            $this->participant($a,$t,$s,(int)$r->route('participant'));
            $map=['RequestKey'=>'request_key','SponsorName'=>'sponsor_name','AmountCents'=>'amount_cents','CapCents'=>'cap_cents','Company'=>'company','FirstName'=>'first_name','LastName'=>'last_name',
                'Street'=>'street','HouseNumber'=>'house_number','PostalCode'=>'postal_code','City'=>'city','Country'=>'country','Email'=>'email','CollectorConfirmed'=>'collector_confirmed'];
            $v=[]; foreach($map as $from=>$to) if($r->exists($from)) $v[$to]=$r->input($from);
            foreach(['PaymentMode'=>'payment_mode','AmountType'=>'amount_type','RecipientType'=>'recipient_type'] as $from=>$to) $v[$to]=strtolower((string)$r->input($from));
            $g=$s->addPledge($a,$t,(int)$r->route('participant'),$v); return $this->json(['PledgeID'=>(int)$g->id]);
        });
    }
    public function operations(Request $r) { return $this->operation($r,false); }
    public function approve(Request $r) { return $this->operation($r,true); }
    private function operation(Request $r,bool $approve) {
        return $this->write($r,function($a,$t,$s,$d)use($r,$approve){
            $v=$r->validate(['Revision'=>'required|integer|min:1','Action'=>$approve?'nullable':'required|in:checkin,laps','CheckedIn'=>'nullable|boolean','Laps'=>'nullable|integer|min:0|max:10000']);
            if($approve) $r->validate(['ConfirmFinal'=>'required|accepted']);
            $p=$this->participant($a,$t,$s,(int)$r->route('participant')); $action=$approve?'approve':$v['Action'];
            $p=$s->operations($a,$t,$p->id,(int)$v['Revision'],$action,$action==='checkin'?($v['CheckedIn']??false):($v['Laps']??null));
            return $this->json(['ParticipantID'=>(int)$p->id,'Revision'=>(int)$p->revision]);
        });
    }
    public function helpers(Request $r) {
        [$a,$t,$s]=$this->context($r); $s->authorize($a,$t,'update');
        return $this->json(['Helpers'=>array_map(fn($h)=>['AccountID'=>(int)$h['account_id'],'Username'=>$h['display_name'],'Revision'=>(int)$h['revision'],
            'CanCheckIn'=>(bool)$h['can_checkin'],'CanRecordLaps'=>(bool)$h['can_record_laps'],'ValidFrom'=>$h['valid_from'],'ValidUntil'=>$h['valid_until']],$s->helpers($a,$t,(int)$r->route('event')))]);
    }
    public function helperCandidates(Request $r) {
        [$a,$t,$s]=$this->context($r); $s->authorize($a,$t,'update'); $s->event($t,(int)$r->route('event')); $v=$r->validate(['search'=>'required|string|min:2|max:100']);
        $q=$s->db()->table('clubora.accounts as a')->join('clubora.tenant_accounts as ta','ta.account_id','=','a.id')->where('ta.tenant_id',$t->id)->where('a.active',true)->where('a.api_only',false)
            ->whereExists(fn($q)=>$q->selectRaw('1')->from('clubora.account_member_links as l')->join('clubora.members as m',fn($j)=>$j->on('m.id','=','l.member_id')->on('m.tenant_id','=','l.tenant_id'))->whereColumn('l.account_id','a.id')->where('l.tenant_id',$t->id)->where('l.kind','self')->where('l.state','approved')->whereIn('m.id',app(MemberAccessService::class)->activeMembers($t)->select('clubora.members.id')))
            ->where('a.display_name','ilike','%'.$v['search'].'%')->orderBy('a.display_name')->limit(30)->get(['a.id','a.display_name']);
        return $this->json(['Candidates'=>$q->map(fn($v)=>['AccountID'=>(int)$v->id,'Username'=>$v->display_name,'Vorname'=>$v->display_name,'Nachname'=>''])->all()]);
    }
    public function saveHelper(Request $r) {
        return $this->write($r,function($a,$t,$s)use($r){
            $v=$r->validate(['AccountID'=>'required|integer|min:1','Revision'=>'required|integer|min:0','CanCheckIn'=>'required|boolean','CanRecordLaps'=>'required|boolean','ValidFrom'=>'required|date_format:Y-m-d','ValidUntil'=>'required|date_format:Y-m-d|after_or_equal:ValidFrom']);
            $s->saveHelper($a,$t,(int)$r->route('event'),['account_id'=>$v['AccountID'],'revision'=>$v['Revision'],'can_checkin'=>$v['CanCheckIn'],'can_record_laps'=>$v['CanRecordLaps'],
                'valid_from'=>\Carbon\CarbonImmutable::parse($v['ValidFrom'],'Europe/Zurich')->startOfDay()->toIso8601String(),'valid_until'=>\Carbon\CarbonImmutable::parse($v['ValidUntil'],'Europe/Zurich')->endOfDay()->toIso8601String()]);
            return $this->json([]);
        });
    }
    public function share(Request $r) {
        return $this->write($r,function($a,$t,$s,$d)use($r){
            $p=$this->participant($a,$t,$s,(int)$r->route('participant')); $public=app(SponsorRunPublicService::class); $action=$r->isMethod('GET')?'show':(string)$r->input('Action');
            $q=$d->table($t,'sponsor_run_links')->where('participant_id',$p->id); $current=(clone $q)->first();
            if($action==='show') $result=$public->share($a,$t,$p->id,'show');
            else {
                $v=$r->validate(['Action'=>'required|in:create,rotate,revoke','RequestKey'=>'required|uuid','Revision'=>'required|integer|min:0','DisplayName'=>'nullable|string|max:80','Consent'=>'nullable|boolean']);
                // Check authority before command replay without mutating an existing link.
                abort_unless($s->allows($a,$t,'send')||app(MemberAccessService::class)->represented($a,$t)->whereKey($p->member_id)->exists(),403);
                $payload=['participant'=>$p->id,'action'=>$action,'revision'=>(int)$v['Revision'],'alias'=>$v['DisplayName']??'','consent'=>(bool)($v['Consent']??false)];
                $hash=$this->fingerprint($payload); $old=$this->oldCommand($a,$t,$v['RequestKey'],$action.'_link',$hash);
                if($old) {
                    abort_unless((int)($current->revision??0)===(int)$old->result_revision,409,'Link wurde inzwischen geaendert. Bitte neu laden.');
                    $result=$action==='revoke'?['active'=>false,'url'=>null]:$public->share($a,$t,$p->id,'show');
                } else {
                    abort_unless((int)($current->revision??0)===(int)$v['Revision'],409,'Link wurde inzwischen geaendert.');
                    $result=$public->share($a,$t,$p->id,$action,['alias'=>$payload['alias'],'consent'=>$payload['consent']]);
                    $revision=(int)((clone $q)->value('revision')??0); $this->record($a,$t,$v['RequestKey'],$action.'_link',$hash,$p->event_id,$p->id,$revision);
                }
            }
            $link=(clone $q)->first();
            $shareUrl=app(MobileSponsorShareIdentity::class)->url($r,$t,$link,($result['url']??null)!==null);
            return $this->json(['TenantID'=>(int)$t->id,'TenantName'=>$t->name,'ShareUrl'=>$shareUrl,'DisplayName'=>$result['alias']??'','ExpiresOn'=>$result['expires_at']??null,'Revision'=>(int)($link->revision??0)]);
        });
    }
    public function billing(Request $r) {
        [$a,$t,$s,$d]=$this->context($r); app(SponsorRunBillingService::class)->authorize($a,$t); $event=(int)$r->route('event'); $s->event($t,$event); $v=$r->validate(['start'=>'sometimes|integer|min:0|max:100000','search'=>'sometimes|nullable|string|max:100']);
        $q=$s->db()->table('clubora.sponsor_run_invoices as i')->where('i.tenant_id',$t->id)->where('i.event_id',$event)
            ->join('clubora.sponsor_run_pledges as g',fn($j)=>$j->on('g.id','=','i.pledge_id')->on('g.tenant_id','=','i.tenant_id'))
            ->join('clubora.sponsor_run_participants as p',fn($j)=>$j->on('p.id','=','g.participant_id')->on('p.tenant_id','=','g.tenant_id'))
            ->join('clubora.members as m',fn($j)=>$j->on('m.id','=','p.member_id')->on('m.tenant_id','=','p.tenant_id'))
            ->when(trim($v['search']??''),fn($q,$search)=>$q->where(fn($q)=>$q->where('g.sponsor_name','ilike','%'.$search.'%')->orWhereRaw("concat_ws(' ',m.first_name,m.last_name) ILIKE ?",['%'.$search.'%'])))
            ->orderBy('i.id');
        // Local snapshots only: never provider catalogues, synchronization, mail or payment commands.
        $rows=$q->offset($v['start']??0)->limit(20)->get(['i.id','i.pledge_id','i.state','i.total_cents','i.revision','i.remote_id','i.remote_status','i.paid_cents','g.sponsor_name','g.payment_mode','g.amount_type','m.first_name','m.last_name']);
        return $this->json(['Settings'=>['Enabled'=>false,'Ready'=>false,'ReadOnly'=>true,'Message'=>'Lokaler Rechnungsstatus. Externe Rechnungen, PDFs und Versand werden im Vereinsbackend verwaltet.'], 'Candidates'=>$rows->map(fn($v)=>[
            'InvoiceID'=>(int)$v->id,'PledgeID'=>(int)$v->pledge_id,'State'=>strtoupper($v->state),'TotalCents'=>(int)$v->total_cents,'Revision'=>(int)$v->revision,
            'StateLabel'=>SponsorRunBillingService::STATES[$v->state]??$v->state,
            'SponsorName'=>$v->sponsor_name,'ParticipantName'=>trim($v->first_name.' '.$v->last_name),'PaymentMode'=>strtoupper($v->payment_mode),'AmountType'=>strtoupper($v->amount_type),
            'BexioInvoiceID'=>$v->remote_id,'BexioStatus'=>$v->remote_status,'PaidCents'=>$v->paid_cents,'ReadOnly'=>true])->all(),'HasMore'=>$rows->count()===20,'Notice'=>'Lokaler Rechnungsstatus. Externe Abrechnung erfolgt im Vereinsbackend.']);
    }
    private function fingerprint(array $v): string { ksort($v); return hash('sha256',json_encode($v,JSON_THROW_ON_ERROR)); }
    private function oldCommand($a,$t,string $key,string $operation,string $hash): ?object {
        $row=DB::connection('clubunio')->table('clubora.mobile_sponsor_commands')->where('tenant_id',$t->id)->where('account_id',$a->id)->where('command_key',$key)->first();
        if($row) abort_unless($row->operation===$operation && hash_equals($row->fingerprint,$hash),409,'Anfragekennung gehoert zu einem anderen Vorgang.');
        return $row;
    }
    private function record($a,$t,string $key,string $operation,string $hash,int $event,?int $participant,int $revision): void {
        DB::connection('clubunio')->table('clubora.mobile_sponsor_commands')->insert(['tenant_id'=>$t->id,'account_id'=>$a->id,'command_key'=>$key,'operation'=>$operation,
            'fingerprint'=>$hash,'event_id'=>$event,'participant_id'=>$participant,'result_revision'=>$revision]);
    }
    private function write(Request $r,\Closure $action) {
        return DB::connection('clubunio')->transaction(function()use($r,$action){
            [$a,$t,$s,$d]=$this->context($r); Tenant::whereKey($t->id)->lockForUpdate()->firstOrFail();
            DB::connection('clubunio')->table('clubora.mobile_sessions')->where('id',$r->attributes->get('mobile.session')->id)->sharedLock()->firstOrFail();
            $a=app(MobileSessions::class)->authenticate($r); app(MobileAccess::class)->enter($a,$t); return $action($a,$t,$s,$d);
        });
    }
}
