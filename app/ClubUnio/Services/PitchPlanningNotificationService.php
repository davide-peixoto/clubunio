<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Mobile\MobilePushOutbox;
use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Transactional fanout only. Provider delivery belongs to an explicit outbox worker. */
class PitchPlanningNotificationService
{
    private const EVENTS = [
        'requested' => 'pitch.friendly.requested',
        'approved' => 'pitch.friendly.approved',
        'rejected' => 'pitch.friendly.rejected',
        'moved' => 'pitch.booking.moved',
        'sfv_moved' => 'pitch.booking.moved',
        'created' => 'pitch.booking.created',
        'cancelled' => 'pitch.booking.cancelled',
    ];

    public function record(Tenant $tenant, array $event): void
    {
        $db = DB::connection('clubunio');
        if ($db->transactionLevel() === 0) {
            throw new \LogicException('Pitch notifications require the source save transaction');
        }
        $event = $this->normalize($event);
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $fingerprint = hash('sha256', $payload);
        $db->transaction(function () use ($db, $tenant, $event, $payload, $fingerprint) {
            $db->table('clubunio.pitch_planning_events')->insertOrIgnore([
                'tenant_id' => $tenant->id, 'event_key' => $event['event_key'], 'fingerprint' => $fingerprint,
                'action' => $event['action'], 'actor_id' => $event['actor_id'],
                'requester_account_id' => $event['requester_account_id'],
                'team_season_ids' => json_encode($event['team_season_ids'], JSON_THROW_ON_ERROR),
                'source_key' => $event['after']['key'], 'source_date' => $event['after']['date'],
                'payload' => $payload, 'delivery_state' => 'held', 'created_at' => now()->toIso8601String(),
            ]);
            $row = $db->table('clubunio.pitch_planning_events')->where('tenant_id', $tenant->id)
                ->where('event_key', $event['event_key'])->lockForUpdate()->firstOrFail();
            if (! hash_equals($row->fingerprint, $fingerprint)) {
                throw new \LogicException('Pitch notification event identity was reused with different data');
            }
            if ($row->notifications_recorded_at !== null) {
                return;
            }
            $values = [
                'verein' => $tenant->name,
                'mannschaft' => $db->table('clubunio.team_seasons')->where('tenant_id', $tenant->id)
                    ->whereIn('id', $event['team_season_ids'])->orderBy('id')->pluck('label')->implode(', '),
                'datum' => $event['after']['date'].' '.$event['after']['starts_at'],
            ];
            foreach (['previous' => $event['before'], 'new' => $event['after']] as $prefix => $entry) {
                $field = $db->table('clubunio.training_options')->where('tenant_id', $tenant->id)
                    ->where('category', 'field')->where('id', $entry['field_id'] ?? null)->first();
                $values[$prefix.'_field'] = $field ? new MessageLookupValue('training_options', (array) $field) : '';
                $values[$prefix.'_date'] = $entry['date'] ?? '';
                $values[$prefix.'_time'] = isset($entry['starts_at']) ? $entry['starts_at'].'-'.($entry['ends_at'] ?? '') : '';
            }
            $templates = app(MessageTemplateService::class);
            $deliveries = [];
            $emailAddresses = [];
            foreach ($this->recipients($tenant, $event)->orderBy('a.id')->get(['a.id', 'a.username']) as $recipient) {
                $account = Account::findOrFail($recipient->id);
                $message = $templates->renderForAccount($tenant, self::EVENTS[$event['action']], 'push', $values, (int) $account->id);
                if ($message) {
                    $notification = $db->table('clubunio.notifications')->insertGetId([
                        'tenant_id' => $tenant->id, 'account_id' => $account->id,
                        'object_type' => 'pitch_planning', 'object_id' => $row->id,
                        'title' => $message['title'], 'body' => $message['body'], 'created_at' => $row->created_at,
                    ]);
                    $key = 'notification:'.$notification;
                    $db->table('clubunio.mobile_inbox')->insertOrIgnore([
                        'tenant_id' => $tenant->id, 'account_id' => $account->id, 'kind' => 'pitch_planning',
                        'object_id' => $row->id, 'event_key' => $key, 'created_at' => $row->created_at,
                    ]);
                    app(MobilePushOutbox::class)->enqueue($account, $tenant, 'pitch_planning', (int) $row->id, $key, $row->created_at);
                }
                // Email addresses are recipient snapshots, never arbitrary event input.
                if ($event['action'] === 'requested') {
                    $email = mb_strtolower(trim((string) $recipient->username));
                    if (! isset($emailAddresses[$email]) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $mail = $templates->renderForEmail($tenant, self::EVENTS['requested'], $values, $email);
                        if ($mail) {
                            $deliveries[] = $mail + ['intended_to' => $email, 'intended_cc' => []];
                            $emailAddresses[$email] = true;
                        }
                    }
                }
            }
            $db->table('clubunio.pitch_planning_events')->where('id', $row->id)->where('tenant_id', $tenant->id)->update([
                'notifications_recorded_at' => now()->toIso8601String(),
                'payload_encrypted' => $deliveries ? Crypt::encryptString(json_encode(['deliveries' => $deliveries], JSON_THROW_ON_ERROR)) : null,
                'delivery_state' => $deliveries ? 'ready' : 'held',
            ]);
        });
    }

