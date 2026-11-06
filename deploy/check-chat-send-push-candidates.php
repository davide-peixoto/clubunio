<?php
// Fresh authorized, read-only push candidate projection. No login/device/message is created.
try {
    $root=dirname(__DIR__);
    if (PHP_SAPI !== 'cli' || $root !== '/var/www/svs-laravel/releases/20261106-clubunio-chat-send-push-candidates') throw new LogicException('Fixed release required');
    require $root.'/vendor/autoload.php'; $app=require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (config('app.url') !== 'https://dev.clubunio.ch' || !config('clubunio-mobile.native_planning_enabled')
        || !config('clubunio-mobile-push.enabled') || !config('clubunio-mobile-push.delivery_enabled')) throw new LogicException('Unexpected flags');
    $db=Illuminate\Support\Facades\DB::connection('clubunio');
    if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora') throw new LogicException('Unexpected database');
    $db->beginTransaction();
    try {
        $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
        $db->statement("SET LOCAL statement_timeout='15s'");
        $tenant=App\ClubUnio\Models\Tenant::where('slug','sv-sissach')->sole();
        $message=$db->table('clubora.chat_messages')->where('tenant_id',$tenant->id)
            ->where('conversation_id',787)->where('id',709)->first(['id','account_id','conversation_id','kind']);
        if (!$message) throw new LogicException('Expected existing source message unavailable');
        $actor=App\ClubUnio\Models\Account::whereKey($message->account_id)->where('active',true)->where('api_only',false)->sole();
        $request=Illuminate\Http\Request::create('https://dev.clubunio.ch/api/mobile/v1/clubs/'.$tenant->id.'/chats/'.$message->conversation_id.'/messages','POST');
        $request->headers->set('X-ClubUnio-Mobile-Features','personal-push-v1');
        $request->attributes->set('mobile.actor',$actor); $request->attributes->set('mobile.tenant',$tenant);
        $app->instance('request',$request);
        app(App\ClubUnio\Mobile\MobileAccess::class)->enter($actor,$tenant);
        if (!app(App\ClubUnio\Mobile\ChatService::class)->allowed($actor,$tenant,
            $db->table('clubora.chat_conversations')->where('tenant_id',$tenant->id)->where('id',$message->conversation_id)->first())) throw new LogicException('Source no longer visible');
        $count=0; $db->listen(function () use (&$count) { $count++; }); $start=microtime(true);
        // The real current source has no eligible recipient device other than the sender.
        // READ ONLY prevents queue inserts if live bindings change during approval.
        app(App\ClubUnio\Mobile\MobilePushOutbox::class)->chatMessage($actor,$tenant,$message);
        if ($count>3) throw new LogicException('Unexpected recipient authorization fanout');
        echo json_encode(['read_only'=>true,'queries'=>$count,'elapsed_ms'=>round((microtime(true)-$start)*1000),
            'no_message_created'=>true,'no_push_enqueued'=>true,'push_flags_preserved'=>true],JSON_THROW_ON_ERROR).PHP_EOL;
    } finally { $db->rollBack(); }
} catch (Throwable $error) {
    fwrite(STDERR,'Chat send check stopped: '.($error instanceof LogicException ? $error->getMessage() : get_class($error)).PHP_EOL); exit(1);
}
