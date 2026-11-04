<?php
namespace App\ClubUnio\Mobile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
final class MobileBootstrapController {
    public function public(Request $request) {
        try {
            $service=app(MobileBootstrapState::class); $origin=$service->origin($request);
            $key='mobile-bootstrap:'.hash('sha256',$origin.'|'.$request->ip()); abort_if(RateLimiter::tooManyAttempts($key,60),429); RateLimiter::hit($key,60);
            $response=response()->json(['data'=>$service->publicState($request)]);
        } catch(\Throwable $error) { $response=app(\App\ClubUnio\Api\ApiErrors::class)->render($error,$request); }
        $response->headers->set('Cache-Control','private, no-store'); $response->headers->set('X-Content-Type-Options','nosniff');
        return $response;
    }
    public function personal(Request $request) {
        return response()->json(['data'=>app(MobileBootstrapState::class)->personalState($request->attributes->get('mobile.actor'),$request->attributes->get('mobile.tenant'))]);
    }
}
