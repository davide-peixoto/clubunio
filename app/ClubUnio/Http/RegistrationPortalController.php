<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Models\DocumentType;
use App\ClubUnio\Models\MemberType;
use App\ClubUnio\Models\RegistrationStatus;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\MemberAccessService;
use App\ClubUnio\Services\MemberDocumentService;
use App\ClubUnio\Services\RegistrationDataService;
use App\ClubUnio\Services\RegistrationReviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RegistrationPortalController
{
    private function authorize(Request $request, Tenant $tenant): void
    {
        $access = app(MemberAccessService::class);
        $actor = $request->user('portal');
        abort_unless($access->canEnter($actor, $tenant)
            && in_array($access->membership($actor, $tenant)?->role, ['admin', 'office', 'technical_lead'], true), 403);
    }

    public function index(Request $request, Tenant $tenant, RegistrationReviewService $service)
    {
        $this->authorize($request, $tenant);
        $data = $request->validate(['q' => 'nullable|string|max:120', 'status' => 'nullable|integer']);
        $q = trim($data['q'] ?? '');
        $registrations = $service->visible($request->user('portal'), $tenant)->with(['status', 'teamSeason'])
            ->when($q !== '', fn ($query) => $query->where(fn ($query) => $query->where('first_name', 'ilike', '%'.$q.'%')->orWhere('last_name', 'ilike', '%'.$q.'%')))
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status_id', $status))
            ->orderByDesc('id')->paginate(25)->withQueryString();
        $statuses = RegistrationStatus::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true)->orderBy('sort_order')->get();
        $tenants = collect([$tenant]);

        return response()->view('clubunio.portal.registrations', compact('tenant', 'tenants', 'registrations', 'statuses', 'q'))->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, Tenant $tenant, int $registration, RegistrationReviewService $service)
    {
        $this->authorize($request, $tenant);
        $actor = $request->user('portal');
        $r = $service->visible($actor, $tenant)->with(['member', 'status', 'teamSeason'])->findOrFail($registration);
        $values = app(RegistrationDataService::class)->formValues($r);
        $teams = $service->assignableTeams($actor, $tenant)->orderBy('label')->get();
        if ($r->teamSeason && ! $teams->contains('id', $r->team_season_id)) {
            $teams->prepend($r->teamSeason);
        }
        $statuses = RegistrationStatus::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true)->whereIn('workflow_key', ['review', 'trial', 'accepted', 'rejected', 'pool'])->orderBy('sort_order')->get();
        $types = MemberType::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true)->get();
        $documentTypes = DocumentType::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true)->get();
        $documents = DB::connection('clubunio')->table('clubora.registration_documents')->where('tenant_id', $tenant->id)->where('registration_id', $r->id)->where('active', true)->get();
        $tenants = collect([$tenant]);

        return response()->view('clubunio.portal.registration', compact('tenant', 'tenants', 'r', 'values', 'teams', 'statuses', 'types', 'documentTypes', 'documents'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, Tenant $tenant, int $registration, RegistrationReviewService $service)
    {
        $this->authorize($request, $tenant);
        $service->review($request->user('portal'), $tenant, $registration,
            $request->only(['status_id', 'team_season_id', 'revision', 'reason', 'member_type_id', 'document_type_id', 'member_version']));

        return redirect()->route('clubunio.portal.registration', ['tenant' => $tenant->slug, 'registration' => $registration])->with('saved', true);
    }

    public function document(Request $request, Tenant $tenant, int $document, MemberDocumentService $service)
    {
        $this->authorize($request, $tenant);

        return $service->openRegistration($request->user('portal'), $tenant, $document, $request->boolean('download'));
    }
}
