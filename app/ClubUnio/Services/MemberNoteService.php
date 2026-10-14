<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\MemberNote;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MemberNoteService
{
    public function save(Account $actor, Tenant $tenant, int $memberId, ?int $id, array $data): MemberNote
    {
        abort_unless($actor->canAccessTenant($tenant), 403);

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $memberId, $id, $data) {
            Member::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereNull('archived_at')->whereKey($memberId)->lockForUpdate()->firstOrFail();
            $note = $id ? MemberNote::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('member_id', $memberId)->whereKey($id)->lockForUpdate()->firstOrFail() : new MemberNote;
            if ($id && (int) ($data['revision'] ?? 0) !== (int) $note->revision) {
                throw ValidationException::withMessages(['body' => 'Die Bemerkung wurde inzwischen geändert. Bitte neu öffnen.']);
            }
            $values = ['body' => $data['body'] ?? '', 'active' => $data['active'] ?? ($id ? $note->active : true)];
            Validator::make($values, ['body' => ['required', 'string', 'max:10000'], 'active' => ['required', 'boolean']])->validate();
            $values['body'] = trim($values['body']);
            Validator::make($values, ['body' => ['required']])->validate();
            $before = $id ? $note->only(['body', 'active', 'revision']) : null;
            $note->forceFill($values);
            if ($id && ! $note->isDirty()) {
                return $note;
            }
            $note->forceFill(['tenant_id' => $tenant->id, 'member_id' => $memberId, 'revision' => $id ? $note->revision + 1 : 1])->save();
            DB::connection('clubunio')->table('clubora.object_changes')->insert([
                'tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'object_type' => 'member_notes', 'object_id' => $note->id,
                'before_value' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
                'after_value' => json_encode($note->only(['body', 'active', 'revision']), JSON_THROW_ON_ERROR),
            ]);

            return $note;
        });
    }
}
