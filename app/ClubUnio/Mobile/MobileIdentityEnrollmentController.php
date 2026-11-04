<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MobileIdentityEnrollmentController implements \Illuminate\Routing\Controllers\HasMiddleware
{
    public static function middleware(): array { return [MobileMiddleware::class,MobilePublicIdentityReady::class]; }
    public function show(Request $request)
    {
        return response()->json(['data'=>app(MobileIdentityChallenges::class)->emailSummary($request->attributes->get('mobile.actor'),$request->attributes->get('mobile.tenant'))]);
    }
    public function store(Request $request)
    {
        return DB::connection('clubunio')->transaction(function () use ($request) {
            $tenant=$request->attributes->get('mobile.tenant'); Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            DB::connection('clubunio')->table('clubora.mobile_sessions')->where('id',$request->attributes->get('mobile.session')->id)->sharedLock()->firstOrFail();
            $actor=app(MobileSessions::class)->authenticate($request); app(MobileAccess::class)->enter($actor,$tenant);
            return response()->json(['data'=>app(MobileIdentityChallenges::class)->enroll($request,$actor,$tenant)]);
        });
    }
}
