<?php

// Read-only source/schema gate; never enables identity delivery or sends mail.
try {
    if (PHP_SAPI!=='cli' || !in_array('--closed', $argv,true)) { throw new LogicException('Explicit closed identity stage required'); }
    require dirname(__DIR__).'/vendor/autoload.php';
    $app=require dirname(__DIR__).'/bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    foreach (['enabled','registration_enabled','password_reset_enabled','email_enrollment_enabled','membership_enabled','mail_enabled'] as $flag) {
        if (config('clubunio-mobile.public_identity.'.$flag,false)!==false) { throw new LogicException('Identity must remain explicitly closed'); }
    }
    if (config('clubunio-mobile.public_identity.origins',[]) || config('clubunio-mobile.public_identity.tenant_slugs',[])) { throw new LogicException('Identity allowlists require separate acceptance'); }
    $public=0; $personal=0;
    foreach (Illuminate\Support\Facades\Route::getRoutes() as $route) {
        $action=$route->getActionName();
        if (str_starts_with($action,App\ClubUnio\Mobile\MobilePublicIdentityController::class.'@')) {
            if (!in_array(App\ClubUnio\Mobile\MobilePublicIdentityReady::class,$route->gatherMiddleware(),true)
                || in_array(App\ClubUnio\Mobile\MobileMiddleware::class,$route->gatherMiddleware(),true)) { throw new LogicException('Invalid public proof boundary'); }
            $public++;
        }
        if (str_starts_with($action,App\ClubUnio\Mobile\MobileIdentityEnrollmentController::class.'@')) {
            foreach ([App\ClubUnio\Mobile\MobileMiddleware::class,App\ClubUnio\Mobile\MobilePublicIdentityReady::class] as $middleware) {
                if (!in_array($middleware,$route->gatherMiddleware(),true)) { throw new LogicException('Invalid personal recovery boundary'); }
            }
            $personal++;
        }
    }
    if ($public!==8 || $personal!==2 || app(App\ClubUnio\Mobile\MobileIdentityMailGuard::class)->configured()) { throw new LogicException('Unexpected identity routes or mail runtime'); }
    echo "Identity8 public/2 personal routes and all closed runtime/mail boundaries verified; no data or delivery action.\n";
} catch (Throwable $error) {
    fwrite(STDERR,'Identity gate failed: '.get_class($error)." (contents suppressed).\n"); exit(1);
}
