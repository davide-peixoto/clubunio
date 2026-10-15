<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Models\MemberChangeRequest;
use App\ClubUnio\Models\MemberDocument;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\MemberChangeService;
use App\ClubUnio\Services\MemberService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MemberChangePortalController
{
    public function show(Request $request, Tenant $tenant, int $member, MemberChangeService $service)
    {
        $actor = $request->user('portal');
        $member = $service->represented($actor, $tenant, $member);
        $values = $service->values($member);
        $version = MemberService::version($member);
        $lookups = [];
        foreach (['country_id' => 'countries', 'nationality_id' => 'countries', 'gender_id' => 'genders', 'billing_recipient_id' => 'billing_recipients'] as $field => $table) {
            $lookups[$field] = DB::connection('clubunio')->table('clubora.'.$table)->where('tenant_id', $tenant->id)
                ->where(fn ($q) => $q->where('active', true)->orWhere('id', $values[$field] ?? -1))->orderBy('name')->pluck('name', 'id');
        }
        $requests = MemberChangeRequest::where('tenant_id', $tenant->id)->where('member_id', $member->id)->where('requested_by', $actor->id)->with('status')->latest()->limit(30)->get();
        $documents = MemberDocument::where('tenant_id', $tenant->id)->where('member_id', $member->id)->where('active', true)->where('portal_visible', true)->with('documentType')->latest('id')->get();
        $comparisons = $requests->mapWithKeys(fn ($r) => [$r->id => $service->comparison($r, $tenant)]);
        $statusColors = DB::connection('clubunio')->table('clubora.status_styles')->where('tenant_id', $tenant->id)->where('active', true)->pluck('color', 'value_key');

        return response()->view('clubunio.portal.member-edit', compact('tenant', 'member', 'values', 'version', 'lookups', 'requests', 'documents', 'comparisons', 'statusColors') + ['tenants' => collect([$tenant])])
            ->header('Cache-Control', 'private, no-store');
    }

    public function submit(Request $request, Tenant $tenant, int $member, MemberChangeService $service)
    {
        $service->submit($request->user('portal'), $tenant, $member, $request->except('_token'));

        return redirect()->route('clubunio.portal.member.edit', ['tenant' => $tenant->slug, 'member' => $member])->with('message', 'Änderungsantrag eingereicht. Die Geschäftsstelle prüft deine Angaben.');
    }

    public function withdraw(Request $request, Tenant $tenant, int $change, MemberChangeService $service)
    {
        $data = $request->validate(['revision' => 'required|integer|min:1']);
        $r = MemberChangeRequest::where('tenant_id', $tenant->id)->where('requested_by', $request->user('portal')->id)->findOrFail($change);
        $service->withdraw($request->user('portal'), $tenant, $change, $data['revision']);

        return redirect()->route('clubunio.portal.member.edit', ['tenant' => $tenant->slug, 'member' => $r->member_id])->with('message', 'Antrag zurückgezogen.');
    }
}
