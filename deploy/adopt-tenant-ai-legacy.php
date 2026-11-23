<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR,get_class($error).': AI adoption failed'.PHP_EOL); exit(1); });
$db = Illuminate\Support\Facades\DB::connection('clubunio');
if (PHP_SAPI!=='cli' || !in_array($argv[1] ?? '',['clubunio_dev','clubunio_stage','clubunio_prod'],true)
    || $db->getDatabaseName()!==$argv[1]) exit(1);
$count = $db->transaction(function () use ($db) {
    $count=0;
    foreach (App\ClubUnio\Models\Setting::where('key','OPENAI_API_KEY')->get() as $setting) {
        App\ClubUnio\Models\Tenant::whereKey($setting->tenant_id)->lockForUpdate()->firstOrFail();
        if ($db->table('clubora.tenant_ai_connections')->where('tenant_id',$setting->tenant_id)->exists()) continue;
        $key = trim(Illuminate\Support\Facades\Crypt::decryptString($setting->encrypted_value));
        $hash = $key===''?null:hash('sha256',$key);
        if ($hash && $db->table('clubora.tenant_ai_connections')->where('key_hash',$hash)->exists()) continue;
        $db->table('clubora.tenant_ai_connections')->insert(['tenant_id'=>$setting->tenant_id,'mode'=>'own',
            'encrypted_key'=>$key===''?null:Illuminate\Support\Facades\Crypt::encryptString($key), 'key_hash'=>$hash,
            'enabled'=>false,'privacy_reviewed'=>false]);
        $count++;
    }
    return $count;
});
echo json_encode(['database'=>$db->getDatabaseName(),'profiles_adopted'=>$count,'enabled_profiles'=>0,
    'global_keys_copied'=>false,'remote_stores_imported'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
