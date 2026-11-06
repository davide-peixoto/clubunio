<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account,Tenant};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Validator};
use Illuminate\Validation\ValidationException;

/** Conversation read cursors, never proof of physical viewing or an exact time. */
final class ChatReadReceipts
{
    public function readers(Request $request,Account $actor,Tenant $tenant,int $id): array
    {
        if(array_diff(array_keys($request->query()),['after','limit'])) {
            throw ValidationException::withMessages(['query'=>__('Ungültige Anfrage.')]);
        }
        $input=Validator::make($request->query(),['after'=>'sometimes|required|integer|min:0','limit'=>'sometimes|required|integer|min:1|max:50'])->validate();
        $after=(int)($input['after']??0); $limit=(int)($input['limit']??30);
        $s=app(ChatService::class); $access=app(ChatAccess::class);
        $access->authorize($actor,$tenant,'view');
        $message=$s->table($tenant,'chat_messages')->where('id',$id)->first();
        abort_unless($message && !$message->deleted_at && (int)$message->account_id===(int)$actor->id,404);
        $chat=$s->table($tenant,'chat_conversations')->where('id',$message->conversation_id)->first();
        abort_unless($chat && $s->allowed($actor,$tenant,$chat),404);
        $member=$s->table($tenant,'chat_memberships')->where('conversation_id',$chat->id)->where('account_id',$actor->id)->first();
        abort_unless($member && $member->left_at===null && $id>(int)$member->joined_after,404);

        $candidates=DB::connection('clubunio')->table('clubora.chat_memberships as cm')
            ->join('clubora.accounts as a','a.id','=','cm.account_id')
            ->join('clubora.tenant_accounts as ta',fn($j)=>$j->on('ta.account_id','=','a.id')->on('ta.tenant_id','=','cm.tenant_id'))
            ->where('cm.tenant_id',$tenant->id)->where('cm.conversation_id',$chat->id)->whereNull('cm.left_at')
            ->where('cm.account_id','>',$after)->where('cm.account_id','<>',$actor->id)
            ->where('cm.joined_after','<',$id)->where('cm.read_message_id','>=',$id)
            ->where('a.active',true)->where('a.api_only',false)->orderBy('a.id')->limit(101)->get(['a.*']);
        $data=[]; $cursor=$after; $scanned=0;
        foreach($candidates->take(100) as $candidate) {
            $reader=new Account; $reader->setRawAttributes((array)$candidate,true); $reader->exists=true;
            try {
                $visible=$access->duringRead($reader,$tenant,function()use($access,$s,$reader,$tenant,$chat){
                    return $access->allows($reader,$tenant,'view') && $s->allowed($reader,$tenant,$chat);
                });
            } catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface|\Illuminate\Database\Eloquent\ModelNotFoundException) {
                $visible=false;
            }
            // Retain the first additional authorized row for the next page.
            if($visible && count($data)>=$limit) return ['data'=>$data,'meta'=>['has_more'=>true,'next_after'=>$cursor]];
            $cursor=(int)$candidate->id; $scanned++;
            if($visible) $data[]=['account_id'=>(int)$reader->id,'name'=>$reader->display_name];
        }
        return ['data'=>$data,'meta'=>['has_more'=>$candidates->count()>$scanned,'next_after'=>$cursor]];
    }
}