    public function dispatch(int $tenantId, int $limit = 20, ?int $eventId = null): array
    {
        if ($tenantId < 1 || ($eventId !== null && $eventId < 1)) {
            throw new \InvalidArgumentException('Positive pitch mail scope required');
        }

        return app(TestOutboxDispatcher::class)->dispatch('pitch_planning_events', $limit, $tenantId, $eventId);
    }

    /**
     * Both historical ownership and current recipient eligibility are required.
     * Profile staff may own inbox rows, but existing MobileAccess still blocks their
     * mobile delivery/entry. This integration deliberately does not widen that policy.
     */
    public function target(Account $actor, Tenant $tenant, int $id): array
    {
        $db = DB::connection('clubunio');
        $row = $db->table('clubunio.pitch_planning_events')->where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();
        abort_unless($db->table('clubunio.notifications')->where('tenant_id', $tenant->id)->where('account_id', $actor->id)
            ->where('object_type', 'pitch_planning')->where('object_id', $id)->exists(), 404);
        $event = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
        abort_unless($this->recipients($tenant, $event)->where('a.id', $actor->id)->exists(), 403);

        return ['date' => $row->source_date, 'key' => $row->source_key];
    }

    private function recipients(Tenant $tenant, array $event)
    {
        $db = DB::connection('clubunio');
        $accounts = $db->table('clubunio.accounts as a')->join('clubunio.tenant_accounts as ta', 'ta.account_id', '=', 'a.id')
            ->where('ta.tenant_id', $tenant->id)->where('a.active', true)->where('a.api_only', false);
        if ($event['action'] === 'requested') {
            return $accounts->whereIn('ta.role', ['admin', 'office']);
        }
        if (in_array($event['action'], ['approved', 'rejected'], true)) {
            return $accounts->where('a.id', $event['requester_account_id'] ?? -1);
        }
        if ($event['action'] === 'created' && $event['team_season_ids'] === []) {
            return $accounts->whereRaw('1=0');
        }
        $members = app(MemberAccessService::class)->activeMembers($tenant)->whereIn('clubunio.members.id',
            $db->table('clubunio.team_staff')->where('tenant_id', $tenant->id)->where('active', true)
                ->whereIn('team_season_id', $event['team_season_ids'])->select('member_id'));

        $staffAccounts = $db->table('clubunio.account_member_links')->where('tenant_id', $tenant->id)
            ->where('kind', 'self')->where('state', 'approved')
            ->whereIn('member_id', $members->select('clubunio.members.id'))->select('account_id');

        return $accounts->where(function ($q) use ($event, $staffAccounts) {
            $q->where(fn ($staff) => $staff
                ->where(fn ($access) => $access->where('ta.portal_enabled', true)->orWhereIn('ta.role', ['admin', 'office']))
                ->whereIn('a.id', $staffAccounts));
            if (in_array($event['action'], ['created', 'cancelled'], true)) {
                $q->orWhere('a.id', $event['requester_account_id'] ?? -1);
            }
        });
    }

    private function normalize(array $event): array
    {
        if (! isset(self::EVENTS[$event['action'] ?? '']) || ! is_string($event['event_key'] ?? null)
            || $event['event_key'] === '' || strlen($event['event_key']) > 240) {
            throw new \InvalidArgumentException('Invalid pitch notification event');
        }
        $after = $event['after'] ?? [];
        if (! is_string($after['key'] ?? null) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $after['date'] ?? '')
            || ! is_string($after['starts_at'] ?? null) || ! isset($after['revision'])) {
            throw new \InvalidArgumentException('Pitch notification requires a saved entry');
        }
        $teams = array_values(array_unique(array_map('intval', $event['team_season_ids'] ?? [])));
        if (array_filter($teams, fn ($id) => $id < 1)) {
            throw new \InvalidArgumentException('Invalid affected pitch team identity');
        }
        sort($teams);
        // Exclude translated display strings and computed UI capabilities from retry identity.
        $snapshot = function (?array $entry): ?array {
            if ($entry === null) {
                return null;
            }
            $fields = ['key', 'source', 'source_id', 'original_date', 'date', 'starts_at', 'ends_at',
                'duration_minutes', 'field_id', 'area_id', 'status', 'revision'];
            $result = [];
            foreach ($fields as $field) {
                $result[$field] = $entry[$field] ?? null;
            }

            return $result;
        };

        return [
            'event_key' => $event['event_key'], 'action' => $event['action'], 'actor_id' => $event['actor_id'] ?? null,
            'requester_account_id' => $event['requester_account_id'] ?? null, 'team_season_ids' => $teams,
            'before' => $snapshot($event['before'] ?? null), 'after' => $snapshot($after),
        ];
    }
}
