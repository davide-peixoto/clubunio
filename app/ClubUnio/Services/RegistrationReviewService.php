<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Registration;
use App\ClubUnio\Models\RegistrationStatus;
use App\ClubUnio\Models\TeamSeason;
use App\ClubUnio\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RegistrationReviewService
{
    public const FIELDS = ['status_id', 'team_season_id', 'technical_lead_member_id'];

    public function visible(Account $actor, Tenant $tenant): Builder
    {
        $q = Registration::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true);
        if ($actor->canAccessTenant($tenant)) {
            return $q;
        }
        $access = app(MemberAccessService::class);
        if ($access->membership($actor, $tenant)?->role !== 'technical_lead' || ! $access->canEnter($actor, $tenant)) {
            return $q->whereRaw('1=0');
        }

        return $q->where(fn ($q) => $q->whereIn('technical_lead_member_id', $access->represented($actor, $tenant, true)->select('clubora.members.id'))
            ->orWhereIn('team_season_id', $access->responsibleTeams($actor, $tenant)));
    }

    public function canReview(Account $actor, Tenant $tenant, Registration $registration): bool
    {
        return (int) $registration->tenant_id === (int) $tenant->id && $this->visible($actor, $tenant)->whereKey($registration->id)->exists();
    }

    public function assignableTeams(Account $actor, Tenant $tenant): Builder
    {
        $q = TeamSeason::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true)->whereHas('team', fn ($q) => $q->where('active', true));
        if (! $actor->canAccessTenant($tenant)) {
            $today = now('Europe/Zurich')->toDateString();
            $q->whereIn('id', app(MemberAccessService::class)->responsibleTeams($actor, $tenant))
                ->whereHas('season', fn ($q) => $q->where('active', true)->where('starts_on', '<=', $today)->where('ends_on', '>=', $today));
        }

        return $q;
    }

    public function leaders(Tenant $tenant): Builder
    {
        return app(MemberAccessService::class)->activeMembers($tenant)->whereIn('id',
            DB::connection('clubunio')->table('clubora.account_member_links as l')
                ->join('clubora.tenant_accounts as ta', fn ($j) => $j->on('ta.tenant_id', '=', 'l.tenant_id')->on('ta.account_id', '=', 'l.account_id'))
                ->join('clubora.accounts as a', 'a.id', '=', 'l.account_id')
                ->where('l.tenant_id', $tenant->id)->where('l.kind', 'self')->where('l.state', 'approved')
                ->where('ta.role', 'technical_lead')->where('ta.portal_enabled', true)->where('a.active', true)->select('l.member_id'));
    }

    public function review(Account $actor, Tenant $tenant, int $id, array $input): Registration
    {
        $unknown = array_diff(array_keys($input), [...self::FIELDS, 'revision', 'reason', 'member_id', 'member_version', 'member_type_id', 'document_type_id']);
        if ($unknown) {
            throw ValidationException::withMessages(['status_id' => 'Diese Felder dürfen hier nicht verändert werden.']);
        }
        $data = Validator::make($input, [
            'revision' => 'required|integer|min:1', 'reason' => 'required|string|min:10|max:500',
            'status_id' => 'sometimes|required|integer', 'team_season_id' => 'sometimes|nullable|integer',
            'technical_lead_member_id' => 'sometimes|nullable|integer',
            'member_id' => 'sometimes|nullable|integer', 'member_version' => 'sometimes|nullable|string|size:64',
            'member_type_id' => 'sometimes|nullable|integer', 'document_type_id' => 'sometimes|nullable|integer',
        ])->validate();

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $data) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $actor = $actor->fresh();
            abort_unless($actor?->active, 403);
            $office = $actor->canAccessTenant($tenant);
            $r = Registration::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true)->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($this->canReview($actor, $tenant, $r), 403);
            if ((int) $r->revision !== (int) $data['revision']) {
                throw ValidationException::withMessages(['revision' => 'Die Anmeldung wurde inzwischen geändert. Bitte erneut öffnen.']);
            }
            $before = $r->only(self::FIELDS);
            $values = array_replace($before, array_intersect_key($data, array_flip(self::FIELDS)));
            $status = RegistrationStatus::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true)->find($values['status_id']);
            $oldStatus = RegistrationStatus::withoutGlobalScopes()->where('tenant_id', $tenant->id)->find($r->status_id);
            if (! $status || ! in_array($status->workflow_key, ['review', 'trial', 'accepted', 'rejected', 'pool'], true)
                || $oldStatus?->workflow_key === 'accepted') {
                throw ValidationException::withMessages(['status_id' => 'Dieser Statuswechsel ist nicht zulässig. Akzeptierte Anmeldungen bleiben abgeschlossen.']);
            }
            $teamChanged = (string) $values['team_season_id'] !== (string) $r->team_season_id;
            $statusChanged = (string) $values['status_id'] !== (string) $r->status_id;
            if ($values['team_season_id'] && ($teamChanged || ($statusChanged && in_array($status->workflow_key, ['trial', 'accepted'], true)))) {
                $team = TeamSeason::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true)
                    ->whereHas('team', fn ($q) => $q->where('active', true))->find($values['team_season_id']);
                if (! $team) {
                    throw ValidationException::withMessages(['team_season_id' => 'Bitte eine aktive Mannschaft dieses Vereins auswählen.']);
                }
                if (! $office && ! $this->assignableTeams($actor, $tenant)->whereKey($team->id)->exists()) {
                    abort(403);
                }
            }
            if ($status->workflow_key === 'trial' && ! $values['team_season_id']) {
                throw ValidationException::withMessages(['team_season_id' => 'Für ein Probetraining ist eine Mannschaft erforderlich.']);
            }
            $leaderChanged = (string) $values['technical_lead_member_id'] !== (string) $r->technical_lead_member_id;
            abort_if(! $office && $leaderChanged, 403);
            if ($leaderChanged && ($status->workflow_key !== 'review'
                || ($values['technical_lead_member_id'] && ! $this->leaders($tenant)->whereKey($values['technical_lead_member_id'])->exists()))) {
                throw ValidationException::withMessages(['technical_lead_member_id' => 'TL-Zuweisungen sind nur in Prüfung an einen aktiven TL möglich.']);
            }
            $r->forceFill($values);
            if (! $r->isDirty(self::FIELDS)) {
                return $r;
            }
            if ($statusChanged && $status->workflow_key === 'accepted') {
                app(RegistrationAcceptanceService::class)->accept($actor, $tenant, $r, $data);
            }
            if ($statusChanged && $status->workflow_key === 'rejected') {
                $r->rejected_at = now();
            } elseif ($statusChanged) {
                $r->rejected_at = null;
            }
            $r->forceFill(['revision' => $r->revision + 1, 'reviewed_by' => $actor->id, 'reviewed_at' => now()])->save();
            $db = DB::connection('clubunio');
            $db->table('clubora.object_changes')->insert([
                'tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'object_type' => 'registrations', 'object_id' => $r->id,
                'before_value' => json_encode($before, JSON_THROW_ON_ERROR),
                'after_value' => json_encode($r->only([...self::FIELDS, 'revision', 'member_id', 'accepted_at']) + ['reason' => trim($data['reason'])], JSON_THROW_ON_ERROR),
            ]);
            $events = [];
            if ($statusChanged && in_array($status->workflow_key, ['trial', 'accepted', 'rejected', 'pool'], true)) {
                $events[] = 'registration.'.$status->workflow_key;
                if ($r->team_season_id && $status->workflow_key !== 'pool') {
                    $events[] = 'registration.staff.'.$status->workflow_key;
                }
            }
            if ($status->workflow_key === 'review' && $r->technical_lead_member_id && ($leaderChanged || $statusChanged)) {
                $events[] = 'registration.review';
            }
            foreach ($events as $event) {
                app(RegistrationMessageService::class)->record($tenant, $r, $event);
            }

            return $r;
        });
    }
}
