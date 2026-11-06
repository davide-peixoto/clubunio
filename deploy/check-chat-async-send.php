<?php
// Authorized read-only reconciliation/schema check. Never creates a message, job or push.
try {
 $root=dirname(__DIR__);
 if(PHP_SAPI!=='cli'||$root!=='/var/www/svs-laravel/releases/20261106-clubunio-chat-async-send') throw new LogicException('Fixed release required');
 require $root.'/vendor/autoload.php';$app=require $root.'/bootstrap/app.php';
 $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
 if(config('app.url')!=='https://dev.clubunio.ch'||!config('clubunio-mobile-push.enabled')||!config('clubunio-mobile-push.delivery_enabled')) throw new LogicException('Unexpected flags');
 if(!class_exists(App\ClubUnio\Mobile\ChatPushPublications::class)) throw new LogicException('Publication worker class unavailable');
 $db=Illuminate\Support\Facades\DB::connection('clubunio');
 if($db->getDriverName()!=='pgsql'||$db->getDatabaseName()!=='db_acdf8d_dclubora') throw new LogicException('Unexpected database');
 $db->beginTransaction();
 try {
  $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');$db->statement("SET LOCAL statement_timeout='15s'");
  if(!$db->table('clubora.migrations')->where('migration','2026_11_06_000200_mobile_chat_publications')->exists()) throw new LogicException('Reviewed migration missing');
  $db->table('clubora.mobile_chat_publications')->limit(1)->get(['id','tenant_id','account_id','message_id','state','attempts','next_attempt_at']);
  $tenant=App\ClubUnio\Models\Tenant::where('slug','sv-sissach')->sole();
  $source=$db->table('clubora.chat_messages')->where('tenant_id',$tenant->id)->where('id',709)->first(['id','account_id','conversation_id','client_id']);
  if(!$source) throw new LogicException('Existing reference unavailable');
  $actor=App\ClubUnio\Models\Account::whereKey($source->account_id)->where('active',true)->where('api_only',false)->sole();
  $request=Illuminate\Http\Request::create('https://dev.clubunio.ch/api/mobile/v1/clubs/'.$tenant->id.'/chats/'.$source->conversation_id.'/messages/by-client/'.$source->client_id,'GET');
  $request->headers->set('X-ClubUnio-Mobile-Features','personal-push-v1');
  $request->attributes->set('mobile.actor',$actor);$request->attributes->set('mobile.tenant',$tenant);$request->attributes->set('mobile.season_scope',true);$app->instance('request',$request);
  $matched=$app['router']->getRoutes()->match($request);
  if($matched->getActionMethod()!=='messageByClient') throw new LogicException('Reconciliation route missing');
  $queries=0;$db->listen(function()use(&$queries){$queries++;});$start=microtime(true);
  $dto=app(App\ClubUnio\Mobile\ChatService::class)->messageByClient($actor,$tenant,(int)$source->conversation_id,$source->client_id);
  if($dto['id']!==(int)$source->id||$dto['author_id']!==(int)$actor->id||$dto['client_id']!==$source->client_id) throw new LogicException('Reconciliation mismatch');
  echo json_encode(['read_only'=>true,'own_message_recognized'=>true,'lookup_queries'=>$queries,'lookup_elapsed_ms'=>round((microtime(true)-$start)*1000),
    'publication_schema_verified'=>true,'no_new_message_or_push'=>true],JSON_THROW_ON_ERROR).PHP_EOL;
 }finally{$db->rollBack();}
}catch(Throwable $e){fwrite(STDERR,'Chat async check stopped: '.($e instanceof LogicException?$e->getMessage():get_class($e)).PHP_EOL);exit(1);}
