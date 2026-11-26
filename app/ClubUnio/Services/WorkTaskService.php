<?php
namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Account,Tenant};
use Illuminate\Support\Facades\{DB,Crypt,Validator};
use Illuminate\Validation\ValidationException;

class WorkTaskService
{
    public function allows(Account $actor,Tenant $tenant,string $function): bool
    {
        return app(WorkspaceAccess::class)->allowed($actor,$tenant) && app(ModulePermissionService::class)->allows($actor,$tenant,'tasks',$function);
    }
    public function table(Tenant $tenant) { return DB::connection('clubunio')->table('clubunio.workspace_tasks')->where('tenant_id',$tenant->id); }
    public function visible(Account $actor,Tenant $tenant)
    {
        abort_unless($this->allows($actor,$tenant,'view'),403);
        return $this->table($tenant)->when(!$this->allows($actor,$tenant,'assign'),fn($q)=>$q->where(fn($q)=>$q->where('created_by',$actor->id)->orWhere('assigned_to',$actor->id)));
    }
    public function get(Account $actor,Tenant $tenant,int $id): object
    {
        $row=$this->visible($actor,$tenant)->where('id',$id)->first();abort_unless($row,404);
        $row->title=Crypt::decryptString($row->title_encrypted);$row->body=Crypt::decryptString($row->body_encrypted);return $row;
    }
    public function assignees(Account $actor,Tenant $tenant): array
    {
        if(!$this->allows($actor,$tenant,'assign')) return [$actor->id=>$actor->display_name];
        $rows=$tenant->accounts()->where('active',true)->where('api_only',false)->orderBy('display_name')->limit(501)->get();
        if($rows->count()>500) throw ValidationException::withMessages(['assigned_to'=>trans('work_hub.narrow_assignees')]);
        return $rows->filter(fn($target)=>$this->allows($target,$tenant,'view'))->pluck('display_name','id')->all();
    }
    public function save(Account $actor,Tenant $tenant,?int $id,array $input): int
    {
        abort_unless($this->allows($actor,$tenant,$id?'update':'create'),403);
        $data=Validator::make($input,['title'=>'required|string|max:160','body'=>'nullable|string|max:4000','assigned_to'=>'required|integer|min:1','due_on'=>'nullable|date_format:Y-m-d','revision'=>$id?'required|integer|min:1':'prohibited'])->validate();
        return DB::connection('clubunio')->transaction(function()use($actor,$tenant,$id,$data){
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->allows($actor,$tenant,$id?'update':'create'),403);
            $old=$id?$this->visible($actor,$tenant)->where('id',$id)->lockForUpdate()->first():null;abort_unless(!$id||$old,404);
            if($old && (int)$old->revision!==(int)$data['revision']) throw ValidationException::withMessages(['revision'=>trans('workspace.stale')]);
            abort_unless(!$old||$old->state!=='archived',409);
            if((int)$data['assigned_to']!==(int)($old->assigned_to??$actor->id)) abort_unless($this->allows($actor,$tenant,'assign'),403);
            $target=Account::whereKey($data['assigned_to'])->where('active',true)->where('api_only',false)->first();
            abort_unless($target&&$this->allows($target,$tenant,'view'),422,trans('work_hub.invalid_assignee'));
            $revision=($old->revision??0)+1;
            $values=['title_encrypted'=>Crypt::encryptString(trim($data['title'])),'body_encrypted'=>Crypt::encryptString($data['body']??''),
                'assigned_to'=>$target->id,'due_on'=>$data['due_on']??null,'revision'=>$revision,'updated_at'=>now()];
            if($id)$this->table($tenant)->where('id',$id)->update($values);
            else $id=$this->table($tenant)->insertGetId($values+['tenant_id'=>$tenant->id,'created_by'=>$actor->id,'state'=>'open']);
            $this->audit($actor,$tenant,$id,$old?'updated':'created',$revision);
            if((int)$target->id!==(int)$actor->id && (!$old || (int)$old->assigned_to!==(int)$target->id)) {
                $locale=app(LanguagePreferences::class)->resolve($target->preferred_locale,$tenant->default_locale);
                DB::connection('clubunio')->table('clubunio.notifications')->insert(['tenant_id'=>$tenant->id,'account_id'=>$target->id,
                    'title'=>trans('work_hub.task_notice',[], $locale),'body'=>'','object_type'=>'workspace_task','object_id'=>$id]);
            }
            return $id;
        });
    }
    public function transition(Account $actor,Tenant $tenant,int $id,int $revision,string $state): void
    {
        Validator::make(compact('state'),['state'=>'required|in:open,doing,done,archived'])->validate();
        DB::connection('clubunio')->transaction(function()use($actor,$tenant,$id,$revision,$state){
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $row=$this->visible($actor,$tenant)->where('id',$id)->lockForUpdate()->first();abort_unless($row,404);
            $function=$state==='archived'?'deactivate':($row->state==='archived'?'restore':'complete');
            abort_unless($this->allows($actor,$tenant,$function),403);
            if((int)$row->revision!==$revision) throw ValidationException::withMessages(['revision'=>trans('workspace.stale')]);
            abort_unless($row->state!=='archived'||$state==='open',422);
            if($row->state===$state)return;
            $this->table($tenant)->where('id',$id)->update(['state'=>$state,'revision'=>$revision+1,'updated_at'=>now()]);
            $this->audit($actor,$tenant,$id,$state,$revision+1);
        });
    }
    private function audit(Account $actor,Tenant $tenant,int $id,string $operation,int $revision): void
    {
        DB::connection('clubunio')->table('clubunio.workspace_task_events')->insert(['tenant_id'=>$tenant->id,'task_id'=>$id,'actor_id'=>$actor->id,'operation'=>$operation,'revision'=>$revision]);
    }
}
