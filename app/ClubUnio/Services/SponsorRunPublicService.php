<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Setting;
use App\ClubUnio\Models\SponsorRunParticipant;
use App\ClubUnio\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class SponsorRunPublicService
{
    public function __construct(private SponsorRunService $core) {}

    private function enabled(?int $tenantId = null): void
    {
        if ($tenantId !== null) {
            abort_unless($this->publicEnabled(Tenant::findOrFail($tenantId)), 404);
        }
    }

    public function publicEnabled(Tenant $tenant): bool
    {
        $setting = Setting::where('tenant_id', $tenant->id)->where('area', 'sponsor_run')->where('key', 'PUBLIC_WEB_ENABLED')->where('enabled', true)->first();

        return $setting && Crypt::decryptString($setting->encrypted_value) === 'true';
    }

    public function configurePublic(Account $actor, Tenant $tenant, bool $enabled): void
    {
        $this->core->db()->transaction(function () use ($actor, $tenant, $enabled) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $this->core->authorize($actor, $tenant, 'send');
            abort_unless(app(MemberAccessService::class)->membership($actor, $tenant)?->role === 'admin', 403);
            $setting = Setting::where('tenant_id', $tenant->id)->where('area', 'sponsor_run')->where('key', 'PUBLIC_WEB_ENABLED')->lockForUpdate()->first() ?? new Setting;
            $setting->forceFill(['tenant_id' => $tenant->id, 'area' => 'sponsor_run', 'key' => 'PUBLIC_WEB_ENABLED', 'label' => 'Sponsorenlauf öffentliche Testzusagen',
                'value_type' => 'boolean', 'enabled' => true, 'is_secret' => false, 'encrypted_value' => Crypt::encryptString($enabled ? 'true' : 'false')])->save();
            $this->core->db()->table('clubora.setting_changes')->insert(['tenant_id' => $tenant->id, 'setting_id' => $setting->id, 'actor_id' => $actor->id]);
        });
    }

    private function hash(string $token): string
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $token), 404, 'Link ist ungültig oder abgelaufen.');

        return hash('sha256', $token);
    }

    public function share(Account $actor, Tenant $tenant, int $participantId, string $action, array $input = []): array
    {
        abort_unless(in_array($action, ['show', 'create', 'rotate', 'revoke'], true), 422);

        return $this->core->db()->transaction(function () use ($actor, $tenant, $participantId, $action, $input) {
            [$e, $p] = $this->core->lockedParticipant($tenant, $participantId);
            $manage = $this->core->allows($actor, $tenant, $action === 'show' ? 'view' : 'send');
            $own = app(MemberAccessService::class)->represented($actor, $tenant)->whereKey($p->member_id)->first();
            abort_unless($manage || $own, 403);
            $table = $this->core->db()->table('clubora.sponsor_run_links')->where('tenant_id', $tenant->id)->where('participant_id', $participantId);
            $old = (clone $table)->lockForUpdate()->first();
            if ($action === 'revoke') {
                if ($old) {
                    $table->update(['revoked_at' => now(), 'revision' => $old->revision + 1]);
                    $this->erasePending($old->id);
                    $this->core->audit($e, $actor, 'link_revoked', null, ['participant_id' => $p->id]);
                }

                return ['active' => false, 'url' => null];
            }
            $this->enabled((int) $tenant->id);
            $this->core->requireOpen($e, $p);
            if ($action === 'show') {
                if (! $old || $old->revoked_at || CarbonImmutable::parse($old->expires_at)->lessThanOrEqualTo(now())) {
                    return ['active' => false, 'url' => null];
                }

                return $this->shareResult($old);
            }
            $data = Validator::make($input, ['consent' => 'required|accepted', 'alias' => 'required|string|max:80'])->validate();
            abort_if(preg_match('/[\x00-\x1f]/', $data['alias']), 422);
            if ($action === 'create' && $old && ! $old->revoked_at && CarbonImmutable::parse($old->expires_at)->greaterThan(now())) {
                return $this->shareResult($old);
            }
            $expires = CarbonImmutable::parse($e->registration_deadline->toDateString(), 'Europe/Zurich')->endOfDay()->timezone('UTC');
            if (! $manage && ! app(MemberAccessService::class)->represented($actor, $tenant, true)->whereKey($p->member_id)->exists()) {
                abort_unless($own?->birth_date, 403);
                $birthday = CarbonImmutable::parse($own->birth_date->toDateString(), 'Europe/Zurich')->addYears(18)->startOfDay()->subMicrosecond()->timezone('UTC');
                $expires = $expires->min($birthday);
            }
            abort_if($expires->lessThanOrEqualTo(now()), 409);
            $token = bin2hex(random_bytes(32));
            $row = ['tenant_id' => $tenant->id, 'event_id' => $e->id, 'participant_id' => $p->id, 'alias' => trim($data['alias']),
                'token_hash' => $this->hash($token), 'token_encrypted' => Crypt::encryptString($token), 'expires_at' => $expires->toIso8601String(),
                'consented_at' => now(), 'revoked_at' => null, 'created_by' => $actor->id, 'revision' => ($old->revision ?? 0) + 1];
            if ($old) {
                $table->update($row);
                $this->erasePending($old->id);
            } else {
                $row['id'] = $this->core->db()->table('clubora.sponsor_run_links')->insertGetId($row);
            }
            $this->core->audit($e, $actor, 'link_consented', null, ['participant_id' => $p->id, 'revision' => $row['revision']]);

            return $this->shareResult((object) $row);
        });
    }

    private function shareResult(object $link): array
    {
        return ['active' => true, 'alias' => $link->alias, 'expires_at' => (string) $link->expires_at,
            'url' => rtrim(config('app.url'), '/').'/sponsor-run#'.Crypt::decryptString($link->token_encrypted)];
    }

    private function erasePending(int $linkId): void
    {
        $ids = $this->core->db()->table('clubora.sponsor_run_public_pending')->where('link_id', $linkId)->whereNull('pledge_id')->pluck('id');
        $this->core->db()->table('clubora.sponsor_run_public_pending')->whereIn('id', $ids)->update(['payload_encrypted' => null]);
        $this->core->db()->table('clubora.sponsor_run_public_outbox')->whereIn('pending_id', $ids)->where('delivery_state', 'held')->update(['payload_encrypted' => null]);
    }

    public function rate(string $kind, string $identity, int $limit): void
    {
        $this->enabled();
        $bucket = hash_hmac('sha256', $kind.'|'.$identity.'|'.now('UTC')->format('Y-m-d-H'), config('app.key'));
        $row = $this->core->db()->selectOne('INSERT INTO clubora.sponsor_run_public_rates(bucket_hash,hits,expires_at) VALUES (?,1,?)
            ON CONFLICT(bucket_hash) DO UPDATE SET hits=clubora.sponsor_run_public_rates.hits+1 RETURNING hits', [$bucket, now()->addHours(2)]);
        abort_if((int) $row->hits > $limit, 429, 'Zu viele Anfragen. Bitte später erneut versuchen.');
        $this->core->db()->statement('DELETE FROM clubora.sponsor_run_public_rates WHERE bucket_hash IN (SELECT bucket_hash FROM clubora.sponsor_run_public_rates WHERE expires_at<? LIMIT 100)', [now()]);
    }

    private function link(string $hash, bool $lock = false): array
    {
        $db = $this->core->db();
        $initial = $db->table('clubora.sponsor_run_links')->where('token_hash', $hash)->first();
        abort_unless($initial, 404, 'Link ist ungültig oder abgelaufen.');
        $this->enabled((int) $initial->tenant_id);
        $tenant = Tenant::findOrFail($initial->tenant_id);
        if ($lock) {
            [$e, $p] = $this->core->lockedParticipant($tenant, $initial->participant_id);
        } else {
            $p = SponsorRunParticipant::where('tenant_id', $tenant->id)->findOrFail($initial->participant_id);
            $e = $this->core->event($tenant, $initial->event_id);
        }
        $link = $db->table('clubora.sponsor_run_links')->where('id', $initial->id)->where('token_hash', $hash)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        abort_unless($link && ! $link->revoked_at && CarbonImmutable::parse($link->expires_at)->greaterThan(now()), 404, 'Link ist ungültig oder abgelaufen.');
        $this->core->requireOpen($e, $p);

        return [$tenant, $e, $p, $link];
    }

    public function info(string $token): array
    {
        $this->enabled();
        [, $event, , $link] = $this->link($this->hash($token));

        return ['alias' => $link->alias, 'title' => $event->title, 'event_date' => $event->event_date->toDateString(), 'registration_deadline' => $event->registration_deadline->toDateString()];
    }

    public function submit(string $token, array $input): void
    {
        $this->enabled();
        Validator::make($input, ['consent' => 'required|accepted', 'website' => 'nullable|string|max:0'])->validate();
        abort_unless(($input['payment_mode'] ?? null) === 'invoice', 422);
        $data = SponsorRunService::pledgeInput($input);
        $hash = $this->hash($token);
        $this->rate('email', $data['email'], 5);
        $this->rate('link', $hash, 40);
        $this->core->db()->transaction(function () use ($hash, $data) {
            [$tenant, $e, $p, $link] = $this->link($hash, true);
            $this->core->db()->selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['sponsor-run-pending|'.$tenant->id.'|'.$data['request_key']]);
            $payloadHash = hash('sha256', json_encode([$hash, $link->revision, $data], JSON_THROW_ON_ERROR));
            $table = $this->core->db()->table('clubora.sponsor_run_public_pending');
            $old = (clone $table)->where('tenant_id', $tenant->id)->where('request_key', $data['request_key'])->lockForUpdate()->first();
            if ($old) {
                abort_unless(hash_equals($old->payload_hash, $payloadHash), 409, 'Anfragekennung bereits verwendet.');

                return;
            }
            $confirm = bin2hex(random_bytes(32));
            $expiry = now()->toImmutable()->addDay()->min(CarbonImmutable::parse($link->expires_at))->min(CarbonImmutable::parse($e->registration_deadline->toDateString(), 'Europe/Zurich')->endOfDay());
            $pendingId = $table->insertGetId(['tenant_id' => $tenant->id, 'link_id' => $link->id, 'link_revision' => $link->revision,
                'request_key' => $data['request_key'], 'payload_hash' => $payloadHash, 'token_hash' => $this->hash($confirm),
                'payload_encrypted' => Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR)), 'expires_at' => $expiry->toIso8601String()]);
            $body = "Testbestätigung Sponsorenlauf\n".rtrim(config('app.url'), '/').'/sponsor-run#confirm='.$confirm;
            // Never queue an external recipient. The shared test transport and guard remain mandatory.
            $this->core->db()->table('clubora.sponsor_run_public_outbox')->insert(['tenant_id' => $tenant->id, 'pending_id' => $pendingId,
                'payload_encrypted' => Crypt::encryptString(json_encode(['to' => TestMailGuard::RECIPIENT, 'title' => 'Sponsorenlauf bestätigen', 'body' => $body], JSON_THROW_ON_ERROR))]);
            $this->cleanup();
        });
    }

    public function confirm(string $token): void
    {
        $this->enabled();
        $hash = $this->hash($token);
        $this->core->db()->transaction(function () use ($hash) {
            $db = $this->core->db();
            $initial = $db->table('clubora.sponsor_run_public_pending')->where('token_hash', $hash)->first();
            abort_unless($initial && CarbonImmutable::parse($initial->expires_at)->greaterThan(now()), 404);
            $initialLink = $db->table('clubora.sponsor_run_links')->where('id', $initial->link_id)->first();
            abort_unless($initialLink, 404);
            [$tenant, $e, $p, $link] = $this->link($initialLink->token_hash, true);
            $q = $db->table('clubora.sponsor_run_public_pending')->where('id', $initial->id)->where('tenant_id', $tenant->id);
            $pending = (clone $q)->lockForUpdate()->first();
            abort_unless($pending && (int) $pending->link_revision === (int) $link->revision && CarbonImmutable::parse($pending->expires_at)->greaterThan(now()), 404);
            if ($pending->pledge_id) {
                return;
            }
            abort_unless($pending->payload_encrypted, 404);
            $data = SponsorRunService::pledgeInput(json_decode(Crypt::decryptString($pending->payload_encrypted), true, 512, JSON_THROW_ON_ERROR));
            // Separate private/public request namespaces without retaining the plaintext pending key in the pledge.
            $data['request_key'] = 'public-'.hash('sha256', $tenant->id.'|'.$pending->request_key);
            $pledge = $this->core->storePledge($tenant, $e, $p, $data, null);
            $q->update(['pledge_id' => $pledge->id, 'confirmed_at' => now(), 'payload_encrypted' => null]);
            $db->table('clubora.sponsor_run_public_outbox')->where('pending_id', $pending->id)->update(['payload_encrypted' => null]);
        });
    }

    private function cleanup(): void
    {
        $db = $this->core->db();
        $ids = $db->table('clubora.sponsor_run_public_pending')->where('expires_at', '<', now()->subDay())->whereNotNull('payload_encrypted')->limit(100)->pluck('id');
        $db->table('clubora.sponsor_run_public_pending')->whereIn('id', $ids)->update(['payload_encrypted' => null]);
        $db->table('clubora.sponsor_run_public_outbox')->whereIn('pending_id', $ids)->update(['payload_encrypted' => null]);
    }

    public function dispatchTestOutbox(Account $actor, Tenant $tenant, int $id): void
    {
        $this->core->authorize($actor, $tenant, 'send');
        abort_unless(app(MemberAccessService::class)->membership($actor, $tenant)?->role === 'admin', 403);
        abort_unless(app()->environment(['local', 'testing', 'staging']) && config('clubunio-integrations.test_mail_enabled'), 409);
        abort_unless($this->core->db()->transactionLevel() === 0, 409, 'Nur bestätigte Outbox-Eintraege versenden.');
        $row = $this->core->db()->transaction(function () use ($tenant, $id) {
            $db = $this->core->db();
            $initial = $db->table('clubora.sponsor_run_public_outbox')->where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();
            $pending = $db->table('clubora.sponsor_run_public_pending')->where('tenant_id', $tenant->id)->where('id', $initial->pending_id)->firstOrFail();
            $link = $db->table('clubora.sponsor_run_links')->where('tenant_id', $tenant->id)->where('id', $pending->link_id)->firstOrFail();
            [, , , $activeLink] = $this->link($link->token_hash, true);
            $pending = $db->table('clubora.sponsor_run_public_pending')->where('tenant_id', $tenant->id)->where('id', $pending->id)->lockForUpdate()->firstOrFail();
            abort_unless(! $pending->pledge_id && $pending->payload_encrypted && (int) $pending->link_revision === (int) $activeLink->revision && CarbonImmutable::parse($pending->expires_at)->greaterThan(now()), 409);
            $q = $this->core->db()->table('clubora.sponsor_run_public_outbox')->where('tenant_id', $tenant->id)->where('id', $id)->where('delivery_state', 'held')->whereNotNull('payload_encrypted');
            $row = (clone $q)->lockForUpdate()->firstOrFail();
            $q->update(['delivery_state' => 'sending']);

            return $row;
        });
        $state = 'failed';
        try {
            $payload = json_decode(Crypt::decryptString($row->payload_encrypted), true, 512, JSON_THROW_ON_ERROR);
            $sent = Mail::mailer('clubunio_test')->raw($payload['body'], fn ($mail) => $mail->to(TestMailGuard::RECIPIENT)->from(config('clubunio-integrations.from'), 'ClubUnio Test')->subject($payload['title']));
            $state = $sent ? 'accepted' : 'failed';
        } catch (\Throwable) {
            // Ambiguous transport outcomes are never automatically retried.
        }
        $this->core->db()->table('clubora.sponsor_run_public_outbox')->where('tenant_id', $tenant->id)->where('id', $id)->update(['delivery_state' => $state, 'finished_at' => now(), 'payload_encrypted' => null]);
    }
}
