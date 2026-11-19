<?php
namespace App\ClubUnio\Mobile;
use App\ClubUnio\Models\{Account,Tenant,MobileKeybox};
use App\ClubUnio\Services\{AccountAdministrationService,MemberAccessService};
use Illuminate\Support\Facades\{Crypt,DB,Validator};
use Illuminate\Validation\ValidationException;

final class MobileKeyboxAdministration {
    public function allowed(Account $actor,Tenant $tenant): bool {
        return config('clubunio-mobile.keybox.administration_enabled',false)&&!request()->attributes->get('clubunio_impersonation')
            &&!$actor->fresh()?->api_only&&app(AccountAdministrationService::class)->allowed($actor,$tenant);
    }
    private function authorize(Account $actor,Tenant $tenant): void { abort_unless($this->allowed($actor,$tenant),403); }
    private function table(string $table) { return DB::connection('clubunio')->table('clubunio.'.$table); }
    private function audit(Account $actor,Tenant $tenant,int $box,string $action,array $details): void {
        $this->table('object_changes')->insert(['tenant_id'=>$tenant->id,'actor_id'=>$actor->id,'object_type'=>'mobile_keybox','object_id'=>$box,
            'after_value'=>json_encode(['action'=>$action]+$details,JSON_THROW_ON_ERROR)]);
    }
    public function saveBox(Account $actor,Tenant $tenant,?int $id,array $input): MobileKeybox {
        $this->authorize($actor,$tenant);
        abort_if(array_diff(array_keys($input),['name','provider_device_id','bus_id','active','revision','reason']),422);
        $d=Validator::make($input,['name'=>'required|string|max:160','provider_device_id'=>'nullable|string|max:120|regex:/^[a-zA-Z0-9_-]+$/D',
            'bus_id'=>'nullable|integer|min:1','active'=>'required|boolean','revision'=>'required|integer|min:0','reason'=>'required|string|min:10|max:500'])->validate();
        if(!$id&&empty($d['provider_device_id'])) throw ValidationException::withMessages(['provider_device_id'=>'Provider-Gerätezuordnung erforderlich.']);
        return DB::connection('clubunio')->transaction(function () use ($actor,$tenant,$id,$d) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail(); Account::whereKey($actor->id)->sharedLock()->firstOrFail(); $this->authorize($actor,$tenant);
            $row=$id?MobileKeybox::where('tenant_id',$tenant->id)->lockForUpdate()->findOrFail($id):new MobileKeybox;
            abort_unless($id?(int)$row->revision===(int)$d['revision']:(int)$d['revision']===0,409);
            if(!empty($d['bus_id'])) {
                abort_unless($this->table('buses')->where('tenant_id',$tenant->id)->where('id',$d['bus_id'])->where('active',true)->exists(),422);
                abort_if(MobileKeybox::where('tenant_id',$tenant->id)->where('bus_id',$d['bus_id'])->where('active',true)->when($id,fn ($q)=>$q->where('id','<>',$id))->exists(),409,'Der Bus hat bereits eine aktive persönliche Boxzuordnung.');
            }
            $device=empty($d['provider_device_id'])?Crypt::decryptString($row->provider_device_cipher):$d['provider_device_id'];
            // Device reuse would share physical PINs across independent local grants.
            foreach(MobileKeybox::where('tenant_id',$tenant->id)->when($id,fn ($q)=>$q->where('id','<>',$id))->get() as $other) {
                abort_if(hash_equals(Crypt::decryptString($other->provider_device_cipher),$device),409,'Dieses Providergerät hat bereits eine persönliche Boxzuordnung.');
            }
            if($id) app(MobileKeyboxService::class)->invalidateBox($tenant,$id);
            $row->forceFill(['tenant_id'=>$tenant->id,'name'=>trim($d['name']),'bus_id'=>$d['bus_id']??null,'active'=>$d['active'],
                'provider_device_cipher'=>empty($d['provider_device_id'])?$row->provider_device_cipher:Crypt::encryptString($device),
                'revision'=>$id?$row->revision+1:1])->save();
            $this->audit($actor,$tenant,(int)$row->id,$id?'box_revised':'box_created',['revision'=>$row->revision,'active'=>$row->active,'reason'=>$d['reason']]);
            return $row->refresh();
        });
    }
    public function accountOptions(Account $actor,Tenant $tenant): array {
        $this->authorize($actor,$tenant);
        return $tenant->accounts()->orderBy('display_name')->get()
            ->mapWithKeys(fn ($account)=>[(int)$account->id=>$account->display_name.' · '.$account->username.(!$account->active||$account->api_only?' (kein aktiver persönlicher Zugang)':'')])->all();
    }
    public function grantSnapshot(Account $actor,Tenant $tenant,int $box,int $account): array {
        $this->authorize($actor,$tenant); MobileKeybox::where('tenant_id',$tenant->id)->findOrFail($box);
        abort_unless(array_key_exists($account,$this->accountOptions($actor,$tenant)),404);
        $row=$this->table('mobile_keybox_grants')->where('tenant_id',$tenant->id)->where('keybox_id',$box)->where('account_id',$account)->first();
        return ['revision'=>(int)($row?->revision??0),'active'=>(bool)($row?->active??false)];
    }
    public function saveGrant(Account $actor,Tenant $tenant,int $box,array $input): void {
        $this->authorize($actor,$tenant);
        abort_if(array_diff(array_keys($input),['account_id','active','revision','box_revision','reason']),422);
        $d=Validator::make($input,['account_id'=>'required|integer|min:1','active'=>'required|boolean','revision'=>'required|integer|min:0','box_revision'=>'required|integer|min:1','reason'=>'required|string|min:10|max:500'])->validate();
        DB::connection('clubunio')->transaction(function () use ($actor,$tenant,$box,$d) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail(); Account::whereKey($actor->id)->sharedLock()->firstOrFail(); $this->authorize($actor,$tenant);
            $boxRow=MobileKeybox::where('tenant_id',$tenant->id)->lockForUpdate()->findOrFail($box);
            abort_unless((int)$boxRow->revision===(int)$d['box_revision'],409);
            $account=Account::find($d['account_id']);
            $membership=$this->table('tenant_accounts')->where('tenant_id',$tenant->id)->where('account_id',$d['account_id'])->first();
            abort_unless($account&&$membership,422);
            $query=$this->table('mobile_keybox_grants')->where('tenant_id',$tenant->id)->where('keybox_id',$box)->where('account_id',$account->id);
            $old=(clone $query)->lockForUpdate()->first(); abort_unless((int)($old?->revision??0)===(int)$d['revision'],409);
            if($d['active']) abort_unless($account->active&&!$account->api_only&&$membership->role!=='profile'&&app(MemberAccessService::class)->canEnter($account,$tenant),422);
            else abort_unless($old,404);
            app(MobileKeyboxService::class)->invalidateBox($tenant,$box,(int)$account->id);
            $this->table('mobile_keybox_grants')->updateOrInsert(['tenant_id'=>$tenant->id,'keybox_id'=>$box,'account_id'=>$account->id],
                ['active'=>$d['active'],'revision'=>(int)$d['revision']+1]);
            $this->audit($actor,$tenant,$box,'grant_revised',['account_id'=>(int)$account->id,'revision'=>(int)$d['revision']+1,'active'=>$d['active'],'reason'=>$d['reason']]);
        });
    }
    public function providerSnapshot(Account $actor,Tenant $tenant): array {
        $this->authorize($actor,$tenant);
        $values=$this->table('tenant_settings')->where('tenant_id',$tenant->id)->where('area','keybox')
            ->whereIn('key',['IGLOOHOME_CLIENT_ID','IGLOOHOME_CLIENT_SECRET'])->orderBy('key')->get(['key','encrypted_value','enabled','is_secret'])->all();
        return ['fingerprint'=>hash_hmac('sha256',json_encode($values,JSON_THROW_ON_ERROR),(string)config('app.key')),
            'configured'=>count($values)===2];
    }
    public function saveProvider(Account $actor,Tenant $tenant,array $input): void {
        $this->authorize($actor,$tenant);
        abort_if(array_diff(array_keys($input),['fingerprint','client_id','client_secret','reason']),422);
        $d=Validator::make($input,['fingerprint'=>'required|string|regex:/^[0-9a-f]{64}$/D','client_id'=>'nullable|string|max:4096|not_regex:/[\r\n]/',
            'client_secret'=>'nullable|string|max:4096|not_regex:/[\r\n]/','reason'=>'required|string|min:10|max:500'])->validate();
        DB::connection('clubunio')->transaction(function () use ($actor,$tenant,$d) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail(); Account::whereKey($actor->id)->sharedLock()->firstOrFail(); $this->authorize($actor,$tenant);
            abort_unless(hash_equals($this->providerSnapshot($actor,$tenant)['fingerprint'],$d['fingerprint']),409);
            foreach(['IGLOOHOME_CLIENT_ID'=>'client_id','IGLOOHOME_CLIENT_SECRET'=>'client_secret'] as $key=>$field) {
                $query=$this->table('tenant_settings')->where('tenant_id',$tenant->id)->where('area','keybox')->where('key',$key);
                if(empty($d[$field])) { abort_unless((clone $query)->where('enabled',true)->where('is_secret',true)->exists(),422); continue; }
                $this->table('tenant_settings')->updateOrInsert(['tenant_id'=>$tenant->id,'area'=>'keybox','key'=>$key],['label'=>$key,'value_type'=>'string',
                    'encrypted_value'=>Crypt::encryptString($d[$field]),'is_secret'=>true,'enabled'=>true]);
            }
            foreach(MobileKeybox::where('tenant_id',$tenant->id)->get() as $box) app(MobileKeyboxService::class)->invalidateBox($tenant,(int)$box->id);
            $this->audit($actor,$tenant,0,'provider_revised',['reason'=>$d['reason']]);
        });
    }
}
