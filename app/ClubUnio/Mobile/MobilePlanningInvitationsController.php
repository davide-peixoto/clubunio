<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\{PlanningGuestService, TeamPlanningService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt, DB};

final class MobilePlanningInvitationsController implements \Illuminate\Routing\Controllers\HasMiddleware
{
    public static function middleware(): array { return [MobilePlanningReady::class]; }

    public function members(Request $request)
    {
        $actor = $request->attributes->get('mobile.actor'); $tenant = $request->attributes->get('mobile.tenant');
        $event = app(TeamPlanningService::class)->event($actor, $tenant, (int) $request->route('event'), true, 'send');
        return response()->json(['data' => app(MobilePlanningInvitationsData::class)->search($request, $actor, $tenant, $event)]);
    }

    public function save(Request $request)
    {
        return DB::connection('clubunio')->transaction(function () use ($request) {
            $tenant = $request->attributes->get('mobile.tenant');
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            DB::connection('clubunio')->table('clubora.mobile_sessions')->where('id', $request->attributes->get('mobile.session')->id)->sharedLock()->firstOrFail();
            $actor = app(MobileSessions::class)->authenticate($request);
            app(MobileAccess::class)->enter($actor, $tenant);
            $p = app(TeamPlanningService::class);
            $remove = $request->isMethod('DELETE');
            $event = $p->event($actor, $tenant, (int) $request->route('event'), true, $remove ? 'cancel' : 'send');
            $member = (int) $request->route('member');
            $d = $request->validate(['EventRevision' => 'required|integer|min:1', 'InvitationSnapshot' => 'required|string|max:20000']);
            $data = app(MobilePlanningInvitationsData::class);
            abort_unless($remove || $data->canInvite($actor, $tenant, $event), 403);
            $data->verify($actor, $tenant, $event, $member, $d['InvitationSnapshot'], (int) $d['EventRevision']);
            abort_if(! $remove && $p->roster($tenant, $event)->whereKey($member)->exists(), 409, 'Person ist bereits erfasst.');
            app(PlanningGuestService::class)->invite($actor, $tenant, (int) $event->id, $member, $remove);
            if (! $remove) {
                // The original selection registers the chosen additional player immediately.
                $old = $p->table($tenant, 'team_participations')->where('event_id', $event->id)->where('member_id', $member)->first();
                $p->participate($actor, $tenant, (int) $event->id, $member, ['status' => 'DABEI', 'attendance' => $old->attendance ?? 'OFFEN',
                    'revision' => (int) ($old->revision ?? 0), 'comment' => $old?->comment_encrypted ? Crypt::decryptString($old->comment_encrypted) : '']);
            }
            return response()->json(['data' => ['success' => true, 'member_id' => $member, 'operation' => $remove ? 'removed' : 'invited',
                'TeamTermin' => app(MobilePlanningData::class)->event($actor, $tenant, (int) $event->id)]]);
        });
    }
}
