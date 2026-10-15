<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\AnnouncementService;
use Illuminate\Http\Request;

class AnnouncementPortalController
{
    public function index(Request $request, Tenant $tenant, AnnouncementService $service)
    {
        $announcements = $service->portal($request->user('portal'), $tenant)->orderByDesc('published_at')->paginate(20);

        return response()->view('clubunio.portal.announcements', compact('tenant', 'announcements') + ['tenants' => collect([$tenant])])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, Tenant $tenant, int $announcement, AnnouncementService $service)
    {
        $announcement = $service->portal($request->user('portal'), $tenant)->findOrFail($announcement);

        return response()->view('clubunio.portal.announcement', compact('tenant', 'announcement') + ['tenants' => collect([$tenant])])->header('Cache-Control', 'private, no-store');
    }
}
