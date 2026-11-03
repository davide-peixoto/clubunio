<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection('clubunio');
if ($db->getDatabaseName() !== 'db_acdf8d_dclubora') { throw new LogicException('Unexpected target'); }
$tenant = $db->table('clubora.tenants')->where('slug','sv-sissach')->first();
if (! $tenant) { throw new LogicException('Tenant missing'); }
$retired = $db->table('clubora.tenant_settings')->where('tenant_id',$tenant->id)->where(function ($q) {
    foreach (App\ClubUnio\Services\RetiredIntegrationParameters::KEYS as $area=>$keys) {
        $q->orWhere(fn ($p)=>$p->where('area',$area)->whereIn('key',$keys));
    }
})->count();
$modules = $db->table('clubora.mobile_module_options')->where('tenant_id',$tenant->id)->count();
if ($retired !== 0 || $modules < 10) { throw new LogicException('Parameter migration incomplete'); }
echo json_encode(['retired_remaining'=>$retired,'module_options'=>$modules,
    'bexio_settings'=>$db->table('clubora.tenant_settings')->where('tenant_id',$tenant->id)->where('area','bexio')->count(),
    'fcm_settings'=>$db->table('clubora.tenant_settings')->where('tenant_id',$tenant->id)->where('key','like','FCM_%')->count(),
    'explicit_app_assignments'=>$db->table('clubora.tenant_accounts')->where('tenant_id',$tenant->id)->whereNotNull('mobile_profile_id')->count(),
],JSON_THROW_ON_ERROR).PHP_EOL;
