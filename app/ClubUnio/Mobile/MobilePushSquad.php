<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\{MemberAccessService, TeamPlanningService};
use Illuminate\Support\Facades\DB;

/** Personal publication action, never a general message/recipient API. */
final class MobilePushSquad
{
    public function snapshot(Account $actor, Tenant $tenant, int $id): array
    {
        $planning = app(TeamPlanningService::class);
        $event = $planning->event($actor, $tenant, $id, true, 'update');
        $planning->event($actor, $tenant, $id, true, 'export');
        abort_unless($event->status === 'AKTIV', 409);
        $roster = $planning->roster($tenant, $event)->pluck('id')->map(fn ($v) => (int) $v)->sort()->values()->all();
        $staff = $planning->table($tenant, 'team_staff')->where('team_season_id', $event->team_season_id)->where('active', true)->orderBy('member_id')->pluck('member_id')->all();
        $parts = $planning->table($tenant, 'team_participations')->where('event_id', $id)->orderBy('member_id')->get(['member_id', 'status', 'revision']);
        $byMember = $parts->keyBy('member_id');
        $players = array_values(array_filter($roster, fn ($member) => ! in_array($member, $staff)
            && ($byMember->get($member)->status ?? ($event->mode === 'ABMELDEN' ? 'DABEI' : 'OFFEN')) === 'DABEI'));
        $ids = $planning->table($tenant, 'account_member_links')->whereIn('member_id', $players)->where('state', 'approved')
            ->whereIn('kind', ['self', 'parent'])->distinct()->pluck('account_id');
        $recipients = [];
        foreach (Account::whereIn('id', $ids)->where('active', true)->where('api_only', false)->orderBy('id')->get() as $candidate) {
            if (! app(MemberAccessService::class)->represented($candidate, $tenant)->whereIn('clubunio.members.id', $players)->exists()
                || ! app(MobilePushOutbox::class)->targetAllowed($candidate, $tenant, 'planning', $id)) continue;
            $recipients[] = (int) $candidate->id;
        }
        $revision = hash('sha256', json_encode([(int) $event->revision, $event->mode, $roster, $staff,
            $parts->map(fn ($p) => [(int) $p->member_id, $p->status, (int) $p->revision])->all()], JSON_THROW_ON_ERROR));
        return ['revision' => $revision, 'recipients' => $recipients,
            'recipient_fingerprint' => hash('sha256', json_encode($recipients, JSON_THROW_ON_ERROR))];
    }

    public function publish(Account $actor, Tenant $tenant, int $event, array $input): array
    {
        abort_unless(config('clubunio-mobile-push.enabled', false) && config('clubunio-mobile-push.delivery_enabled', false), 503);
        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $event, $input) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $snapshot = $this->snapshot($actor, $tenant, $event);
            $fingerprint = hash('sha256', json_encode([$event, $input['revision'], $input['recipient_fingerprint']], JSON_THROW_ON_ERROR));
            $commands = DB::connection('clubunio')->table('clubunio.mobile_push_commands');
            $prior = (clone $commands)->where('tenant_id', $tenant->id)->where('account_id', $actor->id)->where('command_key', $input['command_key'])->first();
            if ($prior) {
                abort_unless($prior->operation === 'publish_squad' && hash_equals($prior->fingerprint, $fingerprint), 409);
                return ['success' => true, 'notificationCount' => (int) $prior->queued_count, 'deliveryState' => 'queued'];
            }
            abort_unless(hash_equals($snapshot['revision'], $input['revision'])
                && hash_equals($snapshot['recipient_fingerprint'], $input['recipient_fingerprint']), 409, 'Aufgebot oder Empfaenger geaendert. Bitte erneut pruefen.');
            $count = 0;
            foreach (Account::whereIn('id', $snapshot['recipients'])->get() as $recipient) {
                $count += app(MobilePushOutbox::class)->enqueue($recipient, $tenant, 'planning', $event, 'squad:'.$actor->id.':'.$input['command_key']);
            }
            $commands->insert(['tenant_id' => $tenant->id, 'account_id' => $actor->id, 'command_key' => $input['command_key'],
                'operation' => 'publish_squad', 'fingerprint' => $fingerprint, 'event_id' => $event, 'event_revision' => $snapshot['revision'],
                'recipient_fingerprint' => $snapshot['recipient_fingerprint'], 'queued_count' => $count]);
            return ['success' => true, 'notificationCount' => $count, 'deliveryState' => 'queued'];
        });
    }
}
