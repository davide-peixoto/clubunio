<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\MemberChangeRequest;
use App\ClubUnio\Models\Tenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MemberChangeService
{
    public const FIELDS = ['first_name' => 'Vorname', 'last_name' => 'Nachname', 'birth_date' => 'Geburtsdatum',
        'email' => 'E-Mail', 'street' => 'Strasse', 'house_number' => 'Hausnummer', 'postal_code' => 'PLZ', 'city' => 'Ort',
        'country_id' => 'Land', 'nationality_id' => 'Nationalität', 'gender_id' => 'Geschlecht',
        'phone_private' => 'Telefon privat', 'phone_business' => 'Telefon Geschäft', 'mobile' => 'Mobile',
        'mother_name' => 'Name Mutter', 'mother_email' => 'E-Mail Mutter', 'mother_mobile' => 'Mobile Mutter',
        'father_name' => 'Name Vater', 'father_email' => 'E-Mail Vater', 'father_mobile' => 'Mobile Vater',
        'billing_recipient_id' => 'Rechnung an'];

    public function represented(Account $actor, Tenant $tenant, int $id, bool $lock = false): Member
    {
        $actor = $actor->fresh();
        abort_unless($actor && app(MemberAccessService::class)->canEnter($actor, $tenant), 403);

        $query = app(MemberAccessService::class)->represented($actor, $tenant);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($id);
    }

    private function requestable(Account $actor, Tenant $tenant, int $id, bool $lock, ?int $teamId): Member
    {
        if ($teamId === null) return $this->represented($actor, $tenant, $id, $lock);
        $fresh = $actor->fresh();
        abort_unless($fresh && app(MemberAccessService::class)->canEnter($fresh, $tenant), 403);
        return app(MemberTeamAccessService::class)->requestable($fresh, $tenant, $teamId, $id, $lock);
    }

    public function values(Member $member): array
    {
        return array_replace($member->only(array_keys(self::FIELDS)), ['birth_date' => $member->birth_date?->format('Y-m-d')]);
    }

    public function submit(Account $actor, Tenant $tenant, int $id, array $input, ?int $teamId = null): MemberChangeRequest
    {
        $this->requestable($actor, $tenant, $id, false, $teamId);

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $input, $teamId) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $member = $this->requestable($actor, $tenant, $id, true, $teamId);
            return $this->createPending($actor, $tenant, $member, $input, $teamId);
        });
    }

    public function submitCampaign(Tenant $tenant, string $token, array $input, ?\Illuminate\Http\UploadedFile $photo = null): MemberChangeRequest
    {
        app(MemberCampaignService::class)->invitation($tenant, $token);
        $portrait = $photo ? app(ProfilePhotoService::class)->encode($photo) : null;
        return DB::connection('clubunio')->transaction(function () use ($tenant, $token, $input, $portrait) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            [$recipient, $member, $campaign] = app(MemberCampaignService::class)->invitation($tenant, $token, true);
            abort_unless($campaign->kind === 'member_update', 404);
            abort_if(array_diff(array_keys($input), [...MemberCampaignService::FIELDS, '_version']), 422);
            $request = $this->createPending(null, $tenant, $member, $input, null, $portrait);
            app(MemberCampaignService::class)->consume($tenant, $recipient, $request);

            return $request;
        });
    }

    private function createPending(?Account $actor, Tenant $tenant, Member $member, array $input, ?int $teamId, ?string $portrait = null): MemberChangeRequest
    {
            $id = $member->id;
            if (array_diff(array_keys($input), [...array_diff(array_keys(self::FIELDS), $teamId !== null ? ['billing_recipient_id'] : []), '_version'])) {
                throw ValidationException::withMessages(['member' => __('Nicht erlaubtes Änderungsfeld.')]);
            }
            Validator::make($input, ['_version' => 'required|string|size:64'])->validate();
            if (! hash_equals(MemberService::version($member), $input['_version'])) {
                throw ValidationException::withMessages(['member' => __('Mitglied wurde inzwischen geändert. Bitte erneut öffnen.')]);
            }
            $before = $this->values($member);
            $data = array_replace($before, array_intersect_key($input, self::FIELDS));
            $rules = [];
            foreach (self::FIELDS as $field => $label) {
                if (is_string($data[$field] ?? null)) {
                    $data[$field] = trim($data[$field]);
                    if ($data[$field] === '') {
                        $data[$field] = null;
                    }
                }
                $rules[$field] = 'nullable|string|max:255';
            }
            foreach (['first_name', 'last_name'] as $field) {
                $rules[$field] = ($member->company ? 'nullable' : 'required').'|string|max:255';
            }
            foreach (['email', 'mother_email', 'father_email'] as $field) {
                $rules[$field] = 'nullable|email:rfc|max:254';
            }
            foreach (['phone_private', 'phone_business', 'mobile', 'mother_mobile', 'father_mobile'] as $field) {
                $rules[$field] = 'nullable|string|max:50';
            }
            foreach (['postal_code', 'house_number'] as $field) {
                $rules[$field] = 'nullable|string|max:20';
            }
            $rules['birth_date'] = 'nullable|date_format:Y-m-d|before_or_equal:today';
            foreach (['country_id' => 'countries', 'nationality_id' => 'countries', 'gender_id' => 'genders', 'billing_recipient_id' => 'billing_recipients'] as $field => $table) {
                $rules[$field] = ['nullable', 'integer', Rule::exists('clubunio.clubunio.'.$table, 'id')->where(fn ($q) => $q->where('tenant_id', $tenant->id)
                    ->where(fn ($q) => $q->where('active', true)->orWhere('id', $member->$field ?? -1)))];
            }
            $data = Validator::make($data, $rules, [], self::FIELDS)->validate();
            if ($data['billing_recipient_id'] ?? null) {
                $emailField = DB::connection('clubunio')->table('clubunio.billing_recipients')->where('tenant_id', $tenant->id)->where('id', $data['billing_recipient_id'])->value('email_field');
                if (empty($data[$emailField])) {
                    throw ValidationException::withMessages([$emailField => __('E-Mail-Adresse für den Rechnungsempfänger erforderlich.')]);
                }
            }
            $changes = [];
            foreach ($data as $field => $value) {
                if ((string) $value !== (string) ($before[$field] ?? null)) {
                    $changes[$field] = $value;
                }
            }
            if ((! $changes && ! $portrait) || MemberChangeRequest::where('tenant_id', $tenant->id)->where('member_id', $id)->where('state', 'pending')->exists()) {
                throw ValidationException::withMessages(['member' => ($changes || $portrait) ? __('Für dieses Mitglied ist bereits ein Antrag in Prüfung.') : __('Keine Änderungen vorhanden.')]);
            }
            $request = new MemberChangeRequest;
            $request->forceFill(['tenant_id' => $tenant->id, 'member_id' => $id, 'requested_by' => $actor?->id,
                'status_id' => $this->status($tenant, 'pending'), 'state' => 'pending', 'revision' => 1,
                'member_version' => MemberService::version($member),
                'snapshot_encrypted' => Crypt::encryptString(json_encode(['before' => array_intersect_key($before, $changes), 'changes' => $changes, 'team_id' => $teamId], JSON_THROW_ON_ERROR))])->save();
            if ($actor) {
                $this->audit($actor, $request, 'submitted');
            }
            if ($portrait) {
                $request->forceFill(['portrait_encrypted' => $portrait])->save();
            }
            $this->notify($tenant, $request, 'member.change.requested', true);

            return $request;
    }

    public function review(Account $actor, Tenant $tenant, int $id, int $revision, bool $approve, ?string $note): MemberChangeRequest
    {
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'member_changes', 'approve');
        Validator::make(['note' => $note], ['note' => ($approve ? 'nullable' : 'required').'|string|max:2000'])->validate();

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $revision, $approve, $note) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            app(ModulePermissionService::class)->authorize($actor, $tenant, 'member_changes', 'approve');
            $r = MemberChangeRequest::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($id);
            $this->pending($r, $revision);
            if ($r->requested_by === null) {
                abort_unless(app(MemberCampaignService::class)->allowed($actor, $tenant), 403);
            }
            // Revoked parent/self links and departed members cannot be approved retrospectively.
            if ($approve) {
                try {
                    $member = $r->requested_by === null
                        ? app(MemberCampaignService::class)->reviewMember($tenant, $r)
                        : $this->requestable($r->requester, $tenant, $r->member_id, true, $this->snapshot($r)['team_id'] ?? null);
                } catch (ModelNotFoundException|\Illuminate\Database\RecordNotFoundException|\Symfony\Component\HttpKernel\Exception\HttpException) {
                    throw ValidationException::withMessages(['revision' => __('Die Mitglieds-, Eltern- oder Trainerberechtigung ist nicht mehr gültig. Bitte Antrag ablehnen.')]);
                }
                if (! hash_equals($r->member_version, MemberService::version($member))) {
                    throw ValidationException::withMessages(['revision' => __('Konflikt: Mitglied wurde inzwischen geändert. Antrag ablehnen und neu erfassen lassen.')]);
                }
                $changes = $this->snapshot($r)['changes'];
                if ($changes) {
                    app(MemberService::class)->update($actor, $tenant, $member->id, $changes + ['_version' => $r->member_version]);
                }
                if ($r->portrait_encrypted) {
                    app(ProfilePhotoService::class)->publishCampaign($tenant, $member->id, $r->portrait_encrypted);
                }
            }
            if ($r->portrait_encrypted) $r->forceFill(['portrait_encrypted' => null]);
            $state = $approve ? 'approved' : 'rejected';
            $r->forceFill(['state' => $state, 'status_id' => $this->status($tenant, $state), 'revision' => $revision + 1,
                'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'decision_note' => $note])->save();
            $this->audit($actor, $r, $state);
            if ($r->requested_by !== null) {
                $this->notify($tenant, $r, 'member.change.'.$state, false);
            }

            return $r;
        });
    }

    public function withdraw(Account $actor, Tenant $tenant, int $id, int $revision): void
    {
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $revision) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $r = MemberChangeRequest::where('tenant_id', $tenant->id)->where('requested_by', $actor->id)->lockForUpdate()->findOrFail($id);
            $this->requestable($actor, $tenant, $r->member_id, false, $this->snapshot($r)['team_id'] ?? null);
            $this->pending($r, $revision);
            $r->forceFill(['state' => 'withdrawn', 'status_id' => $this->status($tenant, 'withdrawn'), 'revision' => $revision + 1])->save();
            $this->audit($actor, $r, 'withdrawn');
        });
    }

    public function snapshot(MemberChangeRequest $request): array
    {
        return json_decode(Crypt::decryptString($request->snapshot_encrypted), true, 512, JSON_THROW_ON_ERROR);
    }

    public function comparison(MemberChangeRequest $request, Tenant $tenant): array
    {
        abort_unless((int) $request->tenant_id === (int) $tenant->id, 403);
        $snapshot = $this->snapshot($request);
        $rows = [];
        foreach ($snapshot['changes'] as $field => $value) {
            $label = fn ($v) => $v;
            $table = ['country_id' => 'countries', 'nationality_id' => 'countries', 'gender_id' => 'genders', 'billing_recipient_id' => 'billing_recipients'][$field] ?? null;
            if ($table) {
                $label = fn ($v) => DB::connection('clubunio')->table('clubunio.'.$table)->where('tenant_id', $tenant->id)->where('id', $v)->value('name');
            }
            $rows[] = ['field' => self::FIELDS[$field], 'before' => $label($snapshot['before'][$field]), 'after' => $label($value)];
        }

        return $rows;
    }

    private function pending(MemberChangeRequest $r, int $revision): void
    {
        if ($r->state !== 'pending' || $r->revision !== $revision) {
            throw ValidationException::withMessages(['revision' => __('Antrag wurde inzwischen bearbeitet. Bitte neu öffnen.')]);
        }
    }

    private function status(Tenant $tenant, string $state): int
    {
        return (int) DB::connection('clubunio')->table('clubunio.member_change_statuses')->where('tenant_id', $tenant->id)->where('code', $state)->sole()->id;
    }

    private function audit(Account $actor, MemberChangeRequest $r, string $operation): void
    {
        DB::connection('clubunio')->table('clubunio.object_changes')->insert(['tenant_id' => $r->tenant_id, 'actor_id' => $actor->id,
            'object_type' => 'member_change_requests', 'object_id' => $r->id,
            'after_value' => json_encode(['operation' => $operation, 'revision' => $r->revision, 'member_id' => $r->member_id], JSON_THROW_ON_ERROR)]);
    }

    private function notify(Tenant $tenant, MemberChangeRequest $r, string $event, bool $office): void
    {
        $db = DB::connection('clubunio');
        $accounts = $db->table('clubunio.tenant_accounts as ta')->join('clubunio.accounts as a', 'a.id', '=', 'ta.account_id')->where('ta.tenant_id', $tenant->id)->where('a.active', true);
        $office ? $accounts->whereIn('ta.role', ['admin', 'office']) : $accounts->where('a.id', $r->requested_by);
        $messages = app(MessageTemplateService::class)->renderForAccounts($tenant, $event, 'push', ['verein' => $tenant->name], $accounts->pluck('a.id')->all());
        foreach ($messages as $account => $message) {
            if ($message) {
                $db->table('clubunio.notifications')->insert(['tenant_id' => $tenant->id, 'account_id' => $account,
                    'title' => $message['title'], 'body' => $message['body'], 'object_type' => 'member_change_request', 'object_id' => $r->id]);
            }
        }
    }
}
