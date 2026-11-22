<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\MemberCardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MobileMemberCardSecurityController
{
    public function change(Request $request)
    {
        $input = $request->validate(['SourceVersion' => 'required|string|regex:/^[a-f0-9]{64}$/']);
        return DB::connection('clubunio')->transaction(function () use ($request, $input) {
            $tenant = $request->attributes->get('mobile.tenant');
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $actor = app(MobileSessions::class)->authenticate($request);
            app(MobileAccess::class)->enter($actor, $tenant);
            $member = (int) $request->route('member');
            // Preserve the original personal-card rights, including team-contact restrictions.
            $current = app(MobileMemberIdCardData::class)->show($actor, $tenant, $member);
            abort_unless(hash_equals($current['SourceVersion'], $input['SourceVersion']), 409);
            $cards = app(MemberCardService::class);
            if ($request->route('operation') === 'rotate') $cards->rotate($tenant, $member);
            else $cards->revoke($tenant, $member);
            return response()->json(['data' => ['Card' => app(MobileMemberIdCardData::class)->show($actor, $tenant, $member)]])
                ->header('Cache-Control', 'private, no-store');
        });
    }
}
