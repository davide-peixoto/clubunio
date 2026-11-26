<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\{MemberCampaignService, ModulePermissionService, ProfilePhotoService, TenantDomainService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

final class ProfilePhotoController
{
    public function personal(Request $request, ProfilePhotoService $photos)
    {
        abort_if($request->attributes->has('clubunio_impersonation'), 403);

        return $photos->response($photos->personal($request->user('clubunio'), app(TenantDomainService::class)->current()));
    }

    public function member(Request $request, Tenant $tenant, int $member, ProfilePhotoService $photos)
    {
        $bound = app(TenantDomainService::class)->current();
        abort_if($bound && ! $bound->is($tenant), 404);
        $actor = $request->user('clubunio');
        abort_unless(app(ModulePermissionService::class)->allows($actor, $tenant, 'members', 'update'), 403);
        $pending = $request->boolean('pending');
        if ($pending) abort_unless(app(MemberCampaignService::class)->allowed($actor, $tenant), 403);
        $row = $photos->member($tenant, $member);
        $image = $pending ? $row?->pending_encrypted : $row?->image_encrypted;
        abort_unless($image, 404);

        return $photos->response(Crypt::decryptString($image));
    }

    public function campaign(Request $request, Tenant $tenant, int $change, ProfilePhotoService $photos)
    {
        $bound = app(TenantDomainService::class)->current();
        abort_if($bound && ! $bound->is($tenant), 404);
        abort_unless($photos->enabled() && app(MemberCampaignService::class)->allowed($request->user('clubunio'), $tenant), 403);
        $image = \App\ClubUnio\Models\MemberChangeRequest::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->where('state', 'pending')->whereKey($change)->value('portrait_encrypted');
        abort_unless($image, 404);

        return $photos->response(Crypt::decryptString($image));
    }
}
