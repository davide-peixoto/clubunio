<?php
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach (['profile', 'password', 'modules'] as $name) {
    abort_unless(Illuminate\Support\Facades\Route::has('clubunio.mobile.'.$name), 409, 'Missing account-shell route');
}
abort_unless(config('clubunio-mobile.enabled'), 409, 'Mobile module disabled');
abort_unless(class_exists(App\ClubUnio\Mobile\MobileAccountController::class), 409, 'Missing account controller');
echo "Personal account/profile/module routes available; no account changed.\n";
