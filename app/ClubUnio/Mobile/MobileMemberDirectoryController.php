<?php

namespace App\ClubUnio\Mobile;

use Illuminate\Http\Request;

final class MobileMemberDirectoryController implements \Illuminate\Routing\Controllers\HasMiddleware
{
    public static function middleware(): array { return [MobileMemberDirectoryReady::class]; }
    public function index(Request $request)
    {
        return response()->json(['data' => app(MobileMemberDirectoryData::class)->index($request,
            $request->attributes->get('mobile.actor'), $request->attributes->get('mobile.tenant'))]);
    }
    public function changes(Request $request)
    {
        return response()->json(['data' => app(MobileMemberDirectoryData::class)->changes($request,
            $request->attributes->get('mobile.actor'), $request->attributes->get('mobile.tenant'))]);
    }
}
