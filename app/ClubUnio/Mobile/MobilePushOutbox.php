<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\TeamPlanningService;
use Illuminate\Support\Facades\{Crypt, DB};
use Illuminate\Support\Str;

final class MobilePushOutbox
{
    /** Called inside the source write transaction. Inserts identifiers, never performs HTTP. */
    public function enqueue(Account $actor, Tenant $tenant, string $kind, int $object, string $eventKey, ?string $sourceCreatedAt = null): int
    {
        if (! config('clubunio-mobile-push.enabled', false)) return 0;
        if (strlen($eventKey) > 160 || ! preg_match('/^[a-z0-9:._-]+$/D', $eventKey)) throw new \InvalidArgumentException('Invalid push event key');
        $db = DB::connection('clubunio'); $count = 0;
        // No delivery candidate means no reason to enumerate fresh domain rights.
        $bindings = $this->bindings($tenant, $sourceCreatedAt)->where('b.account_id', $actor->id)
            ->select(['b.id', 'b.device_id', 'b.revision as binding_revision', 'd.revision as device_revision'])->get();
        if ($bindings->isEmpty() || ! $this->targetAllowed($actor, $tenant, $kind, $object)) return 0;
        foreach ($bindings as $binding) {
            $count += $db->table('clubora.mobile_push_outbox')->insertOrIgnore(['id' => (string) Str::uuid(), 'binding_id' => $binding->id,
                'account_id' => $actor->id, 'device_id' => $binding->device_id, 'tenant_id' => $tenant->id, 'kind' => $kind,
                'object_id' => $object, 'event_key' => $eventKey, 'device_revision' => $binding->device_revision,
                'binding_revision' => $binding->binding_revision, 'created_at' => now()->toIso8601String()]);
        }
        return $count;
    }

    /** Device opt-in is only a cheap candidate filter, never object authorization. */
    private function bindings(Tenant $tenant, ?string $sourceCreatedAt = null)
    {
        return DB::connection('clubunio')->table('clubora.mobile_push_bindings as b')
            ->join('clubora.mobile_push_devices as d', 'd.id', '=', 'b.device_id')
            ->join('clubora.mobile_sessions as s', fn ($j) => $j->on('s.id', '=', 'b.session_id')->on('s.account_id', '=', 'b.account_id'))
            ->whereNull('b.revoked_at')->whereNull('d.revoked_at')->whereNull('s.revoked_at')
            ->where('s.expires_at', '>', now()->toIso8601String())
            ->when($sourceCreatedAt, fn ($q) => $q->where('b.created_at', '<=', $sourceCreatedAt))
            ->where(fn ($q) => $q->whereNull('d.fixed_tenant_id')->orWhere('d.fixed_tenant_id', $tenant->id));
    }

    public function targetAllowed(Account $actor, Tenant $tenant, string $kind, int $object, bool $delivery = true): bool
    {
        $request = request();
        $previousScope = $request->attributes->get('mobile.season_scope');
        $request->attributes->set('mobile.season_scope', true);
        $lockedProjection=app(MobileReadContext::class)->lockedFor($actor,$tenant);
        foreach (array_keys($request->attributes->all()) as $key) {
            if (!$lockedProjection && str_starts_with($key, 'mobile.current_season.'.$tenant->id.'.')) $request->attributes->remove($key);
        }
        try {
            app(MobileAccess::class)->enter($actor, $tenant);
            if (in_array($kind, ['chat', 'announcement'], true)) {
                $chat = app(ChatService::class)->table($tenant, 'chat_conversations')->where('id', $object)->first();
                if (! $chat || ! app(ChatService::class)->allowed($actor, $tenant, $chat)) return false;
                $member = app(ChatService::class)->table($tenant, 'chat_memberships')->where('conversation_id', $object)->where('account_id', $actor->id)->first();
                if (! $delivery) return true;
                return (! $member || (! $member->muted && $member->left_at === null));
            }
            if ($kind === 'invoice') { app(MobileAccess::class)->invoice($actor, $tenant, $object); return true; }
            if ($kind === 'planning') {
                app(TeamPlanningService::class)->event($actor, $tenant, $object);
                return true;
            }
            if (in_array($kind, MobilePushTargets::KINDS, true)) {
                app(MobilePushTargets::class)->authorize($actor, $tenant, $kind, $object); return true;
            }
            return false;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface|\Illuminate\Database\Eloquent\ModelNotFoundException|\Illuminate\Database\RecordNotFoundException) {
            return false;
        } finally {
            if ($previousScope === null) $request->attributes->remove('mobile.season_scope');
            else $request->attributes->set('mobile.season_scope', $previousScope);
        }
    }

