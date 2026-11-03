<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\{MemberAccessService, TeamPlanningService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/** Event-specific optimistic snapshots; never exposes private member contact data. */
final class MobilePlanningInvitationsData
{
    public function canInvite(Account $actor, Tenant $tenant, object $event, ?MobilePlanningCapabilities $presentation = null): bool
    {
        $p = app(TeamPlanningService::class);
        $can = fn ($function) => $presentation ? $presentation->allows($actor, $tenant, (int) $event->team_season_id, $function)
            : $p->teams($actor, $tenant, true, $function)->where('id', $event->team_season_id)->exists();
        return $event->status === 'AKTIV' && $can('send') && $can('update');
    }

    private function context(Tenant $tenant, object $event, array $members): array
    {
        $p = app(TeamPlanningService::class);
        return ['event' => (array) $event,
            'roster' => $p->roster($tenant, $event)->pluck('id')->all(),
            'invited' => $p->table($tenant, 'team_invitations')->where('event_id', $event->id)->pluck('member_id')->all(),
            'parts' => $p->table($tenant, 'team_participations')->where('event_id', $event->id)->whereIn('member_id', $members)->get()->keyBy('member_id'),
            'members' => app(MemberAccessService::class)->activeMembers($tenant)->whereKey($members)
                ->get(['id', 'updated_at', 'status_id', 'exit_date', 'archived_at'])->keyBy('id'),
            // Includes remove/reinvite transitions, even when the tuple itself has no revision.
            'audit' => $p->table($tenant, 'object_changes')->where('object_type', 'team_invitations')->where('object_id', $event->id)->max('id')];
    }

    private function fingerprint(array $context, int $member): string
    {
        return hash('sha256', json_encode([$context['event'], $context['members']->get($member)?->getRawOriginal(),
            in_array($member, $context['roster'], true), in_array($member, $context['invited'], true),
            $context['parts']->get($member), $context['audit']], JSON_THROW_ON_ERROR));
    }

    private function snapshot(Account $actor, Tenant $tenant, object $event, int $member, array $context): string
    {
        return Crypt::encryptString(json_encode(['tenant' => (int) $tenant->id, 'actor' => (int) $actor->id, 'event' => (int) $event->id,
            'member' => $member, 'revision' => (int) $event->revision, 'expires' => now()->addMinutes(15)->timestamp,
            'fingerprint' => $this->fingerprint($context, $member)], JSON_THROW_ON_ERROR));
    }

    public function verify(Account $actor, Tenant $tenant, object $event, int $member, string $snapshot, int $revision): void
    {
        try { $claim = json_decode(Crypt::decryptString($snapshot), true, 32, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { abort(409, 'Auswahl bitte neu oeffnen.'); }
        abort_unless(is_array($claim) && ($claim['tenant'] ?? null) === (int) $tenant->id && ($claim['actor'] ?? null) === (int) $actor->id
            && ($claim['event'] ?? null) === (int) $event->id && ($claim['member'] ?? null) === $member
            && is_int($claim['expires'] ?? null) && $claim['expires'] >= now()->timestamp
            && ($claim['revision'] ?? null) === $revision && $revision === (int) $event->revision
            && is_string($claim['fingerprint'] ?? null), 409, 'Auswahl wurde inzwischen geaendert.');
        $context = $this->context($tenant, $event, [$member]);
        abort_unless(hash_equals($claim['fingerprint'], $this->fingerprint($context, $member)), 409, 'Auswahl wurde inzwischen geaendert.');
    }

    public function search(Request $request, Account $actor, Tenant $tenant, object $event): array
    {
        abort_unless($this->canInvite($actor, $tenant, $event), 403);
        $d = $request->validate(['q' => 'nullable|string|max:100', 'start' => 'nullable|integer|min:0|max:100000']);
        $q = trim($d['q'] ?? '');
        if (mb_strlen($q) < 2) { return ['success' => true, 'records' => [], 'totalRecordCount' => 0]; }
        $p = app(TeamPlanningService::class);
        $query = app(MemberAccessService::class)->activeMembers($tenant)->whereNotIn('id', $p->roster($tenant, $event)->select('clubora.members.id'))
            ->where(fn ($b) => $b->where('first_name', 'ilike', '%'.$q.'%')->orWhere('last_name', 'ilike', '%'.$q.'%'));
        $total = (clone $query)->count();
        $rows = $query->orderBy('last_name')->orderBy('first_name')->orderBy('id')->offset($d['start'] ?? 0)->limit(20)
            ->get(['id', 'first_name', 'last_name']);
        $context = $this->context($tenant, $event, $rows->pluck('id')->all());
        return ['success' => true, 'totalRecordCount' => $total, 'records' => $rows->map(fn ($member) => [
            'lf' => (int) $member->id, 'df' => $member->first_name, 'df2' => $member->last_name,
            'EventRevision' => (int) $event->revision, 'InvitationSnapshot' => $this->snapshot($actor, $tenant, $event, (int) $member->id, $context)])->all()];
    }

    public function metadata(Account $actor, Tenant $tenant, object $event, ?MobilePlanningCapabilities $presentation = null): array
    {
        $p = app(TeamPlanningService::class);
        $ids = $p->table($tenant, 'team_invitations')->where('event_id', $event->id)->pluck('member_id')->all();
        $canRemove = $event->status === 'AKTIV' && ($presentation ? $presentation->allows($actor, $tenant, (int) $event->team_season_id, 'cancel')
            : $p->teams($actor, $tenant, true, 'cancel')->where('id', $event->team_season_id)->exists());
        $context = $canRemove && $ids ? $this->context($tenant, $event, $ids) : null;
        $members = [];
        foreach ($ids as $id) {
            $members[$id] = ['IsInvited' => true, 'CanRemoveInvitation' => $canRemove,
                'InvitationSnapshot' => $context ? $this->snapshot($actor, $tenant, $event, (int) $id, $context) : null];
        }
        return ['CanInviteMembers' => $this->canInvite($actor, $tenant, $event, $presentation), 'members' => $members];
    }
}
