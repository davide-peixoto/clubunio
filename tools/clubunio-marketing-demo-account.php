<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\ClubUnio\Models\{Account,Tenant};use App\ClubUnio\Services\PlatformAdministrationService;
$path=storage_path('app/marketing-demo-access.json');if(is_file($path)){echo "Demo access already prepared.\n";exit;}
$s=app(PlatformAdministrationService::class);$actor=Account::where('username','davide.peixoto@svsissach.ch')->firstOrFail();$t=Tenant::where('slug','fc-horizon-demo')->firstOrFail();
$password=bin2hex(random_bytes(24));$username='fc-horizon-demo@example.invalid';
$s->createAccount($actor,['username'=>$username,'display_name'=>'FC Horizon Demo','password'=>$password,'password_confirmation'=>$password,'reason'=>'User-requested isolated demo account for fictional product screenshots.']);
$s->change($actor,$t->id,['revision'=>$t->platform_revision,'operation'=>'membership','username'=>$username,'role'=>'admin','portal_enabled'=>false,'reason'=>'Demo-only access to the fictional FC Horizon marketing tenant.']);
file_put_contents($path,json_encode(['username'=>$username,'password'=>$password]));echo "Dedicated demo-only account created. Credentials retained privately.\n";
