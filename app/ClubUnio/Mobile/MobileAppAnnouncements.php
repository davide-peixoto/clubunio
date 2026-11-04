<?php
namespace App\ClubUnio\Mobile;
use App\ClubUnio\Models\{Account,Tenant,MobileAppAnnouncement};
use App\ClubUnio\Services\BexioConnectionService;
use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\{DB,Validator};
use Illuminate\Validation\ValidationException;

final class MobileAppAnnouncements {
    public function allowed(Account $actor,Tenant $tenant): bool { return !request()->attributes->get('clubunio_impersonation')&&BexioConnectionService::canManage($actor->fresh(),$tenant); }
    public function active(Tenant $tenant,bool $member): array {
        if(!app(MobileBootstrapState::class)->enabled()) return [];
        return MobileAppAnnouncement::where('tenant_id',$tenant->id)->where('active',true)
            ->where('starts_at','<=',now()->toIso8601String())->where('ends_at','>',now()->toIso8601String())
            ->when(!$member,fn ($q)=>$q->where('audience','public'))->orderByDesc('starts_at')->orderByDesc('id')->limit(20)->get()
            ->map(fn ($row)=>['id'=>(int)$row->id,'revision'=>(int)$row->revision,'title'=>$row->title,'text'=>$row->body,
                'validFrom'=>$row->starts_at->toIso8601String(),'validUntil'=>$row->ends_at->toIso8601String()])->all();
    }
    public function save(Account $actor,Tenant $tenant,?int $id,array $input): MobileAppAnnouncement {
        abort_unless($this->allowed($actor,$tenant),403);
        abort_if(array_diff(array_keys($input),['title','body','audience','starts_at','ends_at','active','revision','public_acknowledged']),422);
        $d=Validator::make($input,['title'=>'required|string|max:255','body'=>'required|string|max:10000','audience'=>'required|in:public,members',
            'starts_at'=>'required|date','ends_at'=>'required|date|after:starts_at','active'=>'required|boolean','revision'=>'required|integer|min:0',
            'public_acknowledged'=>'nullable|boolean'])->validate();
        if(strlen($d['body'])>20000) throw ValidationException::withMessages(['body'=>'Der Bannertext ist zu lang.']);
        if($d['audience']==='public'&&$d['active']&&!($d['public_acknowledged']??false)) throw ValidationException::withMessages(['public_acknowledged'=>'Die anonyme Veröffentlichung muss ausdrücklich bestätigt werden.']);
        return DB::connection('clubunio')->transaction(function () use ($actor,$tenant,$id,$d) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail(); Account::whereKey($actor->id)->sharedLock()->firstOrFail(); abort_unless($this->allowed($actor,$tenant),403);
            $row=$id?MobileAppAnnouncement::where('tenant_id',$tenant->id)->lockForUpdate()->findOrFail($id):new MobileAppAnnouncement;
            abort_unless($id?(int)$row->revision===(int)$d['revision']:(int)$d['revision']===0,409);
            $old=$id?$row->only(['title','body','audience','starts_at','ends_at','active','revision']):null;
            $row->forceFill(['tenant_id'=>$tenant->id,'title'=>trim($d['title']),'body'=>trim($d['body']),'audience'=>$d['audience'],
                'starts_at'=>Time::parse($d['starts_at']),'ends_at'=>Time::parse($d['ends_at']),'active'=>$d['active'],
                'revision'=>$id?$row->revision+1:1,'created_by'=>$id?$row->created_by:$actor->id,'updated_by'=>$actor->id])->save();
            DB::connection('clubunio')->table('clubora.object_changes')->insert(['tenant_id'=>$tenant->id,'actor_id'=>$actor->id,'object_type'=>'mobile_app_announcements',
                'object_id'=>$row->id,'before_value'=>$old?json_encode($old,JSON_THROW_ON_ERROR):null,
                'after_value'=>json_encode($row->only(['title','body','audience','starts_at','ends_at','active','revision']),JSON_THROW_ON_ERROR)]);
            return $row->refresh();
        });
    }
}
