<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Services\ParentAccessService;
use Illuminate\Http\Request;

final class MobileParentAccessController
{
    public function index(Request $request)
    {
        return response()->json(['data' => app(ParentAccessService::class)->profile(
            $request->attributes->get('mobile.actor'), $request->attributes->get('mobile.tenant'))]);
    }
    public function grant(Request $request)
    {
        $data = $request->validate(['MemberID' => 'required|integer|min:1', 'Email' => 'required|email:rfc|max:254']);
        app(ParentAccessService::class)->grant($request->attributes->get('mobile.actor'), $request->attributes->get('mobile.tenant'),
            (int) $data['MemberID'], $data['Email'], fn () => app(MobileSessions::class)->authenticate($request));
        return $this->index($request);
    }
    public function decide(Request $request)
    {
        $data = $request->validate(['Revision' => 'required|integer|min:1']);
        app(ParentAccessService::class)->decide($request->attributes->get('mobile.actor'), $request->attributes->get('mobile.tenant'),
            (int) $request->route('link'), (int) $data['Revision'], $request->route('operation') === 'approve',
            fn () => app(MobileSessions::class)->authenticate($request));
        return $this->index($request);
    }
}
