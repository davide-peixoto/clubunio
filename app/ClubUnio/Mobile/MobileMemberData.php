<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, Member, MemberChangeRequest, Tenant};
use App\ClubUnio\Services\{InvoiceAccessService, InvoiceTaxService, MemberAccessService, MemberChangeService, MemberService, ModulePermissionService, MemberTeamAccessService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MobileMemberData
{
    public const CONTACT = ['Strasse' => 'street', 'HausNr' => 'house_number', 'PLZ' => 'postal_code', 'Ort' => 'city',
        'Land' => 'country_id', 'Nationalitaet' => 'nationality_id', 'TelefonPrivat' => 'phone_private', 'TelefonGeschaeft' => 'phone_business',
        'Mobile' => 'mobile', 'Email' => 'email', 'Homepage' => 'website', 'Mutter' => 'mother_name', 'MobileMutter' => 'mother_mobile',
        'EmailMutter' => 'mother_email', 'Vater' => 'father_name', 'MobileVater' => 'father_mobile', 'EmailVater' => 'father_email', 'RechnungAn' => 'billing_recipient_id'];
    public const STATES = ['pending' => 'OFFEN', 'approved' => 'GENEHMIGT', 'rejected' => 'ABGELEHNT', 'withdrawn' => 'ZURUECKGEZOGEN'];

    public function table(Tenant $tenant, string $table) { return DB::connection('clubunio')->table('clubunio.'.$table)->where('tenant_id', $tenant->id); }

    public function scope(Account $actor, Tenant $tenant)
    {
        app(MobileAccess::class)->enter($actor, $tenant);
        if (app(ModulePermissionService::class)->allows($actor, $tenant, 'members', 'view')) {
            return Member::withoutGlobalScopes()->where('tenant_id', $tenant->id);
        }
        if (app(MemberAccessService::class)->membership($actor,$tenant)?->role === 'trainer') return app(MemberTeamAccessService::class)->visibleTrainerContacts($actor,$tenant);
        return app(MemberAccessService::class)->visibleMembers($actor, $tenant);
    }

    public function member(Account $actor, Tenant $tenant, int $id): Member { return $this->scope($actor, $tenant)->whereKey($id)->firstOrFail(); }

    public function direct(Account $actor, Tenant $tenant): bool
    {
        return app(ModulePermissionService::class)->allows($actor, $tenant, 'members', 'update')
            && app(ModulePermissionService::class)->allows($actor, $tenant, 'members', 'view');
    }

    public function represented(Account $actor, Tenant $tenant, int $id): bool
    {
        return app(MemberAccessService::class)->represented($actor, $tenant)->whereKey($id)->exists();
    }

    public function details(Account $actor, Tenant $tenant, int $id, ?int $teamId = null): array
    {
        [$m, $mode] = $teamId === null ? [$this->member($actor, $tenant, $id), null]
            : app(MemberTeamAccessService::class)->member($actor, $tenant, $teamId, $id);
        $own = $this->represented($actor, $tenant, $id);
        $teamRestricted = $mode !== null && $mode !== 'full';
        $trainer = $mode === 'trainer';
        $full = $mode !== null ? in_array($mode, ['full', 'trainer'], true)
            : ($own || app(ModulePermissionService::class)->allows($actor, $tenant, 'members', 'view'));
        $direct = !$teamRestricted && $this->direct($actor, $tenant) && ! $m->archived_at;
        $editable = ($direct || ($own && !$teamRestricted) || $trainer) && ! $m->archived_at;
        $fields = $editable ? array_keys(self::CONTACT) : [];
        if (! $direct) { $fields = array_values(array_diff($fields, ['Homepage'])); }
        if ($trainer) { $fields = array_values(array_diff($fields, ['RechnungAn'])); }
        $features = explode(',', substr((string) request()->header('X-ClubUnio-Mobile-Features', ''), 0, 512));
        $documents = !$teamRestricted && (bool) config('clubunio-mobile.native_documents_enabled', false) && in_array('member-documents-v1', $features, true)
            && ($own || app(ModulePermissionService::class)->allows($actor, $tenant, 'documents', 'view'));
        $dto = ['MitgliederID' => (int) $m->id, 'Vorname' => $m->first_name, 'Nachname' => $m->last_name,
            'Email' => $m->email, 'Mobile' => $m->mobile, 'Ort' => $m->city, 'CanEdit' => $editable,
            'PhotoAvailable' => !$teamRestricted && ($own || $direct)
                && app(\App\ClubUnio\Services\ProfilePhotoService::class)->memberAvailable($tenant, $id),
            'ChangeMode' => $editable ? ($direct ? 'direct' : 'request') : 'none', 'EditableFields' => $fields,
            'TeamSaisonID' => $teamId, 'ContactOnly' => !$full, 'CanRequestChanges' => $editable && !$direct,
            'CanReadChangeRequests' => !$teamRestricted && app(ModulePermissionService::class)->allows($actor, $tenant, 'member_changes', 'view'),
            'CanReadInvoices' => !$teamRestricted && ($own || app(MobileAccess::class)->admin($actor, $tenant)), 'CanReadDocuments' => $documents, 'CanReadIdCard' => !$teamRestricted && (bool) config('clubunio-mobile.native_member_id_card_enabled', false)
                && in_array('member-id-card-v1', $features, true)
                && ($own || app(ModulePermissionService::class)->allows($actor, $tenant, 'members', 'view'))];
        if (! $full) { return $dto; }
        $dto += ['Firma' => $m->company, 'Geburtsdatum' => $m->birth_date?->format('Y-m-d'), 'Passnummer' => $m->pass_number,
            '_version' => MemberService::version($m)];
        foreach (self::CONTACT as $key => $column) { if ($trainer && $key === 'RechnungAn') continue; $dto[$key] = $m->{$column}; }
        $countryIds = array_values(array_unique(array_filter([$m->country_id, $m->nationality_id])));
        $countries = $countryIds ? $this->table($tenant, 'countries')->whereIn('id', $countryIds)->get()->keyBy('id') : collect();
        foreach (['Land' => 'country_id', 'Nationalitaet' => 'nationality_id'] as $key => $column) {
            $country = $countries->get($m->{$column});
            $dto[$key] = $country ? ($key === 'Land' ? $country->code : $country->iso3) : '';
            $dto[$key.'Text'] = $country?->name;
        }
        $recipient = !$trainer && $m->billing_recipient_id ? $this->table($tenant, 'billing_recipients')->where('id', $m->billing_recipient_id)->first() : null;
        if (!$trainer) { $dto['RechnungAn'] = $recipient ? (string) $recipient->id : ''; $dto['RechnungAnText'] = $recipient?->name; }
        $dto['Geschlecht'] = $m->gender_id ? $this->table($tenant, 'genders')->where('id', $m->gender_id)->value('name') : null;
        return $dto;
    }

    public function options(Account $actor, Tenant $tenant, int $id, ?int $teamId = null): array
    {
        [$m, $mode] = $teamId === null ? [$this->member($actor, $tenant, $id), null]
            : app(MemberTeamAccessService::class)->member($actor, $tenant, $teamId, $id);
        $trainer = $mode === 'trainer';
        abort_unless(($trainer || (($mode === null || $mode === 'full') && ($this->direct($actor, $tenant) || $this->represented($actor, $tenant, $id)))) && ! $m->archived_at, 403);
        return ['Countries' => $this->table($tenant, 'countries')->where(fn ($q) => $q->where('active', true)->orWhereIn('id', array_filter([$m->country_id, $m->nationality_id])))
            ->orderBy('name')->orderBy('id')->get(['code', 'iso3', 'name'])->map(fn ($c) => ['ISO2' => $c->code, 'ISO3' => $c->iso3, 'Name' => $c->name])->all(),
            'BillingRecipients' => $trainer ? [] : $this->table($tenant, 'billing_recipients')->where(fn ($q) => $q->where('active', true)->orWhere('id', $m->billing_recipient_id ?? -1))
                ->orderBy('sort_order')->orderBy('id')->get(['id', 'name'])->map(fn ($r) => ['value' => (string) $r->id, 'label' => $r->name])->all()];
    }

    public function invoices(Request $r, Account $actor, Tenant $tenant, int $id): array
    {
        $this->member($actor, $tenant, $id);
        // Match the existing personal invoice/PDF contract; roster visibility grants no financial access.
        abort_unless(app(MobileAccess::class)->admin($actor, $tenant) || $this->represented($actor, $tenant, $id), 403);
        $d = $r->validate(['start' => 'nullable|integer|min:0|max:100000', 'recperpage' => 'nullable|integer|min:1|max:100']);
        $query = app(InvoiceAccessService::class)->visible($actor, $tenant)->where('member_id', $id);
        $total = (clone $query)->count();
        return ['Invoices' => $query->with(['status', 'season'])->orderByDesc('season_id')->orderByDesc('id')->offset($d['start'] ?? 0)->limit($d['recperpage'] ?? 20)->get()
            ->map(fn ($invoice) => ['RechnungsID' => (int) $invoice->id, 'MitgliederID' => (int) $invoice->member_id,
                'SaisonID' => $invoice->season_id, 'SeasonLabel' => $invoice->season?->name, 'StatusLabel' => $invoice->status?->name,
                'AmountCents' => app(InvoiceTaxService::class)->summary($invoice)['total_cents'], 'Currency' => 'CHF',
                'HasPdf' => (bool) preg_match('/^[1-9][0-9]{0,14}$/', (string) $invoice->bexio_invoice_id)])->all(), 'totalRecordCount' => $total];
    }

    public function changes(Request $r, Account $actor, Tenant $tenant, int $id): array
    {
        $member = $this->member($actor, $tenant, $id);
        $permissions = app(ModulePermissionService::class);
        $permissions->authorize($actor, $tenant, 'member_changes', 'view');
        $d = $r->validate(['status' => 'nullable|in:OFFEN,GENEHMIGT,ABGELEHNT,ZURUECKGEZOGEN',
            'start' => 'nullable|integer|min:0|max:100000', 'recperpage' => 'nullable|integer|min:1|max:100']);
        $query = MemberChangeRequest::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('member_id', $id);
        if (! empty($d['status'])) { $query->where('state', array_search($d['status'], self::STATES, true)); }
        $total = (clone $query)->count();
        $approve = $permissions->allows($actor, $tenant, 'member_changes', 'approve');
        $canUpdate = $permissions->allows($actor, $tenant, 'members', 'update');
        return ['requests' => $query->with('requester')->orderByDesc('id')->offset($d['start'] ?? 0)->limit($d['recperpage'] ?? 20)->get()
            ->map(fn ($change) => ['AenderungsantragID' => (int) $change->id, 'MitgliederID' => $id, 'Revision' => (int) $change->revision,
                'Mitglied' => trim($member->first_name.' '.$member->last_name), 'Status' => self::STATES[$change->state],
                'CreatedAt' => $change->created_at?->toIso8601String(), 'AntragstellerUsername' => $change->requester?->display_name,
                'EntscheidAm' => $change->reviewed_at?->toIso8601String(), 'EntscheidBegruendung' => $change->decision_note,
                'CanApprove' => $approve && $canUpdate && $change->state === 'pending', 'CanReject' => $approve && $change->state === 'pending',
                'Aenderungen' => array_map(fn ($comparison) => ['field' => $comparison['field'], 'old' => $comparison['before'], 'new' => $comparison['after']],
                    app(MemberChangeService::class)->comparison($change, $tenant))])->all(), 'totalRecordCount' => $total];
    }
}
