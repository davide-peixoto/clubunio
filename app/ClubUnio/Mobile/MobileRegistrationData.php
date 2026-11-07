<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, Registration, Tenant};
use App\ClubUnio\Services\{MemberService, ModulePermissionService, RegistrationAcceptanceService, RegistrationDataService, RegistrationReviewService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MobileRegistrationData
{
    public const FIELDS = ['Vorname' => 'first_name', 'Nachname' => 'last_name', 'Email' => 'email', 'Ort' => 'city', 'Geburtsdatum' => 'birth_date',
        'Strasse' => 'street', 'HausNr' => 'house_number', 'PLZ' => 'postal_code', 'Land' => 'country_id', 'Nationalitaet' => 'nationality_id',
        'Geschlecht' => 'gender_id', 'Mobile' => 'mobile', 'TelefonPrivat' => 'phone_private', 'Mutter' => 'mother_name', 'EmailMutter' => 'mother_email',
        'MobileMutter' => 'mother_mobile', 'Vater' => 'father_name', 'EmailVater' => 'father_email', 'MobileVater' => 'father_mobile',
        'RechnungAn' => 'billing_recipient_id', 'Bemerkungen' => 'notes', 'Geburtsort' => 'birth_place', 'BisherigerVerein' => 'previous_club',
        'BisherigerVereinLand' => 'previous_club_country_type', 'BisherigerVereinVorhanden' => 'has_previous_club'];
    private function review(): RegistrationReviewService { return app(RegistrationReviewService::class); }
    private function table(Tenant $tenant, string $table) { return DB::connection('clubunio')->table('clubora.'.$table)->where('tenant_id', $tenant->id); }
    private function canUpload(Account $actor, Tenant $tenant): bool
    {
        return (bool) config('clubunio-mobile.uploads_enabled') && app(\App\ClubUnio\Services\TenantDocumentStorage::class)->canUpload($tenant)
            && app(ModulePermissionService::class)->allows($actor, $tenant, 'registrations', 'upload');
    }
    public function authorizeReader(Account $actor, Tenant $tenant): void
    {
        app(MobileAccess::class)->enter($actor, $tenant);
        abort_unless(app(ModulePermissionService::class)->allows($actor, $tenant, 'registrations', 'view')
            || app(\App\ClubUnio\Services\MemberAccessService::class)->membership($actor, $tenant)?->role === 'technical_lead', 403);
    }
    public function registration(Account $actor, Tenant $tenant, int $id): Registration
    {
        $this->authorizeReader($actor, $tenant);
        return $this->review()->visible($actor, $tenant)->with(['status', 'teamSeason'])->findOrFail($id);
    }
    public function index(Request $request, Account $actor, Tenant $tenant): array
    {
        $this->authorizeReader($actor, $tenant);
        $d = $request->validate(['q' => 'nullable|string|max:120', 'status' => 'nullable|integer|min:1', 'start' => 'nullable|integer|min:0|max:100000', 'recperpage' => 'nullable|integer|min:1|max:50']);
        $query = $this->review()->visible($actor, $tenant)->with(['status', 'teamSeason']);
        if ($search = trim($d['q'] ?? '')) {
            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(fn ($q) => $q->where('first_name', 'ilike', $pattern)->orWhere('last_name', 'ilike', $pattern));
        }
        $counts = (clone $query)->reorder()->selectRaw('status_id, COUNT(*) AS count')->groupBy('status_id')->toBase()->get()->mapWithKeys(fn ($row) => [(int) $row->status_id => (int) $row->count])->all();
        if ($d['status'] ?? null) { $query->where('status_id', $d['status']); }
        $total = (clone $query)->count();
        return ['Registrations' => $query->orderByDesc('id')->offset($d['start'] ?? 0)->limit($d['recperpage'] ?? 20)->get()->map(fn ($r) => $this->summary($r))->all(),
            'totalRecordCount' => $total, 'statusCounts' => (object) $counts,
            'CanCreate' => app(ModulePermissionService::class)->allows($actor, $tenant, 'registrations', 'create')];
    }
    private function summary(Registration $r): array
    {
        return ['AnmeldungID' => (int) $r->id, 'Vorname' => $r->first_name, 'Nachname' => $r->last_name,
            'AnmeldungStatusID' => $r->status_id ? (int) $r->status_id : null, 'AnmeldungStatusText' => $r->status?->name,
            'WorkflowKey' => $r->status?->workflow_key, 'TeamSaisonID' => $r->team_season_id ? (int) $r->team_season_id : null,
            'TeamSaisonLabel' => $r->teamSeason?->label, 'Revision' => (int) $r->revision];
    }
    public function details(Account $actor, Tenant $tenant, int $id): array
    {
        $r = $this->registration($actor, $tenant, $id); $permissions = app(ModulePermissionService::class);
        $values = app(RegistrationDataService::class)->formValues($r); // Deliberately excludes AHV.
        $dto = $this->summary($r);
        foreach (self::FIELDS as $legacy => $field) { $dto[$legacy] = $values[$field] ?? null; }
        // The original country picker uses codes; IDs remain the canonical server contract.
        foreach (['Land' => 'country_id', 'Nationalitaet' => 'nationality_id'] as $field => $idField) {
            $country = $this->table($tenant, 'countries')->where('id', $values[$idField] ?? null)->first(['code', 'iso3', 'name']);
            $dto[$field] = $field === 'Nationalitaet' ? ($country?->iso3 ?: $country?->code) : $country?->code;
            $dto[$field.'Text'] = $country?->name;
        }
        foreach (['Geschlecht', 'RechnungAn'] as $field) { $dto[$field] = isset($dto[$field]) ? (string) $dto[$field] : ''; }
        $dto['GeschlechtText'] = $this->table($tenant, 'genders')->where('id', $values['gender_id'] ?? null)->value('name');
        $dto['RechnungAnText'] = $this->table($tenant, 'billing_recipients')->where('id', $values['billing_recipient_id'] ?? null)->value('name');
        $dto += ['EingereichtAm' => $r->submitted_at?->toIso8601String(), 'GeprueftAm' => $r->reviewed_at?->toIso8601String(),
            'AkzeptiertAm' => $r->accepted_at?->toIso8601String(),
            'AbgelehntAm' => $r->rejected_at ? \Carbon\CarbonImmutable::parse($r->rejected_at)->toIso8601String() : null];
        $dto += ['CanEdit' => $r->status?->workflow_key !== 'accepted' && $permissions->allows($actor, $tenant, 'registrations', 'update'),
            'CanReview' => $r->status?->workflow_key !== 'accepted' && $this->review()->canReview($actor, $tenant, $r),
            'CanAssignLeader' => $permissions->allows($actor, $tenant, 'registrations', 'approve'),
            'CanUpload' => $r->status?->workflow_key !== 'accepted' && $this->canUpload($actor, $tenant),
            'CanRemoveAttachments' => $r->status?->workflow_key !== 'accepted' && $permissions->allows($actor, $tenant, 'registrations', 'deactivate'),
            'TechnischerLeiterMitgliederID' => $r->technical_lead_member_id ? (int) $r->technical_lead_member_id : null,
            'MitgliederID' => $r->member_id ? (int) $r->member_id : null, 'DetailsComplete' => (bool) $r->details_complete];
        $files = $this->table($tenant, 'registration_documents')->where('registration_id', $id)->where('active', true)->orderBy('id')->get(['id', 'kind', 'original_filename', 'filename', 'mime_type', 'sha256', 'file_size']);
        foreach (['portrait' => 'PassfotoUpload', 'identity' => 'AusweisUpload'] as $kind => $field) {
            $dto[$field] = $files->where('kind', $kind)->map(fn ($file) => ['id' => (int) $file->id, 'name' => $file->original_filename ?: $file->filename,
                'type' => $file->mime_type, 'sha256' => $file->sha256, 'size' => $file->file_size,
                'url' => 'registration:'.$id.':'.$file->id])->values()->all();
        }
        $dto['PendingAttachments'] = app(\App\ClubUnio\Services\DocumentUploadService::class)->table()->where('tenant_id', $tenant->id)->where('kind', 'registration')->where('object_id', $id)->where('state', 'pending')->count();
        $dto['PendingUploads'] = app(\App\ClubUnio\Services\DocumentUploadService::class)->table()->where('tenant_id', $tenant->id)->where('kind', 'registration')->where('object_id', $id)->where('state', 'pending')->orderBy('id')->get(['id', 'state'])->toArray();
        return $dto;
    }
    public function options(Account $actor, Tenant $tenant, ?int $id): array
    {
        $this->authorizeReader($actor, $tenant);
        if ($id) { $r = $this->registration($actor, $tenant, $id); }
        $retained = isset($r) ? app(RegistrationDataService::class)->formValues($r) : [];
        $result = ['CanUpload' => $this->canUpload($actor, $tenant)];
        foreach (['Statuses' => 'registration_statuses', 'Countries' => 'countries', 'Genders' => 'genders', 'BillingRecipients' => 'billing_recipients', 'MemberTypes' => 'member_types', 'DocumentTypes' => 'document_types'] as $key => $table) {
            $old = match ($table) { 'countries' => [$retained['country_id'] ?? null, $retained['nationality_id'] ?? null],
                'genders' => [$retained['gender_id'] ?? null], 'billing_recipients' => [$retained['billing_recipient_id'] ?? null],
                'registration_statuses' => [isset($r) ? $r->status_id : null], default => [] };
            $query = $this->table($tenant, $table)->where(fn ($q) => $q->where('active', true)->orWhereIn('id', array_filter($old)));
            $columns = ['id', 'code', 'name'];
            if ($table === 'registration_statuses') { $columns[] = 'workflow_key'; }
            if ($table === 'countries') { $columns[] = 'iso3'; }
            $result[$key] = $query->orderBy('name')->get($columns)->map(fn ($row) => (array) $row)->all();
        }
        $result['Teams'] = $this->review()->assignableTeams($actor, $tenant)->orderBy('label')->get(['id', 'team_id', 'season_id', 'label'])->toArray();
        $result['Leaders'] = app(ModulePermissionService::class)->allows($actor, $tenant, 'registrations', 'approve')
            ? $this->review()->leaders($tenant)->orderBy('last_name')->get(['id', 'first_name', 'last_name'])->toArray() : [];
        $result['MemberCandidates'] = isset($r) && app(ModulePermissionService::class)->allows($actor, $tenant, 'registrations', 'approve')
            ? app(RegistrationAcceptanceService::class)->possibleDuplicates($tenant, $r)->whereIn('id', app(MobileMemberData::class)->scope($actor, $tenant)->select('clubora.members.id'))->limit(30)->get()->map(fn ($m) => ['id' => (int) $m->id,
                'label' => trim($m->first_name.' '.$m->last_name), 'city' => $m->city, 'version' => MemberService::version($m)])->all() : [];
        if (isset($r) && $r->member_id && ! collect($result['MemberCandidates'])->contains('id', (int) $r->member_id)) {
            $linked = \App\ClubUnio\Models\Member::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereNull('archived_at')->find($r->member_id);
            if ($linked) { $result['MemberCandidates'][] = ['id' => (int) $linked->id, 'label' => trim($linked->first_name.' '.$linked->last_name),
                'city' => $linked->city, 'version' => MemberService::version($linked)]; }
        }
        return $result;
    }
    public function candidates(Request $request, Account $actor, Tenant $tenant, int $id): array
    {
        $registration = $this->registration($actor, $tenant, $id);
        abort_unless($this->review()->canReview($actor, $tenant, $registration), 403);
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'registrations', 'approve');
        $d = $request->validate(['q' => 'nullable|string|max:120', 'start' => 'nullable|integer|min:0|max:100000', 'recperpage' => 'nullable|integer|min:1|max:20']);
        $query = app(MobileMemberData::class)->scope($actor, $tenant)->whereNull('archived_at');
        if ($search = trim($d['q'] ?? '')) {
            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(fn ($q) => $q->where('first_name', 'ilike', $pattern)->orWhere('last_name', 'ilike', $pattern));
        }
        return ['Members' => $query->orderBy('last_name')->orderBy('first_name')->orderBy('id')->offset($d['start'] ?? 0)->limit($d['recperpage'] ?? 20)->get()
            ->map(fn ($member) => ['id' => (int) $member->id, 'label' => trim($member->first_name.' '.$member->last_name),
                'city' => $member->city, 'version' => MemberService::version($member)])->all()];
    }
}
