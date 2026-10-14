<?php
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$count=0;
try {
    foreach (\Illuminate\Support\Facades\DB::connection('clubunio')->table('clubora.tenant_settings')->pluck('encrypted_value') as $cipher) {
        if ($cipher!==null) {
            \Illuminate\Support\Facades\Crypt::decryptString($cipher);
            $count++;
        }
    }
    echo "Encrypted settings verified: $count\n";
    if (config('clubunio.outbound_enabled')) {
        throw new \LogicException('Outbound must stay disabled during migration');
    }
    $templates = \App\ClubUnio\Models\MessageTemplate::withoutGlobalScopes()->get();
    foreach ($templates as $template) {
        app(\App\ClubUnio\Services\MessageTemplateService::class)->preview(
            $template->event_key, $template->title, $template->body,
            \App\ClubUnio\Services\MessageEventCatalog::samples()
        );
    }
    echo 'Message template rendering verified: '.$templates->count()."\n";
    $db = \Illuminate\Support\Facades\DB::connection('clubunio');
    foreach ($db->table('clubora.account_member_links')->selectRaw('kind, state, count(*) as total')->groupBy('kind', 'state')->get() as $group) {
        echo "Identity links: {$group->kind}/{$group->state} {$group->total}\n";
    }
} catch (\Throwable) {
    fwrite(STDERR,"ClubUnio encryption, outbound or template verification failed.\n");
    exit(1);
}
