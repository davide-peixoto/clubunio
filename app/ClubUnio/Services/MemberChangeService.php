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

    public function values(Member $member): array
    {
        return array_replace($member->only(array_keys(self::FIELDS)), ['birth_date' => $member->birth_date?->format('Y-m-d')]);
    }

    public function submit(Account $actor, Tenant $tenant, int $id, array $input): MemberChangeRequest
    {
        $this->represented($actor, $tenant, $id);

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $input) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $member = $this->represented($actor, $tenant, $id, true);
            if (array_diff(array_keys($input), [...array_keys(self::FIELDS), '_version'])) {
                throw ValidationException::withMessages(['member' => 'Nicht erlaubtes Änderungsfeld.']);
            }
            Validator::make($input, ['_version' => 'required|string|size:64'])->validate();
            if (! hash_equals(MemberService::version($member), $input['_version'])) {
                throw ValidationException::withMessages(['member' => 'Mitglied wurde inzwischen geändert. Bitte erneut öffnen.']);
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
                $rules[$field] = ['nullable', 'integer', Rule::exists('clubunio.clubora.'.$table, 'id')->where(fn ($q) => $q->where('tenant_id', $tenant->id)
                    ->where(fn ($q) => $q->where('active', true)->orWhere('id', $member->$field ?? -1)))];
            }
            $data = Validator::make($data, $rules, [], self::FIELDS)->validate();
            if ($data['billing_recipient_id'] ?? null) {
                $emailField = DB::connection('clubunio')->table('clubora.billing_recipients')->where('tenant_id', $tenant->id)->where('id', $data['billing_recipient_id'])->value('email_field');
                if (empty($data[$emailField])) {
                    throw ValidationException::withMessages([$emailField => 'E-Mail-Adresse für den Rechnungsempfänger erforderlich.']);
                }
            }
            $changes = [];
            foreach ($data as $field => $value) {
                if ((string) $value !== (string) ($before[$field] ?? null)) {
                    $changes[$field] = $value;
                }
            }
            if (! $changes || MemberChangeRequest::where('tenant_id', $tenant->id)->where('member_id', $id)->where('state', 'pending')->exists()) {
                throw ValidationException::withMessages(['member' => $changes ? 'Für dieses Mitglied ist bereits ein Antrag in Prüfung.' : 'Keine Änderungen vorhanden.']);
            }
            $request = new MemberChangeRequest;
            $request->forceFill(['tenant_id' => $tenant->id, 'member_id' => $id, 'requested_by' => $actor->id,
                'status_id' => $this->status($tenant, 'pending'), 'state' => 'pending', 'revision' => 1,
                'member_version' => MemberService::version($member),
                'snapshot_encrypted' => Crypt::encryptString(json_encode(['before' => array_intersect_key($before, $changes), 'changes' => $changes], JSON_THROW_ON_ERROR))])->save();
            $this->audit($actor, $request, 'submitted');
            $this->notify($tenant, $request, 'member.change.requested', true);

            return $request;
        });
    }

    public function review(Account $actor, Tenant $tenant, int $id, int $revision, bool $approve, ?string $note): MemberChangeRequest
    {
        abort_unless($actor->fresh()?->canAccessTenant($tenant), 403);
        Validator::make(['note' => $note], ['note' => ($approve ? 'nullable' : 'required').'|string|max:2000'])->validate();

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $revision, $approve, $note) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->fresh()?->canAccessTenant($tenant), 403);
            $r = MemberChangeRequest::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($id);
            $this->pending($r, $revision);
            // Revoked parent/self links and departed members cannot be approved retrospectively.
            if ($approve) {
                try {
                    $member = $this->represented($r->requester, $tenant, $r->member_id, true);
                } catch (ModelNotFoundException|\Symfony\Component\HttpKernel\Exception\HttpException) {
                    throw ValidationException::withMessages(['revision' => 'Die Mitglieds- oder Elternberechtigung ist nicht mehr gültig. Bitte Antrag ablehnen.']);
                }
                if (! hash_equals($r->member_version, MemberService::version($member))) {
                    throw ValidationException::withMessages(['revision' => 'Konflikt: Mitglied wurde inzwischen geändert. Antrag ablehnen und neu erfassen lassen.']);
                }
                app(MemberService::class)->update($actor, $tenant, $member->id, $this->snapshot($r)['changes'] + ['_version' => $r->member_version]);
            }
            $state = $approve ? 'approved' : 'rejected';
            $r->forceFill(['state' => $state, 'status_id' => $this->status($tenant, $state), 'revision' => $revision + 1,
                'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'decision_note' => $note])->save();
            $this->audit($actor, $r, $state);
            $this->notify($tenant, $r, 'member.change.'.$state, false);

            return $r;
        });
    }

    public function withdraw(Account $actor, Tenant $tenant, int $id, int $revision): void
    {
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $revision) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $r = MemberChangeRequest::where('tenant_id', $tenant->id)->where('requested_by', $actor->id)->lockForUpdate()->findOrFail($id);
            $this->represented($actor, $tenant, $r->member_id);
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
                $label = fn ($v) => DB::connection('clubunio')->table('clubora.'.$table)->where('tenant_id', $tenant->id)->where('id', $v)->value('name');
            }
            $rows[] = ['field' => self::FIELDS[$field], 'before' => $label($snapshot['before'][$field]), 'after' => $label($value)];
        }

        return $rows;
    }

    private function pending(MemberChangeRequest $r, int $revision): void
    {
        if ($r->state !== 'pending' || $r->revision !== $revision) {
            throw ValidationException::withMessages(['revision' => 'Antrag wurde inzwischen bearbeitet. Bitte neu öffnen.']);
        }
    }

    private function status(Tenant $tenant, string $state): int
    {
        return (int) DB::connection('clubunio')->table('clubora.member_change_statuses')->where('tenant_id', $tenant->id)->where('code', $state)->sole()->id;
    }

    private function audit(Account $actor, MemberChangeRequest $r, string $operation): void
    {
        DB::connection('clubunio')->table('clubora.object_changes')->insert(['tenant_id' => $r->tenant_id, 'actor_id' => $actor->id,
            'object_type' => 'member_change_requests', 'object_id' => $r->id,
            'after_value' => json_encode(['operation' => $operation, 'revision' => $r->revision, 'member_id' => $r->member_id], JSON_THROW_ON_ERROR)]);
    }

    private function notify(Tenant $tenant, MemberChangeRequest $r, string $event, bool $office): void
    {
        $db = DB::connection('clubunio');
        $accounts = $db->table('clubora.tenant_accounts as ta')->join('clubora.accounts as a', 'a.id', '=', 'ta.account_id')->where('ta.tenant_id', $tenant->id)->where('a.active', true);
        $office ? $accounts->whereIn('ta.role', ['admin', 'office']) : $accounts->where('a.id', $r->requested_by);
        $message = app(MessageTemplateService::class)->render($tenant, $event, 'push', ['verein' => $tenant->name]);
        if ($message) {
            foreach ($accounts->pluck('a.id') as $account) {
                $db->table('clubora.notifications')->insert(['tenant_id' => $tenant->id, 'account_id' => $account,
                    'title' => $message['title'], 'body' => $message['body'], 'object_type' => 'member_change_request', 'object_id' => $r->id]);
            }
        }
    }
}
