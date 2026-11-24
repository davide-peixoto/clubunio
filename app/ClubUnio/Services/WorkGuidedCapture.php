<?php
namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Account,Tenant};
use App\ClubUnio\Resources\{MemberResource,ExpenseResource,ProfileUi};
use Illuminate\Support\Facades\DB;

class WorkGuidedCapture
{
    public function kinds(): array
    {
        $items=[];
        if(MemberResource::canCreate())$items['member']=trans('work_hub.member');
        if(ExpenseResource::canViewAny()&&ProfileUi::allows('expenses','create'))$items['expense']=trans('work_hub.expense_draft');
        if(\App\ClubUnio\Pages\TeamPlanning::canAccess()&&ProfileUi::allows('planning','create'))$items['event']=trans('work_hub.event');
        return $items;
    }
    public function validateStep(string $kind,array $data,int $step): array
    {
        $rules=match($kind){
            'member'=> $step===1?['first_name'=>'required|string|max:255','last_name'=>'required|string|max:255']:['status_id'=>'required|integer|min:1','type_id'=>'required|integer|min:1','billing_recipient_id'=>'required|integer|min:1','mother_email'=>'nullable|email:rfc|max:254','father_email'=>'nullable|email:rfc|max:254','email'=>'nullable|email:rfc|max:254','birth_date'=>'nullable|date_format:Y-m-d|before_or_equal:today','city'=>'nullable|string|max:255'],
            'expense'=> $step===1?['member_id'=>'required|integer|min:1','team_season_id'=>'required|integer|min:1','bank_account_id'=>'required|integer|min:1','round_id'=>'required|integer|min:1']:['expense_type_id'=>'required|integer|min:1','amount'=>'required|numeric|min:0.01|max:999999999','notes'=>'nullable|string|max:4000'],
            'event'=> $step===1?['team_season_id'=>'required|integer|min:1','title'=>'required|string|max:255']:['kind'=>'required|string|max:40','mode'=>'required|string|max:40','starts_at'=>'required|date_format:Y-m-d\TH:i','ends_at'=>'required|date_format:Y-m-d\TH:i|after:starts_at','deadline_at'=>'required|date_format:Y-m-d\TH:i|before_or_equal:starts_at','location'=>'nullable|string|max:255'],
            default=>abort(422),
        };
        return validator($data,$rules)->validate();
    }
    public function save(Account $actor,Tenant $tenant,string $kind,array $data,string $command,?\Illuminate\Http\UploadedFile $receipt=null): string
    {
        abort_unless(app(WorkspaceAccess::class)->allowed($actor,$tenant)&&isset($this->kinds()[$kind]),403);
        $first=$this->validateStep($kind,$data,1);$second=$this->validateStep($kind,$data,2);
        validator(compact('command'),['command'=>'required|uuid'])->validate();
        $verdict=null;
        if($receipt){
            abort_unless($kind==='expense',422);app(ModulePermissionService::class)->authorize($actor,$tenant,'expenses','upload');
            validator(['receipt'=>$receipt],['receipt'=>'required|file|max:10240|mimetypes:application/pdf,image/jpeg,image/png'])->validate();
            try{$verdict=app(\App\ClubUnio\Mobile\MalwareScanner::class)->scan($receipt->get());}
            catch(\Throwable){throw \Illuminate\Validation\ValidationException::withMessages(['receipt'=>trans('daily.receipt_scan_unavailable')]);}
            if(!is_string($verdict['version']??null)||$verdict['version']==='')throw \Illuminate\Validation\ValidationException::withMessages(['receipt'=>trans('daily.receipt_scan_unavailable')]);
            if(($verdict['clean']??null)!==true)throw \Illuminate\Validation\ValidationException::withMessages(['receipt'=>trans('daily.receipt_rejected')]);
        }
        $receiptHash=$receipt?hash_file('sha256',$receipt->getRealPath()):null;
        $hashParts=[$kind,$first,$second];if($receiptHash!==null)$hashParts[]=$receiptHash;
        $hash=hash('sha256',json_encode($hashParts,JSON_THROW_ON_ERROR));
        $url=DB::connection('clubunio')->transaction(function()use($actor,$tenant,$kind,$first,$second,$command,$hash,$receipt,$receiptHash,$verdict){
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $commands=DB::connection('clubunio')->table('clubora.workspace_capture_commands')->where('tenant_id',$tenant->id)->where('account_id',$actor->id)->where('command_key',$command);
            if($old=(clone $commands)->first()){abort_unless(hash_equals($old->input_hash,$hash),409);return $old->result_url;}
            $url=null;
            if($kind==='member'){
                $member=app(MemberService::class)->create($actor,$tenant,$first+$second+['company'=>null]);
                $url=MemberResource::getUrl('view',['record'=>$member]);
            }elseif($kind==='expense'){
                $service=app(ExpenseService::class);$expense=$service->save($actor,$tenant,null,$first+['notes'=>$second['notes']??null]);
                $line=$service->saveLine($actor,$tenant,$expense->id,null,['expense_revision'=>$expense->revision,'expense_type_id'=>$second['expense_type_id'],
                    'amount'=>number_format((float)$second['amount'],2,'.',''),'notes'=>$second['notes']??null,'active'=>true]);
                if($receipt){$upload=app(DocumentUploadService::class)->stage($actor,$tenant,'expense',$line->id,['revision'=>$line->revision,'expense_revision'=>$expense->refresh()->revision,'scanner_version'=>$verdict['version'],'scanned_sha256'=>$receiptHash],$receipt);}
                $url=ExpenseResource::getUrl('view',['record'=>$expense]+(isset($upload)?['captureUpload'=>$upload]:[]));
            }else{
                $id=app(TeamPlanningService::class)->saveEvent($actor,$tenant,null,$first+$second+['kind'=>'TRAINING','mode'=>'ANMELDEN','status'=>'AKTIV','capacity'=>18]);
                $url=route('clubunio.planning.show',[$tenant->slug,$id]);
            }
            DB::connection('clubunio')->table('clubora.workspace_capture_commands')->insert(['tenant_id'=>$tenant->id,'account_id'=>$actor->id,'command_key'=>$command,'input_hash'=>$hash,'result_url'=>$url]);
            return $url;
        });
        if($receipt&&DB::connection('clubunio')->transactionLevel()===0){
            parse_str(parse_url($url,PHP_URL_QUERY)??'',$query);$upload=(int)($query['captureUpload']??0);
            if($upload)try{app(DocumentUploadService::class)->attempt($actor,$tenant,$upload);}catch(\Illuminate\Validation\ValidationException){return $url.'&receipt_pending=1';}
        }
        return $url;
    }
}
