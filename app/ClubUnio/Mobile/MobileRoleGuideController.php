<?php
namespace App\ClubUnio\Mobile;
use Illuminate\Http\Request;
final class MobileRoleGuideController {
    public function show(Request $request) {
        abort_unless(config('clubunio-mobile.native_role_guide_enabled',false),503);
        return response()->json(['data'=>app(MobileRoleGuideData::class)->read($request->attributes->get('mobile.actor'),$request->attributes->get('mobile.tenant'))]);
    }
}
