<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account,Tenant};
use Illuminate\Support\Facades\DB;

/** Durable identifiers only. Fanout never runs in the message request or calls a provider. */
final class ChatPushPublications
{
    /** Fresh source authorization, without tenant/conversation locks during fanout. */
    public function source(Tenant $tenant,int $messageId): ?array
    {
        $db=DB::connection('clubunio');
        $message=$db->table('clubunio.chat_messages')->where('tenant_id',$tenant->id)->where('id',$messageId)->first();
        if(!$message || $message->deleted_at) return null;
        $actor=Account::whereKey($message->account_id)->where('active',true)->where('api_only',false)->first();
        if(!$actor) return null;
        $member=$db->table('clubunio.chat_memberships')->where('tenant_id',$tenant->id)
            ->where('conversation_id',$message->conversation_id)->where('account_id',$actor->id)->first();
        $chat=$db->table('clubunio.chat_conversations')->where('tenant_id',$tenant->id)->where('id',$message->conversation_id)->first();
        if(!$member || $member->left_at!==null || $message->id<=$member->joined_after || !$chat) return null;
        try {
            // Read-only eligibility check, not a chat mutation. A fresh frame per
            // source avoids repeating enter/profile checks inside the same proof.
            $allowed=app(ChatAccess::class)->duringRead($actor,$tenant,function()use($actor,$tenant,$chat,$message){
                $access=app(ChatAccess::class);
                return $access->allows($actor,$tenant,'send') && app(ChatService::class)->allowed($actor,$tenant,$chat)
                    && ($message->kind!=='announcement' || $access->allows($actor,$tenant,'announce'));
            });
            if(!$allowed) return null;
        } catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface|\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return null;
        }
        return ['actor'=>$actor,'message'=>$message];
    }

    public function record(Account $actor,Tenant $tenant,object $message): void
    {
        if(!config('clubunio-mobile-push.enabled',false)) return;
        $db=DB::connection('clubunio');
        if($db->transactionLevel()<1) throw new \LogicException('Chat intent requires the message transaction');
        $db->table('clubunio.mobile_chat_publications')->insertOrIgnore([
            'tenant_id'=>$tenant->id,'account_id'=>$actor->id,'message_id'=>$message->id,
            'next_attempt_at'=>now()->toIso8601String(),'created_at'=>now()->toIso8601String(),
        ]);
    }

    public function processOne(): bool
    {
        if(!config('clubunio-mobile-push.enabled',false)) return false;
        $db=DB::connection('clubunio');
        if($db->transactionLevel()>0&&!app()->environment('testing')) throw new \LogicException('Fanout cannot run inside a business transaction');
        $claimed=null;
        try {
            return $db->transaction(function()use($db,&$claimed){
                $q=$db->table('clubunio.mobile_chat_publications')->where('state','pending')->where('next_attempt_at','<=',now()->toIso8601String())
                    ->orderBy('id')->lock('FOR UPDATE SKIP LOCKED');
                $job=$q->first();
                if(!$job) return false;
                $claimed=$job;
                $tenant=Tenant::whereKey($job->tenant_id)->first();
                $source=$tenant ? $this->source($tenant,(int)$job->message_id) : null;
                $allowed=$source && (int)$source['actor']->id===(int)$job->account_id;
                $queued=$allowed ? app(MobilePushOutbox::class)->chatMessage($source['actor'],$tenant,$source['message'],$source['message']->created_at) : 0;
                $db->table('clubunio.mobile_chat_publications')->where('id',$job->id)->update([
                    'state'=>$allowed?'done':'discarded','attempts'=>(int)$job->attempts+1,'queued_count'=>$queued,
                    'last_error_code'=>$allowed?null:'source_authorization_changed','finished_at'=>now()->toIso8601String(),
                ]);
                return true;
            });
        } catch(\Throwable $error) {
            if(!$claimed) throw $error;
            // The whole fanout transaction rolled back, including partial outbox rows.
            // Compare attempts so another completed/retried worker can never be reset.
            $attempt=min(5,(int)$claimed->attempts+1);
            $db->table('clubunio.mobile_chat_publications')->where('id',$claimed->id)->where('state','pending')
                ->where('attempts',$claimed->attempts)->update([
                    'attempts'=>$attempt,'state'=>$attempt>=5?'attention':'pending',
                    'next_attempt_at'=>now()->addSeconds(min(300,15*(2**($attempt-1))))->toIso8601String(),
                    'last_error_code'=>'fanout_retry','finished_at'=>$attempt>=5?now()->toIso8601String():null,
                ]);
            return true;
        }
    }
}
