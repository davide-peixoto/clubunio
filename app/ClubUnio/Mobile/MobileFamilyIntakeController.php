<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\{MemberAccessService, PublicWebService};
use Illuminate\Http\Request;

final class MobileFamilyIntakeController
{
    private function authorize(Request $request): Tenant
    {
        $tenant = $request->attributes->get('mobile.tenant');
        $actor = $request->attributes->get('mobile.actor');
        abort_unless(app(PublicWebService::class)->enabled($tenant, 'registration'), 503);
        abort_unless(app(MemberAccessService::class)->membership($actor, $tenant)?->role !== 'profile', 403);

        return $tenant;
    }

    public function options(Request $request)
    {
        $tenant = $this->authorize($request);

        return response()->json(['data' => app(MobilePublicIntake::class)->options($request, $tenant, true)]);
    }

    public function submit(Request $request)
    {
        $tenant = $this->authorize($request);

        return response()->json(['data' => app(MobilePublicIntake::class)->submit($request, $tenant, true)]);
    }
}
