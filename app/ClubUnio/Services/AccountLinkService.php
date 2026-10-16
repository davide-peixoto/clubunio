<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\AccountMemberLink;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountLinkService
{
    public function save(Account $actor, Tenant $tenant, ?int $id, array $input): AccountMemberLink
    {
        abort_unless($actor->hasUnrestrictedBackofficeAccess($tenant), 403);

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $input) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $record = $id ? AccountMemberLink::withoutGlobalScopes()->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($id) : new AccountMemberLink;
            $data = Validator::make($input, [
                'account_id' => ['required', 'integer', Rule::exists('clubunio.clubora.tenant_accounts', 'account_id')->where('tenant_id', $tenant->id)],
                'member_id' => ['required', 'integer', Rule::exists('clubunio.clubora.members', 'id')->where('tenant_id', $tenant->id)],
                'kind' => ['required', Rule::in(['self', 'parent'])], 'state' => ['required', Rule::in(['pending', 'approved', 'revoked'])],
                'revision' => $id ? 'required|integer' : 'prohibited', 'reason' => 'required|string|min:10|max:500',
                'tenant_id' => 'prohibited', 'reviewed_by' => 'prohibited',
            ])->validate();
            if ($id) {
                if ((int) $record->revision !== (int) $data['revision']) {
                    throw ValidationException::withMessages(['state' => 'Die Zuordnung wurde inzwischen geändert. Bitte neu öffnen.']);
                }
                foreach (['account_id', 'member_id', 'kind'] as $field) {
                    if ((string) $record->$field !== (string) $data[$field]) {
                        throw ValidationException::withMessages([$field => 'Bestehende Zuordnung widerrufen und neu erfassen.']);
                    }
                }
            }
            $duplicate = AccountMemberLink::withoutGlobalScopes()->where('tenant_id', $tenant->id)->when($id, fn ($q) => $q->whereKeyNot($id))
                ->where('account_id', $data['account_id'])->where('member_id', $data['member_id'])->where('kind', $data['kind'])->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['member_id' => 'Diese Zuordnung existiert bereits.']);
            }
            if ($data['state'] === 'approved') {
                $member = app(MemberAccessService::class)->activeMembers($tenant)->find($data['member_id']);
                $today = now('Europe/Zurich')->toDateString();
                $minor = $member?->birth_date && $member->birth_date->toDateString() <= $today
                    && $member->birth_date->copy()->addYearsNoOverflow(18)->toDateString() > $today;
                if (! $member || ($data['kind'] === 'parent' && ! $minor)) {
                    throw ValidationException::withMessages(['member_id' => 'Aktive Mitgliedschaft erforderlich; Elternzugriff nur für Minderjährige mit Geburtsdatum.']);
                }
                if ($data['kind'] === 'self' && AccountMemberLink::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('account_id', $data['account_id'])
                    ->where('kind', 'self')->where('state', 'approved')->when($id, fn ($q) => $q->whereKeyNot($id))->exists()) {
                    throw ValidationException::withMessages(['member_id' => 'Der Benutzer hat bereits eine eigene Mitgliedszuordnung.']);
                }
            }
            $before = $record->exists ? $record->only(['account_id', 'member_id', 'kind', 'state', 'revision']) : null;
            $record->forceFill(array_intersect_key($data, array_flip(['account_id', 'member_id', 'kind', 'state'])) + [
                'tenant_id' => $tenant->id, 'source' => $record->source ?? 'backoffice_review', 'revision' => $id ? $record->revision + 1 : 1,
                'reviewed_by' => $actor->id, 'reviewed_at' => now(),
            ])->save();
            DB::connection('clubunio')->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id,
                'object_type' => 'account_member_links', 'object_id' => $record->id, 'before_value' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null,
                'after_value' => json_encode($record->only(['account_id', 'member_id', 'kind', 'state', 'revision']) + ['reason' => $data['reason']], JSON_THROW_ON_ERROR)]);

            return $record;
        });
    }
}
