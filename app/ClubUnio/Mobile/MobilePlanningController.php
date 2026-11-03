<?php

namespace App\ClubUnio\Mobile;

use Illuminate\Http\Request;

final class MobilePlanningController
{
    public function teams(Request $r) {
        return response()->json(['data' => app(MobilePlanningData::class)->teams($r, $r->attributes->get('mobile.actor'), $r->attributes->get('mobile.tenant'))]);
    }
    public function events(Request $r) {
        return response()->json(['data' => app(MobilePlanningData::class)->events($r, $r->attributes->get('mobile.actor'), $r->attributes->get('mobile.tenant'))]);
    }
    public function event(Request $r) {
        return response()->json(['data' => ['success' => true, 'TeamTermin' => app(MobilePlanningData::class)->event($r->attributes->get('mobile.actor'), $r->attributes->get('mobile.tenant'), (int) $r->route('event'))]]);
    }
}
