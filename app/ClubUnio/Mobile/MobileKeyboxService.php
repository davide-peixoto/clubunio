<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account,Tenant};
use App\ClubUnio\Services\{BexioConnectionService,BusKeyboxAccessService,MemberAccessService};
use Carbon\CarbonImmutable as Time;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt,DB,RateLimiter};
use Illuminate\Support\Str;

final class MobileKeyboxService
{
    private function table(string $name) { return DB::connection('clubunio')->table('clubora.mobile_keybox_'.$name); }
    private function boxes(Tenant $tenant) { return DB::connection('clubunio')->table('clubora.mobile_keyboxes')->where('tenant_id',$tenant->id); }
    private function allowed(Account $actor,Tenant $tenant,object $box): bool
    {
        if (!$actor->fresh()?->active || !$box->active) return false;
        app(MobileAccess::class)->enter($actor,$tenant);
        if (BexioConnectionService::canManage($actor->fresh(),$tenant)) return true;
        if ($box->bus_id && !in_array(app(MemberAccessService::class)->membership($actor,$tenant)?->role,['office','technical_lead'],true)) return false;
        return $this->table('grants')->where('tenant_id',$tenant->id)->where('keybox_id',$box->id)->where('account_id',$actor->id)->where('active',true)->exists();
    }
    private function box(Account $actor,Tenant $tenant,int $id): object
    {
        $box=$this->boxes($tenant)->where('id',$id)->first(); abort_unless($box&&$this->allowed($actor,$tenant,$box),404); return $box;
    }
    private function operational(Tenant $tenant,object $box,bool $checkStale=true): bool
    {
        if (!config('clubunio-mobile.enabled',false)||!config('clubunio-mobile.native_keybox_enabled',false)||!config('clubunio-mobile.keybox.issuance_enabled',false)
            ||!config('clubunio-mobile.keybox.operations_reviewed',false)||!config('clubunio-mobile.keybox.offline_revocation_acknowledged',false)
            ||!in_array((int)$box->id,config('clubunio-mobile.keybox.approved_keybox_ids.'.$tenant->slug,[]),true)) return false;
        // A known offline PIN cannot be made invalid by deleting a database row.
        if ($this->table('commands')->where('tenant_id',$tenant->id)->where('keybox_id',$box->id)->where(fn ($q)=>$q->where('remote_revocation_state','required')->orWhere('state','attention'))
            ->where('valid_until','>',now()->toIso8601String())->exists()) return false;
        try {
            app(MobileKeyboxProvider::class)->credentials($tenant);
            if ($checkStale) {
                $issued=$this->table('commands')->where('tenant_id',$tenant->id)->where('keybox_id',$box->id)->where('state','issued')
                    ->where('valid_until','>',now()->toIso8601String())->limit(1001)->get();
                if ($issued->count()>1000) return false;
                foreach ($issued as $row) {
                    $actor=Account::find($row->account_id);
                    if (!$actor||!$this->current($actor,$tenant,$box,$row)) return false;
                }
            }
            return true;
        } catch (\Throwable) { return false; }
    }
    private function dto(object $box,bool $issue): array { return ['id'=>(int)$box->id,'name'=>$box->name,'revision'=>(int)$box->revision,'issuanceEnabled'=>$issue,'reservationOnly'=>false]; }
    public function index(Account $actor,Tenant $tenant): array
    {
        $rows=[]; foreach ($this->boxes($tenant)->where('active',true)->orderBy('name')->get() as $box) {
            if ($this->allowed($actor,$tenant,$box)) $rows[]=$this->dto($box,$this->operational($tenant,$box));
        }
        return ['boxes'=>$rows,'providerCredentialsExposed'=>false];
    }
    public function profileTenants(Account $actor,?Tenant $bound=null): array
    {
        if (!config('clubunio-mobile.native_keybox_enabled',false)) return [];
        $ids=[];
        foreach ($actor->tenants()->get() as $tenant) {
            if ($bound && !$bound->is($tenant)) continue;
            try { if ($this->index($actor,$tenant)['boxes']) $ids[]=(int)$tenant->id; }
            catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) { if (!in_array($error->getStatusCode(),[403,404],true)) throw $error; }
        }
        return $ids;
    }
    public function show(Account $actor,Tenant $tenant,int $id): array
    {
        $box=$this->box($actor,$tenant,$id); $pins=[];
        foreach ($this->table('commands')->where('tenant_id',$tenant->id)->where('keybox_id',$id)->where('account_id',$actor->id)->whereNull('reservation_id')
            ->where('state','issued')->where('valid_until','>',now()->toIso8601String())->get() as $row) {
            $dto=$this->result($actor,$tenant,$box,$row); if (isset($dto['pin'])) $pins[]=$dto;
        }
        return $this->dto($box,$this->operational($tenant,$box))+['activePins'=>$pins];
    }
    private function reservation(Account $actor,Tenant $tenant,int $id): array
    {
        $row=app(MobileBusData::class)->visible($actor,$tenant)->where('id',$id)->first(); abort_unless($row,404);
        $inspection=app(BusKeyboxAccessService::class)->inspect($actor,$tenant,$id);
        abort_unless($inspection['eligible'],403);
        $box=$this->boxes($tenant)->where('bus_id',$row->bus_id)->where('active',true)->first();
        return [$row,$inspection,$box];
    }
    public function bus(Account $actor,Tenant $tenant,int $id): array
    {
        [$reservation,$inspection,$box]=$this->reservation($actor,$tenant,$id);
        $data=['available'=>(bool)$box,'issuanceEnabled'=>$box&&$inspection['inWindow']&&$this->operational($tenant,$box),'simulationMode'=>false,
            'eligible'=>true,'inWindow'=>$inspection['inWindow'],'revision'=>(int)$reservation->revision,
            'startsAt'=>$inspection['from']->toIso8601String(),'expiresAt'=>$inspection['until']->toIso8601String(),
            'physicalRevocation'=>'offline_requires_operations'];
        if (!$box) return $data;
        $data['keyboxId']=(int)$box->id; $data['keyboxRevision']=(int)$box->revision;
        $row=$this->table('commands')->where('tenant_id',$tenant->id)->where('keybox_id',$box->id)->where('reservation_id',$id)->where('account_id',$actor->id)
            ->whereIn('state',['held','issuing','issued','attention'])->orderByDesc('created_at')->first();
        if ($row) $data=array_replace($data,$this->result($actor,$tenant,$box,$row,$reservation));
        return $data;
    }
    private function snapshot(Account $actor,Tenant $tenant,object $box,?object $reservation,Time $from,Time $until): array
    {
        return ['actor_revision'=>(int)$actor->fresh()->revision,'box_revision'=>(int)$box->revision,'device_sha256'=>hash('sha256',$box->provider_device_cipher),
            'grant_revision'=>(int)($this->table('grants')->where('tenant_id',$tenant->id)->where('keybox_id',$box->id)->where('account_id',$actor->id)->value('revision')??0),
            'reservation_revision'=>$reservation?(int)$reservation->revision:null,'reservation_bus'=>$reservation?(int)$reservation->bus_id:null,
            'reservation_start'=>$reservation?Time::parse($reservation->starts_at)->utc()->toIso8601String():null,'reservation_end'=>$reservation?Time::parse($reservation->ends_at)->utc()->toIso8601String():null,
            'from'=>$from->utc()->toIso8601String(),'until'=>$until->utc()->toIso8601String()];
    }
    public function prepare(Request $request,?int $boxId,?int $reservationId): array
    {
        abort_if(array_diff(array_keys($request->all()),['request_key','keybox_revision','reservation_revision']),422);
        $d=$request->validate(['request_key'=>'required|uuid','keybox_revision'=>'required|integer|min:1','reservation_revision'=>$reservationId?'required|integer|min:1':'prohibited']);
        $actor=$request->attributes->get('mobile.actor'); $tenant=$request->attributes->get('mobile.tenant'); $session=$request->attributes->get('mobile.session');
        $result=DB::connection('clubunio')->transaction(function () use ($request,$actor,$tenant,$session,$d,$boxId,$reservationId) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            DB::connection('clubunio')->table('clubora.mobile_sessions')->where('id',$session->id)->sharedLock()->firstOrFail();
            $actor=app(MobileSessions::class)->authenticate($request); app(MobileAccess::class)->enter($actor,$tenant);
            $reservation=null;
            if ($reservationId) {
                [$reservation,$inspection,$box]=$this->reservation($actor,$tenant,$reservationId);
                abort_unless($box&&$inspection['inWindow'],409); $from=$inspection['from']->startOfHour()->max(Time::now('Europe/Zurich')->startOfHour()); $until=$inspection['until']->ceilHour();
                abort_unless((int)$reservation->revision===(int)$d['reservation_revision'],409);
            } else {
                $box=$this->box($actor,$tenant,$boxId); $from=Time::now('Europe/Zurich')->startOfHour(); $until=$from->addHours(168);
            }
            abort_unless((int)$box->revision===(int)$d['keybox_revision'],409);
            abort_unless($until->diffInHours($from,true)<=672&&$this->operational($tenant,$box),503);
            $snapshot=$this->snapshot($actor,$tenant,$box,$reservation,$from,$until);
            $fingerprint=hash('sha256',json_encode([$box->id,$snapshot['box_revision'],$snapshot['device_sha256'],$reservationId,
                $snapshot['reservation_revision'],$snapshot['from'],$snapshot['until']],JSON_THROW_ON_ERROR));
            $old=$this->table('commands')->where('tenant_id',$tenant->id)->where('account_id',$actor->id)->where('request_key',$d['request_key'])->first();
            if ($old) { abort_unless((int)$old->keybox_id===(int)$box->id&&(int)$old->keybox_revision===(int)$d['keybox_revision']
                &&($old->reservation_id===null?$reservationId===null:(int)$old->reservation_id===$reservationId)
                &&($reservationId===null||(int)$old->reservation_revision===(int)$d['reservation_revision']),409); return $this->result($actor,$tenant,$box,$old,$reservation); }
            $rate='mobile-keybox:'.$tenant->id.':'.$actor->id; abort_if(RateLimiter::tooManyAttempts($rate,5),429); RateLimiter::hit($rate,3600);
            // Do not create another physical code for an uncertain or current same window.
            $old=$this->table('commands')->where('tenant_id',$tenant->id)->where('keybox_id',$box->id)->where('scope_key',$fingerprint)->first();
            if ($old) { abort_unless((int)$old->account_id===(int)$actor->id,409); return $this->result($actor,$tenant,$box,$old,$reservation); }
            abort_if($this->table('commands')->where('tenant_id',$tenant->id)->where('keybox_id',$box->id)->whereIn('state',['held','issuing'])
                ->where('valid_until','>',now()->toIso8601String())->exists(),409);
            $id=(string)Str::uuid();
            $snapshot['provider']=app(MobileKeyboxProvider::class)->fingerprint(app(MobileKeyboxProvider::class)->credentials($tenant));
            $this->table('commands')->insert(['id'=>$id,'tenant_id'=>$tenant->id,'keybox_id'=>$box->id,'account_id'=>$actor->id,'session_id'=>$session->id,
                'request_key'=>$d['request_key'],'scope_key'=>$fingerprint,'snapshot_encrypted'=>Crypt::encryptString(json_encode($snapshot,JSON_THROW_ON_ERROR)),
                'reservation_id'=>$reservationId,'keybox_revision'=>$box->revision,'reservation_revision'=>$reservation?->revision,
                'valid_from'=>$from->toIso8601String(),'valid_until'=>$until->toIso8601String()]);
            DB::connection('clubunio')->afterCommit(function () use ($id) {
                if (config('clubunio-mobile.keybox.dispatch_after_response',true)) app()->terminating(fn ()=>$this->execute($id));
            });
            return ['id'=>$id,'keyboxId'=>(int)$box->id,'state'=>'held','startsAt'=>$from->toIso8601String(),'expiresAt'=>$until->toIso8601String()];
        });
        return $result;
    }
    private function current(Account $actor,Tenant $tenant,object $box,object $row,?object $reservation=null): bool
    {
        try {
            if (!config('clubunio-mobile.native_keybox_enabled',false)||!$this->operational($tenant,$box,false)) return false;
            if ($row->reservation_id) { [$reservation,$inspection,$freshBox]=$this->reservation($actor,$tenant,(int)$row->reservation_id); if (!$inspection['inWindow']||!$freshBox||(int)$freshBox->id!==(int)$box->id) return false; }
            elseif (!$this->allowed($actor,$tenant,$box)) return false;
            $saved=json_decode(Crypt::decryptString($row->snapshot_encrypted),true,32,JSON_THROW_ON_ERROR);
            $snapshot=$this->snapshot($actor,$tenant,$box,$reservation,Time::parse($row->valid_from),Time::parse($row->valid_until));
            foreach ($snapshot as $key=>$value) if (($saved[$key]??null)!==$value) return false;
            return hash_equals($saved['provider'],app(MobileKeyboxProvider::class)->fingerprint(app(MobileKeyboxProvider::class)->credentials($tenant)))
                &&DB::connection('clubunio')->table('clubora.mobile_sessions')->where('id',$row->session_id)->where('account_id',$actor->id)->whereNull('revoked_at')->where('expires_at','>',now()->toIso8601String())->exists();
        } catch (\Throwable) { return false; }
    }
    private function result(Account $actor,Tenant $tenant,object $box,object $row,?object $reservation=null): array
    {
        $data=['id'=>$row->id,'keyboxId'=>(int)$box->id,'state'=>$row->state,'startsAt'=>Time::parse($row->valid_from)->toIso8601String(),'expiresAt'=>Time::parse($row->valid_until)->toIso8601String()];
        if ($row->state==='issued'&&Time::parse($row->valid_until)->isFuture()&&$this->current($actor,$tenant,$box,$row,$reservation)) {
            $pin=json_decode(Crypt::decryptString($row->pin_encrypted),true,8,JSON_THROW_ON_ERROR); $data+=['pin'=>$pin['pin'],'pinId'=>$pin['pinId']];
        } elseif ($row->state==='issued') { $data['state']='invalidated'; }
        return $data;
    }
    public function command(Account $actor,Tenant $tenant,int $boxId,string $id): array
    {
        $row=$this->table('commands')->where('tenant_id',$tenant->id)->where('keybox_id',$boxId)->where('account_id',$actor->id)->where('id',$id)->first(); abort_unless($row,404);
        $box=$this->boxes($tenant)->where('id',$boxId)->first(); abort_unless($box,404);
        if ($row->reservation_id) $this->reservation($actor,$tenant,(int)$row->reservation_id); else $this->box($actor,$tenant,$boxId);
        return $this->result($actor,$tenant,$box,$row);
    }
    public function execute(string $id): void
    {
        $work=DB::connection('clubunio')->transaction(function () use ($id) {
            $first=$this->table('commands')->where('id',$id)->first(); if (!$first) return null;
            $tenant=Tenant::whereKey($first->tenant_id)->lockForUpdate()->firstOrFail();
            $row=$this->table('commands')->where('id',$id)->where('state','held')->lockForUpdate()->first(); if (!$row) return null;
            $actor=Account::find($row->account_id); $box=$this->boxes($tenant)->where('id',$row->keybox_id)->first();
            if (!$actor||!$box||!$this->operational($tenant,$box)||!$this->current($actor,$tenant,$box,$row)) { $this->invalidateRows([$id],false); return null; }
            $this->table('commands')->where('id',$id)->update(['state'=>'issuing','provider_started_at'=>now()->toIso8601String()]);
            return [$row,$actor,$tenant,$box];
        });
        if (!$work) return;
        [$row,$actor,$tenant,$box]=$work;
        try {
            $provider=app(MobileKeyboxProvider::class); $credentials=$provider->credentials($tenant);
            $pin=$provider->hourly($credentials,Crypt::decryptString($box->provider_device_cipher),Time::parse($row->valid_from),Time::parse($row->valid_until),$actor->display_name);
            DB::connection('clubunio')->transaction(function () use ($row,$actor,$tenant,$box,$pin) {
                Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                $fresh=$this->table('commands')->where('id',$row->id)->lockForUpdate()->first();
                $box=$this->boxes($tenant)->where('id',$box->id)->first();
                if ($fresh->state!=='issuing'||!$this->operational($tenant,$box)||!$this->current($actor,$tenant,$box,$fresh)) { $this->invalidateRows([$row->id],true); return; }
                $this->table('commands')->where('id',$row->id)->update(['state'=>'issued','pin_encrypted'=>Crypt::encryptString(json_encode($pin,JSON_THROW_ON_ERROR))]);
            });
        } catch (\Throwable) {
            $this->table('commands')->where('id',$id)->where('state','issuing')->update(['state'=>'attention','pin_encrypted'=>null]);
            // No exception/response/credential/PIN logging and no automatic retry.
        }
    }
    private function invalidateRows(array $ids,bool $physical): void
    {
        $this->table('commands')->whereIn('id',$ids)->update(['state'=>'invalidated','pin_encrypted'=>null,'invalidated_at'=>now()->toIso8601String(),
            'remote_revocation_state'=>$physical?'required':'not_available']);
    }
    /** Administrative box/grant changes run inside the same tenant-locked transaction. */
    public function invalidateBox(Tenant $tenant,int $keybox,?int $account=null): void
    {
        abort_unless(DB::connection('clubunio')->transactionLevel()>0,500);
        $rows=$this->table('commands')->where('tenant_id',$tenant->id)->where('keybox_id',$keybox)->where('state','<>','invalidated')
            ->when($account!==null,fn ($q)=>$q->where('account_id',$account))->get();
        foreach($rows as $row) $this->invalidateRows([$row->id],$row->provider_started_at!==null||in_array($row->state,['issued','attention'],true));
    }
    /** No-op while the separate native feature is closed. Never sends a physical revoke. */
    public function invalidateReservation(Tenant $tenant,int $reservationId): void
    {
        if (!config('clubunio-mobile.native_keybox_enabled',false)) return;
        $rows=$this->table('commands')->where('tenant_id',$tenant->id)->where('reservation_id',$reservationId)->whereNotIn('state',['invalidated'])->get();
        foreach ($rows as $row) $this->invalidateRows([$row->id],$row->provider_started_at!==null);
    }
    /** Administrative revocation is local; a previously exposed offline PIN needs physical action. */
    public function revokeGrant(Account $actor,Tenant $tenant,int $keybox,int $account,int $revision): void
    {
        DB::connection('clubunio')->transaction(function () use ($actor,$tenant,$keybox,$account,$revision) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless(BexioConnectionService::canManage($actor->fresh(),$tenant),403);
            $grant=$this->table('grants')->where('tenant_id',$tenant->id)->where('keybox_id',$keybox)->where('account_id',$account)->lockForUpdate()->first();
            abort_unless($grant,404); abort_unless((int)$grant->revision===$revision,409);
            $this->table('grants')->where('tenant_id',$tenant->id)->where('keybox_id',$keybox)->where('account_id',$account)->update(['active'=>false,'revision'=>$revision+1]);
            foreach ($this->table('commands')->where('tenant_id',$tenant->id)->where('keybox_id',$keybox)->where('account_id',$account)->where('state','<>','invalidated')->get() as $row) {
                $this->invalidateRows([$row->id],$row->provider_started_at!==null);
            }
        });
    }
}