    /** Explicit worker operation, never called from message/account requests. */
    public function deliverOne(?string $onlyId = null): bool
    {
        if ($onlyId !== null && ! Str::isUuid($onlyId)) throw new \InvalidArgumentException('A valid push outbox UUID is required');
        if (! config('clubunio-mobile-push.enabled', false) || ! config('clubunio-mobile-push.delivery_enabled', false)) return false;
        $db = DB::connection('clubunio');
        if ($db->transactionLevel() > 0 && ! app()->environment('testing')) throw new \LogicException('Push worker cannot run inside a business transaction');
        // A crashed/uncertain sender is not automatically retried: provider may already have accepted it.
        $db->table('clubora.mobile_push_outbox')->where('state', 'sending')->where('lease_until', '<', now()->toIso8601String())
            ->when($onlyId !== null, fn ($query) => $query->where('id', $onlyId))
            ->update(['state' => 'attention', 'outcome_code' => 'delivery_uncertain', 'finished_at' => now()->toIso8601String()]);
        $claimed = $db->transaction(function () use ($db, $onlyId) {
            $row = $db->table('clubora.mobile_push_outbox')->where('state', 'pending')
                ->when($onlyId !== null, fn ($query) => $query->where('id', $onlyId))
                ->orderBy('created_at')->lock('FOR UPDATE SKIP LOCKED')->first();
            if (! $row) return null;
            $db->table('clubora.mobile_push_outbox')->where('id', $row->id)->update(['state' => 'sending', 'lease_until' => now()->addMinute()->toIso8601String()]);
            return $row;
        });
        if (! $claimed) return false;
        return $db->transaction(function () use ($db, $claimed) {
            $row = $db->table('clubora.mobile_push_outbox')->where('id', $claimed->id)->where('state', 'sending')->lockForUpdate()->first();
            if (! $row) return true;
            // Locks serialize logout/token rotation/revocation against the final authorization and provider attempt.
            $device = $db->table('clubora.mobile_push_devices')->where('id', $row->device_id)->lockForUpdate()->first();
            $binding = $db->table('clubora.mobile_push_bindings')->where('id', $row->binding_id)->sharedLock()->first();
            $session = $binding ? $db->table('clubora.mobile_sessions')->where('id', $binding->session_id)->sharedLock()->first() : null;
            $actor = Account::whereKey($row->account_id)->where('active', true)->where('api_only', false)->sharedLock()->first();
            $tenant = Tenant::whereKey($row->tenant_id)->sharedLock()->first();
            $finish = function (array $result) use ($db, $row) {
                $db->table('clubora.mobile_push_outbox')->where('id', $row->id)->update(['state' => $result['state'],
                    'outcome_code' => $result['code'], 'provider_reference' => $result['reference'] ?? null, 'finished_at' => now()->toIso8601String()]);
            };
            $chatSource = null;
            if (! $device || ! $binding || ! $session || ! $actor || ! $tenant || $device->revoked_at || $binding->revoked_at || $session->revoked_at
                || (int) $session->account_id !== (int) $actor->id || \Carbon\CarbonImmutable::parse($session->expires_at)->isPast()
                || (int) $device->revision !== (int) $row->device_revision || (int) $binding->revision !== (int) $row->binding_revision
                || ($device->fixed_tenant_id !== null && (int) $device->fixed_tenant_id !== (int) $tenant->id)
                || ! $this->targetAllowed($actor, $tenant, $row->kind, (int) $row->object_id)
                || ! $this->publicationCurrent($tenant, $row, $chatSource)) {
                $finish(['state' => 'discarded', 'code' => 'authorization_changed']); return true;
            }
            try {
                $app = app(MobilePushRegistry::class)->app($device->app_id, $device->platform, $device->origin);
                if ($app['fixed_tenant_id'] !== ($device->fixed_tenant_id === null ? null : (int) $device->fixed_tenant_id)) throw new \LogicException('App binding changed');
                $token = Crypt::decryptString($device->token_cipher);
            } catch (\Throwable) { $finish(['state' => 'discarded', 'code' => 'app_binding_changed']); return true; }
            try {
                // Source and recipient rights were just checked under the delivery locks.
                // A newly joined reader must not receive historical message text.
                if ($chatSource && ! $db->table('clubora.chat_memberships')->where('tenant_id', $tenant->id)
                    ->where('conversation_id', $row->object_id)->where('account_id', $actor->id)->whereNull('left_at')
                    ->where('joined_after', '<', $chatSource['message']->id)->exists()) $chatSource = null;
                $locale = app(MobileLanguages::class)->forAccount($actor)['language'];
                $notification = app(MobilePushPresentation::class)->notification($locale ?? config('clubunio-languages.default', 'de'), $chatSource);
                $result = app(MobilePushFcm::class)->send($app, $token,
                    new MobilePushEnvelope((int) $actor->id, (int) $tenant->id, $row->kind, (int) $row->object_id, $row->id), $notification);
            } catch (\Throwable) { $result = ['state' => 'attention', 'code' => 'provider_configuration']; }
            if ($result['code'] === 'token_unregistered') {
                $db->table('clubora.mobile_push_devices')->where('id', $device->id)->update(['revoked_at' => now()->toIso8601String(),
                    'revision' => (int) $device->revision + 1, 'updated_at' => now()->toIso8601String()]);
            }
            $finish($result);
            return true;
        });
    }

