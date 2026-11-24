<?php
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\ClubUnio\Models\{Tenant,Account,Setting};
use App\ClubUnio\Services\{ReactUi,SettingsService};
use Illuminate\Support\Facades\{DB,Crypt};
$database=DB::connection('clubunio');
if(!in_array([config('app.url'),$database->getDatabaseName()],[['https://stage.clubunio.com','clubunio_stage'],['https://app.clubunio.com','clubunio_prod']],true))throw new LogicException('Reviewed Stage/PROD only');
foreach(Tenant::whereIn('slug',['sv-sissach','sv-sissach-refresh','fc-horizon'])->get() as $tenant){
    $actor=Account::where('active',true)->where('api_only',false)->whereHas('tenants',fn($q)=>$q->whereKey($tenant->id)->whereIn('clubora.tenant_accounts.role',['admin','office']))->first();
    if(!$actor){echo "No enabled administrator for tenant {$tenant->id}; parameter retained\n";continue;}
    $database->transaction(function()use($actor,$tenant){
        Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
        abort_unless($actor->hasUnrestrictedBackofficeAccess($tenant),403);
        $setting=Setting::where('tenant_id',$tenant->id)->where('key',ReactUi::KEY)->first();
        if(!$setting){$setting=new Setting;$setting->forceFill(['tenant_id'=>$tenant->id,'area'=>'general','key'=>ReactUi::KEY,'label'=>'React','value_type'=>'boolean','is_secret'=>false,'enabled'=>true,'encrypted_value'=>Crypt::encryptString('0')])->save();}
        app(SettingsService::class)->update($actor,$tenant,$setting->id,'1');
        if(!app(ReactUi::class)->enabled($tenant->id))throw new LogicException('React verification failed');
    });
    echo "Tenant {$tenant->id} {$tenant->slug}: React enabled and audited\n";
}
