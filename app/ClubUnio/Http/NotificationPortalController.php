<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\MemberAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationPortalController
{
    private function query(Request $request, Tenant $tenant)
    {
        $actor = $request->user('portal');
        abort_unless(app(MemberAccessService::class)->canEnter($actor, $tenant), 403);

        return DB::connection('clubunio')->table('clubora.notifications')->where('tenant_id', $tenant->id)->where('account_id', $actor->id);
    }

    public function index(Request $request, Tenant $tenant)
    {
        $query = $this->query($request, $tenant);
        $unread = (clone $query)->whereNull('read_at')->count();
        $notifications = $query->when($request->boolean('unreadOnly'), fn ($q) => $q->whereNull('read_at'))->orderByDesc('id')->paginate(20)->withQueryString();
        $tenants = collect([$tenant]);

        return response()->view('clubunio.portal.notifications', compact('tenant', 'tenants', 'notifications', 'unread'))->header('Cache-Control', 'private, no-store');
    }

    public function read(Request $request, Tenant $tenant, int $notification)
    {
        $query = $this->query($request, $tenant)->where('id', $notification);
        abort_unless((clone $query)->exists(), 404);
        $query->whereNull('read_at')->update(['read_at' => now()]);

        return redirect()->route('clubunio.portal.notifications', ['tenant' => $tenant->slug]);
    }

    public function readAll(Request $request, Tenant $tenant)
    {
        $this->query($request, $tenant)->whereNull('read_at')->update(['read_at' => now()]);

        return redirect()->route('clubunio.portal.notifications', ['tenant' => $tenant->slug]);
    }
}