    public function chatMessage(Account $sender, Tenant $tenant, object $message, ?string $sourceCreatedAt = null): int
    {
        if (! config('clubunio-mobile-push.enabled', false)) return 0;
        $count = 0;
        foreach ($tenant->accounts()->where('active', true)->where('api_only', false)->where('accounts.id', '<>', $sender->id)
            ->whereExists($this->bindings($tenant, $sourceCreatedAt)->whereColumn('b.account_id', 'clubora.accounts.id')->selectRaw('1'))->get() as $actor) {
            $count += $this->enqueue($actor, $tenant, $message->kind === 'announcement' ? 'announcement' : 'chat',
                (int) $message->conversation_id, 'chat.message:'.$message->id, $sourceCreatedAt);
        }
        return $count;
    }

    private function publicationCurrent(Tenant $tenant, object $row, ?array &$chatSource = null): bool
    {
        if ($row->kind === 'admin_notice') return app(MobileAdminNoticeAccess::class)->publicationCurrent($tenant, (int) $row->object_id);
        if (str_starts_with($row->event_key, 'chat.message:')) {
            if (! preg_match('/^chat\.message:([1-9][0-9]*)$/D', $row->event_key, $matches)) return false;
            $source = app(ChatPushPublications::class)->source($tenant, (int) $matches[1]);
            $current = $source && (int) $source['message']->conversation_id === (int) $row->object_id
                && $row->kind === ($source['message']->kind === 'announcement' ? 'announcement' : 'chat');
            if ($current) $chatSource = $source;
            return $current;
        }
        if ($row->kind !== 'planning' || ! str_starts_with($row->event_key, 'squad:')) return true;
        if (! preg_match('/^squad:([1-9][0-9]*):([0-9a-f-]{36})$/D', $row->event_key, $matches)) return false;
        $command = DB::connection('clubunio')->table('clubora.mobile_push_commands')->where('tenant_id', $tenant->id)
            ->where('account_id', $matches[1])->where('command_key', $matches[2])->where('event_id', $row->object_id)->first();
        $publisher = Account::whereKey($matches[1])->where('active', true)->where('api_only', false)->sharedLock()->first();
        if (! $command || ! $publisher) return false;
        try {
            $snapshot = app(MobilePushSquad::class)->snapshot($publisher, $tenant, (int) $row->object_id);
            return hash_equals($command->event_revision, $snapshot['revision']) && hash_equals($command->recipient_fingerprint, $snapshot['recipient_fingerprint'])
                && in_array((int) $row->account_id, $snapshot['recipients'], true);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface|\Illuminate\Database\Eloquent\ModelNotFoundException) { return false; }
    }
}
