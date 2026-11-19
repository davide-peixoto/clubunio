<?php
namespace App\ClubUnio\Mobile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
final class MobileNotificationController
{
    public function index(Request $r) {
        $d = $r->validate(['start' => 'nullable|integer|min:0|max:100000', 'recperpage' => 'nullable|integer|min:1|max:100', 'unreadOnly' => 'nullable|boolean']);
        return response()->json(['data' => app(MobileNotificationService::class)->page($r->attributes->get('mobile.actor'), $r->attributes->get('mobile.tenant'), $d['start'] ?? 0, $d['recperpage'] ?? 20, (bool) ($d['unreadOnly'] ?? false))]);
    }
    public function count(Request $r) { return response()->json(['data' => app(MobileNotificationService::class)->count($r->attributes->get('mobile.actor'), $r->attributes->get('mobile.tenant'))]); }
    private function write(Request $r, ?int $id, ?int $through, array $observed = []) {
        return DB::connection('clubunio')->transaction(function () use ($r, $id, $through, $observed) {
            \App\ClubUnio\Models\Tenant::whereKey($r->attributes->get('mobile.tenant')->id)->lockForUpdate()->firstOrFail();
            DB::connection('clubunio')->table('clubunio.mobile_sessions')->where('id', $r->attributes->get('mobile.session')->id)->sharedLock()->firstOrFail();
            $actor = app(MobileSessions::class)->authenticate($r);
            $r->attributes->set('mobile.actor',$actor);
            return response()->json(['data' => app(MobileNotificationService::class)->read($actor, $r->attributes->get('mobile.tenant'), $id, $through, $observed)]);
        });
    }
    public function read(Request $r) { return $this->write($r, (int) $r->route('notice'), null); }
    public function readAll(Request $r) {
        $d = $r->validate(['through_id' => 'required|integer|min:0','observed_ids'=>'sometimes|array|max:100','observed_ids.*'=>'required|integer|min:1|distinct']);
        return $this->write($r, null, (int) $d['through_id'], $d['observed_ids']??[]);
    }
}
