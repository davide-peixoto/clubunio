<?php

namespace App\ClubUnio\Mobile;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MobilePushController
{
    public function register(Request $r) { return response()->json(['data' => app(MobilePushDevices::class)->register($r)]); }
    public function status(Request $r) { return response()->json(['data' => app(MobilePushDevices::class)->status($r)]); }
    public function resolve(Request $r) {
        $d = $r->validate(['kind' => ['required', \Illuminate\Validation\Rule::in(MobilePushTargets::KINDS)], 'object_id' => 'required|integer|min:1']);
        return response()->json(['data' => app(MobilePushTargets::class)->authorize($r->attributes->get('mobile.actor'), $r->attributes->get('mobile.tenant'), $d['kind'], (int) $d['object_id'])]);
    }
    public function revoke(Request $r) { return response()->json(['data' => app(MobilePushDevices::class)->revoke($r)]); }
    public function squadPreview(Request $r)
    {
        abort_unless(app(MobilePushReady::class)->available($r), 503);
        $snapshot = app(MobilePushSquad::class)->snapshot($r->attributes->get('mobile.actor'), $r->attributes->get('mobile.tenant'), (int) $r->route('event'));
        // Recipient identity list is never sent to the caller.
        return response()->json(['data' => ['revision' => $snapshot['revision'], 'recipient_fingerprint' => $snapshot['recipient_fingerprint'], 'recipient_count' => count($snapshot['recipients'])]]);
    }
    public function squad(Request $r)
    {
        abort_unless(app(MobilePushReady::class)->available($r), 503);
        $input = $r->validate(['command_key' => 'required|uuid', 'revision' => 'required|regex:/^[0-9a-f]{64}$/D', 'recipient_fingerprint' => 'required|regex:/^[0-9a-f]{64}$/D']);
        return DB::connection('clubunio')->transaction(function () use ($r, $input) {
            DB::connection('clubunio')->table('clubunio.mobile_sessions')->where('id', $r->attributes->get('mobile.session')->id)->sharedLock()->firstOrFail();
            $actor = app(MobileSessions::class)->authenticate($r);
            return response()->json(['data' => app(MobilePushSquad::class)->publish($actor, $r->attributes->get('mobile.tenant'), (int) $r->route('event'), $input)]);
        });
    }
}
