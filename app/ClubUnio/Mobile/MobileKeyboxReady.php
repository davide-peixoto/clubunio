<?php
namespace App\ClubUnio\Mobile;
final class MobileKeyboxReady {
    public function handle($request,\Closure $next) {
        abort_unless(config('clubunio-mobile.native_keybox_enabled',false),503);
        return $next($request);
    }
}
