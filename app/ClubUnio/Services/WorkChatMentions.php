<?php
namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Account,Tenant};
use App\ClubUnio\Mobile\{ChatAccess,ChatService};
use Illuminate\Support\Facades\{DB,Validator};

class WorkChatMentions
{
    public function candidates(Account $actor,Tenant $tenant,int $chatId,string $term=''): array
    {
        $service=app(ChatService::class);$chat=$service->chat($actor,$tenant,$chatId,true);
        Validator::make(compact('term'),['term'=>'nullable|string|max:100'])->validate();
        $rows=Account::where('active',true)->where('api_only',false)->where('id','<>',$actor->id)
            ->whereIn('id',$service->table($tenant,'chat_memberships')->where('conversation_id',$chatId)->whereNull('left_at')->select('account_id'))
            ->whereRaw("LOWER(display_name) LIKE ? ESCAPE '!'",[WorkObjectSearch::pattern($term)])->orderBy('display_name')->limit(100)->get();
        return $rows->filter(fn($target)=>app(ChatAccess::class)->allows($target,$tenant,'view')&&$service->allowed($target,$tenant,$chat))
            ->map(fn($target)=>['id'=>(int)$target->id,'name'=>$target->display_name])->values()->all();
    }
    public function send(Account $actor,Tenant $tenant,int $chatId,array $input): object
    {
        $ids=Validator::make($input,['client_id'=>'required|uuid','mentions'=>'sometimes|array|max:10','mentions.*'=>'required|integer|min:1|distinct'])->validate()['mentions']??[];
        $s=app(ChatService::class);
        return DB::connection('clubunio')->transaction(function()use($s,$actor,$tenant,$chatId,$input,$ids){
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();$chat=$s->chat($actor,$tenant,$chatId,true);
            $exists=$s->table($tenant,'chat_messages')->where('account_id',$actor->id)->where('client_id',$input['client_id']??'')->exists();
            $targets=[];
            foreach($ids as $id){
                $target=Account::whereKey($id)->where('active',true)->where('api_only',false)->first();
                $membership=$s->table($tenant,'chat_memberships')->where('conversation_id',$chatId)->where('account_id',$id)->whereNull('left_at')->exists();
                abort_unless($target && (int)$target->id!==(int)$actor->id && $membership && app(ChatAccess::class)->allows($target,$tenant,'view') && $s->allowed($target,$tenant,$chat),422,trans('work_hub.invalid_mention'));
                $targets[]=$target;
            }
            $message=$s->send($actor,$tenant,$chatId,$input);
            if(!$exists)foreach($targets as $target){
                DB::connection('clubunio')->table('clubora.chat_mentions')->insert(['tenant_id'=>$tenant->id,'message_id'=>$message->id,'account_id'=>$target->id]);
                $locale=app(LanguagePreferences::class)->resolve($target->preferred_locale,$tenant->default_locale);
                DB::connection('clubunio')->table('clubora.notifications')->insert(['tenant_id'=>$tenant->id,'account_id'=>$target->id,'object_type'=>'chat_mention','object_id'=>$message->id,
                    'title'=>trans('work_hub.mention_notice',[],$locale),'body'=>'']);
            }
            return $message;
        });
    }
    public function target(Account $actor,Tenant $tenant,int $messageId): ?array
    {
        $s=app(ChatService::class);
        if(!DB::connection('clubunio')->table('clubora.chat_mentions')->where('tenant_id',$tenant->id)->where('account_id',$actor->id)->where('message_id',$messageId)->exists())return null;
        $message=$s->table($tenant,'chat_messages')->where('id',$messageId)->whereNull('deleted_at')->first();if(!$message)return null;
        $s->chat($actor,$tenant,$message->conversation_id);
        $membership=$s->table($tenant,'chat_memberships')->where('conversation_id',$message->conversation_id)->where('account_id',$actor->id)->whereNull('left_at')->where('joined_after','<',$messageId)->exists();
        return $membership?['label'=>trans('work_hub.mention_notice'),'url'=>\App\ClubUnio\Pages\Chats::getUrl(['conversation'=>$message->conversation_id])]:null;
    }
}
