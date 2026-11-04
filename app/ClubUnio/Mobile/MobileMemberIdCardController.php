<?php

namespace App\ClubUnio\Mobile;

use Illuminate\Http\Request;

final class MobileMemberIdCardController
{
    public function show(Request $request)
    {
        return response()->json(['data' => ['Card' => app(MobileMemberIdCardData::class)->show(
            $request->attributes->get('mobile.actor'), $request->attributes->get('mobile.tenant'), (int) $request->route('member'))]])
            ->header('Cache-Control', 'private, no-store');
    }
}
