<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Account, Member, Tenant};
use Illuminate\Support\Facades\{Crypt, DB};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CampaignOperations
{
    public function enabled(): bool
    {
        return app()->environment('staging')
            && in_array(config('app.url'), ['https://dev.clubunio.ch', 'https://stage.clubunio.com'], true);
    }

    public function table(string $name)
    {
        abort_unless(in_array($name, ['campaign_dispatches','campaign_templates','campaign_delivery_events'], true), 500);
        return DB::connection('clubunio')->table('clubunio.'.$name);
    }

    public function authorise(Account $actor, Tenant $tenant): void
    {
        abort_unless($this->enabled() && app(MemberCampaignService::class)->allowed($actor, $tenant), 403);
    }

    public function testMember(Member $member, ?string $email = null): bool
    {
        return preg_match('/\Acampaign-test-[a-z0-9-]+@example\.invalid\z/i', trim((string) $member->email)) === 1
            && ($email === null || (filter_var($email, FILTER_VALIDATE_EMAIL)
                && preg_match('/\Acampaign-test-[a-z0-9-]+@example\.invalid\z/i', $email) === 1));
    }

    public function delivery(Tenant $tenant, object $row): ?array
    {
        $member = app(MemberCampaignService::class)->deliveryMember($tenant, $row);
        if (! $member) return null;
        $service = app(MemberCampaignService::class);
        $contact = app(CampaignContact::class)->details($tenant, $row, $member);
        $email = $contact ? ($contact['email'] ?: null) : $service->email($member);
        if (! $this->testMember($member, in_array($row->channel, ['email','both'], true) ? ($email ?? '') : null)) return null;
        return ['member' => $member, 'email' => $email, 'contact_hash' => $this->fingerprint($row, $member, $email)];
    }

    private function fingerprint(object $row, Member $member, ?string $email): string
    {
        return hash('sha256', json_encode([$row->channel, strtolower((string) $email),
            $row->guardian_account_id ?? null, $row->guardian_email_hash ?? null,
            $row->contact_baseline ?? null, $row->contact_encrypted ?? null,
            $member->billing_recipient_id], JSON_THROW_ON_ERROR));
    }

    public function preview(Account $actor, Tenant $tenant, int $campaign): array
    {
        $this->authorise($actor, $tenant);
        $service = app(MemberCampaignService::class);
        $c = $service->campaigns($tenant)->where('id', $campaign)->firstOrFail();
        $rows = $service->recipients($tenant, $campaign)->orderBy('id')->limit(1001)->get();
        $issues = [];
        $counts = ['email' => 0, 'post' => 0, 'invalid' => 0, 'total' => $rows->count()];
        if ($rows->isEmpty() || $rows->count() > 1000) $issues[] = ['id' => null, 'reason' => 'size'];
        $members = app(MemberAccessService::class)->activeMembers($tenant)->with('billingRecipient')
            ->whereIn('id', $rows->pluck('member_id'))->get()->keyBy('id');
        $participants = $c->kind === 'sponsor' ? \App\ClubUnio\Models\SponsorRunParticipant::where('tenant_id', $tenant->id)
            ->where('event_id', $c->target_id ?? -1)->where('required', true)->whereNull('approved_at')
            ->whereIn('member_id', $rows->pluck('member_id'))->pluck('member_id')->flip() : null;
        foreach ($rows as $row) {
            $member = $members->get($row->member_id);
            $reason = null;
            if (! $member || ! $member->birth_date || $member->birth_date->isFuture()) $reason = 'member';
            else {
                $email = $service->email($member);
                $manual = app(CampaignContact::class)->details($tenant, $row, $member);
                if ($manual) $email = $manual['email'] ?: null;
                if (CampaignContact::isMinor($member) && ! $manual
                    && ($row->channel !== 'email' || ! $service->verifiedGuardian($tenant, $member))) $reason = 'guardian';
                if (! $this->testMember($member, in_array($row->channel, ['email','both'], true) ? ($email ?? '') : null)) $reason = 'test_only';
                if (! $reason && in_array($row->channel, ['post','both'], true)) {
                    $address = $manual ?: ['street' => $member->street, 'postal_code' => $member->postal_code, 'city' => $member->city];
                    if (! $address['street'] || ! $address['postal_code'] || ! $address['city']) $reason = 'address';
                }
                if ($participants !== null && ! $participants->has($row->member_id)) $reason = 'target';
            }
            if ($reason) { $counts['invalid']++; $issues[] = ['id' => $row->id, 'reason' => $reason]; }
            if (in_array($row->channel, ['email','both'], true)) $counts['email']++;
            if (in_array($row->channel, ['post','both'], true)) $counts['post']++;
        }
        if (in_array($c->kind, ['event','sponsor'], true) && ! $this->targetValid($tenant, $c)) $issues[] = ['id' => null, 'reason' => 'target'];
        return ['counts' => $counts, 'issues' => $issues, 'copy' => $service->copy($c)];
    }

    public function targetValid(Tenant $tenant, object $campaign): bool
    {
        if (! $campaign->target_id) return false;
        if ($campaign->kind === 'event') return \App\ClubUnio\Models\ClubEvent::where('tenant_id', $tenant->id)->where('active', true)->whereKey($campaign->target_id)->exists();
        return \App\ClubUnio\Models\SponsorRunEvent::where('tenant_id', $tenant->id)->where('status_code', 'open')
            ->where('registration_deadline', '>=', now('Europe/Zurich')->toDateString())->whereKey($campaign->target_id)->exists();
    }

    public function enqueueReleased(Account $actor, Tenant $tenant, int $campaign): void
    {
        $this->authorise($actor, $tenant);
        $service = app(MemberCampaignService::class);
        foreach ($service->recipients($tenant, $campaign)->whereIn('channel', ['email','both'])->get() as $row) {
            $this->queue($actor, $tenant, $row, 0);
        }
    }

    private function queue(Account $actor, Tenant $tenant, object $row, int $sequence): void
    {
        $delivery = $this->delivery($tenant, $row);
        abort_unless($delivery && $delivery['email'], 422, trans('campaign_ops.test_only'));
        $hash = hash_hmac('sha256', strtolower($delivery['email']), config('app.key'));
        abort_if($this->table('campaign_dispatches')->where('tenant_id', $tenant->id)->where('address_hash', $hash)->where('state', 'bounced')->exists(), 422);
        $this->table('campaign_dispatches')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'campaign_id' => $row->campaign_id,
            'recipient_id' => $row->id, 'sequence' => $sequence, 'state' => 'queued', 'address_hash' => $hash,
            'contact_hash' => $delivery['contact_hash'], 'actor_id' => $actor->id,
        ]);
        app(MemberCampaignService::class)->recipients($tenant, $row->campaign_id)->where('id', $row->id)->update(['mail_state' => 'queued']);
    }

    public function reminders(Account $actor, Tenant $tenant, int $campaign): int
    {
        $this->authorise($actor, $tenant);
        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $campaign) {
            $service = app(MemberCampaignService::class);
            $c = $service->campaigns($tenant)->where('id', $campaign)->lockForUpdate()->firstOrFail();
            $service->openCampaign($tenant, $campaign);
            abort_unless((int) $c->workflow_version === 2, 409);
            $count = 0;
            foreach ($service->recipients($tenant, $campaign)->whereNull('acknowledged_at')->whereNull('request_id')
                ->whereIn('channel', ['email','both'])->get() as $row) {
                $last = $this->table('campaign_dispatches')->where('tenant_id', $tenant->id)->where('recipient_id', $row->id)
                    ->orderByDesc('sequence')->first();
                if (! $last || ! in_array($last->state, ['accepted','delivered'], true) || $last->sequence >= 3
                    || \Carbon\CarbonImmutable::parse($last->accepted_at)->addDays($c->reminder_days)->isFuture()) continue;
                $this->queue($actor, $tenant, $row, $last->sequence + 1);
                $count++;
            }
            return $count;
        });
    }

    public function retry(Account $actor, Tenant $tenant, int $campaign, string $dispatch, string $reason): void
    {
        $this->authorise($actor, $tenant);
        validator(['reason' => $reason], ['reason' => 'required|string|min:10|max:500'], [], trans('campaign_ops.fields'))->validate();
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $campaign, $dispatch, $reason) {
            app(MemberCampaignService::class)->openCampaign($tenant, $campaign);
            $q = $this->table('campaign_dispatches')->where('tenant_id', $tenant->id)->where('campaign_id', $campaign)->where('id', $dispatch);
            $row = (clone $q)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($row->state, ['failed','attention'], true) && $row->attempts < 5, 409);
            // Attention requires an explicit human decision that no delivery occurred.
            $q->update(['state' => 'queued', 'due_at' => now(), 'actor_id' => $actor->id, 'outcome' => 'manual_retry']);
            $this->event($tenant, $row->id, 'retry-'.Str::uuid(), 'queued', ['actor_id' => $actor->id, 'reason' => $reason]);
        });
    }

    public function runOne(?string $only = null): bool
    {
        if (! $this->enabled()) return false;
        $db = DB::connection('clubunio');
        return $db->transaction(function () use ($only, $db) {
            // Transport is memory-only and local; hold the lock through delivery to avoid response races.
            $db->selectOne('select pg_advisory_xact_lock(67162311)');
            $row = $this->table('campaign_dispatches')->where('state', 'queued')->where('due_at', '<=', now())
                ->when($only, fn ($q) => $q->where('id', $only))->orderBy('due_at')->lockForUpdate()->first();
            if (! $row) return false;
            $tenant = Tenant::find($row->tenant_id);
            if (! $tenant) return false;
            $recent = $this->table('campaign_dispatches')->where('tenant_id', $tenant->id)
                ->where('attempted_at', '>', now()->subSeconds((int) ceil(60 / max(1, min(30, config('clubunio-campaign-mail.per_minute'))))))->exists();
            if ($recent) return false;
            $service = app(MemberCampaignService::class);
            $recipient = $service->recipients($tenant, $row->campaign_id)->where('id', $row->recipient_id)->lockForUpdate()->first();
            $campaign = $service->campaigns($tenant)->where('id', $row->campaign_id)->first();
            $actor = Account::find($row->actor_id);
            $valid = $campaign && $campaign->released_at && ! $campaign->revoked_at && $campaign->expires_at
                && \Carbon\CarbonImmutable::parse($campaign->expires_at)->isFuture()
                && $actor && $service->allowed($actor, $tenant) && $recipient
                && ! $recipient->acknowledged_at && ! $recipient->request_id && $recipient->token_encrypted;
            $state = 'cancelled';
            $outcome = 'no_longer_eligible';
            $diagnostic = null;
            try {
                $delivery = $valid ? $this->delivery($tenant, $recipient) : null;
                if ($delivery && hash_equals($row->contact_hash, $delivery['contact_hash'])
                    && ! $this->table('campaign_dispatches')->where('tenant_id', $tenant->id)->where('address_hash', $row->address_hash)->where('state', 'bounced')->exists()) {
                    $copy = $service->copyFor($campaign, $recipient->locale);
                    $message = (new \Symfony\Component\Mime\Email())->from('campaign-test@example.invalid')
                        ->to('campaign-test-sink@example.invalid')->subject($copy['subject'])
                        ->text($copy['body']."\n\n".$service->url($tenant, $recipient));
                $message->html(app(ClubUnioMailTheme::class)->render(
                    $tenant, $copy['subject'], $copy['body'], $service->url($tenant, $recipient),
                    trans('mail_theme.open', [], $recipient->locale), $recipient->locale,
                ));
                    $message->html(app(ClubUnioMailTheme::class)->render(
                    $tenant, $copy['subject'], $copy['body'], $service->url($tenant, $recipient),
                    trans('mail_theme.open', [], $recipient->locale), $recipient->locale,
                ));
                    $message->getHeaders()->addIdHeader('Message-ID', $row->id.'@campaign.clubunio.invalid');
                    $message->getHeaders()->addTextHeader('X-ClubUnio-Campaign-Address-Hash', $row->address_hash);
                    $sent = (new \Illuminate\Mail\Transport\ArrayTransport())->send($message);
                    $state = $sent ? 'accepted' : 'attention';
                    $outcome = $sent ? 'memory_simulated' : 'uncertain';
                }
            } catch (\Illuminate\Database\QueryException $error) {
                throw $error;
            } catch (\Throwable $error) {
                $state = 'attention';
                $outcome = 'processing_error';
                $diagnostic = ['exception' => $error::class, 'file' => basename($error->getFile()), 'line' => $error->getLine()];
            }
            $this->table('campaign_dispatches')->where('id', $row->id)->where('tenant_id', $tenant->id)->update([
                'state' => $state, 'attempts' => $row->attempts + 1, 'attempted_at' => now(),
                'accepted_at' => $state === 'accepted' ? now() : null,
                'finished_at' => now(), 'outcome' => $outcome,
            ]);
            $service->recipients($tenant, $row->campaign_id)->where('id', $row->recipient_id)->update(['mail_state' => $state]);
            $this->event($tenant, $row->id, 'attempt-'.Str::uuid(), $state, $diagnostic);
            return true;
        });
    }

    private function event(Tenant $tenant, string $dispatch, string $key, string $state, ?array $details = null): void
    {
        $this->table('campaign_delivery_events')->insertOrIgnore([
            'tenant_id' => $tenant->id, 'dispatch_id' => $dispatch, 'source_key' => $key, 'state' => $state,
            'details_encrypted' => $details ? Crypt::encryptString(json_encode($details, JSON_THROW_ON_ERROR)) : null,
        ]);
    }

    public function feedback(array $input): void
    {
        abort_unless($this->enabled(), 403);
        $data = validator($input, ['event_id' => 'required|uuid', 'dispatch_id' => 'required|uuid',
            'state' => 'required|in:delivered,bounced', 'address_hash' => 'required|string|size:64'])->validate();
        DB::connection('clubunio')->transaction(function () use ($data) {
            $q = $this->table('campaign_dispatches')->where('id', $data['dispatch_id']);
            $row = (clone $q)->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals($row->address_hash, $data['address_hash']), 422);
            if ($this->table('campaign_delivery_events')->where('tenant_id', $row->tenant_id)->where('source_key', $data['event_id'])->exists()) return;
            abort_unless(in_array($row->state, ['accepted','delivered','bounced'], true), 409);
            $tenant = Tenant::findOrFail($row->tenant_id);
            $this->event($tenant, $row->id, $data['event_id'], $data['state']);
            // A delayed "delivered" report must not undo a permanent bounce.
            if ($row->state === 'bounced') return;
            $q->update(['state' => $data['state'], 'outcome' => 'feedback', 'finished_at' => now()]);
            $latest = $this->table('campaign_dispatches')->where('tenant_id', $row->tenant_id)
                ->where('recipient_id', $row->recipient_id)->orderByDesc('sequence')->value('id');
            if ($latest === $row->id) {
                app(MemberCampaignService::class)->recipients($tenant, $row->campaign_id)->where('id', $row->recipient_id)
                    ->update(['mail_state' => $data['state']]);
            }
            if ($data['state'] === 'bounced') {
                $this->table('campaign_dispatches')->where('tenant_id', $row->tenant_id)->where('address_hash', $row->address_hash)->where('state', 'queued')
                    ->update(['state' => 'cancelled', 'outcome' => 'suppressed']);
            }
        });
    }

    public function manualBounce(Account $actor, Tenant $tenant, int $campaign, string $dispatch, string $reason): void
    {
        $this->authorise($actor, $tenant);
        validator(['reason' => $reason], ['reason' => 'required|string|min:10|max:500'], [], trans('campaign_ops.fields'))->validate();
        $row = $this->table('campaign_dispatches')->where('tenant_id', $tenant->id)->where('campaign_id', $campaign)->where('id', $dispatch)->firstOrFail();
        DB::connection('clubunio')->transaction(function () use ($row, $reason, $actor, $tenant) {
            $key = (string) Str::uuid();
            $this->feedback(['event_id' => $key, 'dispatch_id' => $row->id, 'state' => 'bounced', 'address_hash' => $row->address_hash]);
            $this->table('campaign_delivery_events')->where('tenant_id', $tenant->id)->where('source_key', $key)
                ->update(['details_encrypted' => Crypt::encryptString(json_encode(['actor_id' => $actor->id, 'reason' => $reason], JSON_THROW_ON_ERROR))]);
        });
    }

    public function templates(Tenant $tenant)
    {
        return $this->table('campaign_templates')->where('tenant_id', $tenant->id)->where('active', true);
    }

    public function saveTemplate(Account $actor, Tenant $tenant, int $campaign, string $name): int
    {
        $this->authorise($actor, $tenant);
        validator(['name' => $name], ['name' => 'required|string|max:160'], [], trans('campaign_ops.fields'))->validate();
        $c = app(MemberCampaignService::class)->campaigns($tenant)->where('id', $campaign)->firstOrFail();
        return $this->table('campaign_templates')->insertGetId(['tenant_id' => $tenant->id,
            'name' => trim($name), 'kind' => $c->kind, 'content' => $c->content ?? '{}', 'created_by' => $actor->id]);
    }

    public function applyTemplate(Account $actor, Tenant $tenant, int $campaign, int $template): void
    {
        $this->authorise($actor, $tenant);
        $row = $this->templates($tenant)->where('id', $template)->firstOrFail();
        app(MemberCampaignService::class)->saveContent($actor, $tenant, $campaign, $row->kind, json_decode($row->content, true));
    }

    public function updateTemplate(Account $actor, Tenant $tenant, int $campaign, int $template, int $revision): void
    {
        $this->authorise($actor, $tenant);
        $c = app(MemberCampaignService::class)->campaigns($tenant)->where('id', $campaign)->firstOrFail();
        abort_unless($this->templates($tenant)->where('id', $template)->where('revision', $revision)
            ->update(['kind' => $c->kind, 'content' => $c->content ?? '{}', 'revision' => $revision + 1, 'updated_at' => now()]) === 1, 409);
    }

    public function archiveTemplate(Account $actor, Tenant $tenant, int $template, int $revision): void
    {
        $this->authorise($actor, $tenant);
        abort_unless($this->templates($tenant)->where('id', $template)->where('revision', $revision)
            ->update(['active' => false, 'revision' => $revision + 1, 'updated_at' => now()]) === 1, 409);
    }

    public function configure(Account $actor, Tenant $tenant, int $id, ?int $target, int $days, bool $automatic = false): void
    {
        $this->authorise($actor, $tenant);
        abort_unless($days >= 1 && $days <= 30, 422);
        DB::connection('clubunio')->transaction(function () use ($tenant, $id, $target, $days, $automatic) {
            $q = app(MemberCampaignService::class)->campaigns($tenant)->where('id', $id);
            $c = (clone $q)->lockForUpdate()->firstOrFail();
            abort_if($c->released_at || $c->revoked_at, 409);
            $c->target_id = $target;
            if (in_array($c->kind, ['event','sponsor'], true)) abort_unless($this->targetValid($tenant, $c), 422);
            $q->update(['target_id' => in_array($c->kind, ['event','sponsor'], true) ? $target : null, 'reminder_days' => $days,
                'auto_reminders' => $automatic]);
        });
    }

    public function automaticReminders(): int
    {
        if (! $this->enabled()) return 0;
        $count = 0;
        DB::connection('clubunio')->table('clubunio.campaigns')->where('workflow_version', 2)->where('auto_reminders', true)
            ->whereNotNull('released_at')->whereNull('revoked_at')->where('expires_at', '>', now())->orderBy('id')
            ->chunkById(50, function ($rows) use (&$count) {
                foreach ($rows as $row) {
                    $tenant = Tenant::find($row->tenant_id);
                    $actor = Account::find($row->created_by);
                    if ($tenant && $actor && app(MemberCampaignService::class)->allowed($actor, $tenant)) {
                        $count += $this->reminders($actor, $tenant, $row->id);
                    }
                }
            });
        return $count;
    }

    public function cockpit(Account $actor, Tenant $tenant, int $id, int $limit = 25): array
    {
        $this->authorise($actor, $tenant);
        $service = app(MemberCampaignService::class);
        $service->campaigns($tenant)->where('id', $id)->firstOrFail();
        $rows = $service->recipients($tenant, $id);
        $summary = (clone $rows)->selectRaw("count(*) as total,
            count(*) FILTER (WHERE first_opened_at IS NOT NULL) as opened,
            count(*) FILTER (WHERE acknowledged_at IS NOT NULL) as responded,
            count(*) FILTER (WHERE printed_at IS NOT NULL) as printed,
            count(*) FILTER (WHERE posted_at IS NOT NULL) as posted")->first();
        $dispatches = $this->table('campaign_dispatches')->where('tenant_id', $tenant->id)->where('campaign_id', $id);
        $states = (clone $dispatches)->groupBy('state')->selectRaw('state, count(*) as count')->pluck('count', 'state')->all();
        $labels = DB::connection('clubunio')->table('clubunio.campaign_recipients as r')->where('r.tenant_id', $tenant->id)->where('r.campaign_id', $id)
            ->join('clubunio.members as m', fn ($j) => $j->on('m.id', 'r.member_id')->on('m.tenant_id', 'r.tenant_id'))
            ->selectRaw("r.id, concat_ws(' ', m.first_name, m.last_name) as label")->pluck('label','r.id');
        $summary->submitted = (clone $rows)->whereNotNull('request_id')->count();
        foreach (['approved','rejected'] as $state) {
            $summary->$state = (clone $rows)->whereIn('request_id', DB::connection('clubunio')->table('clubunio.member_change_requests')
                ->where('tenant_id', $tenant->id)->where('state', $state)->select('id'))->count();
        }
        $responses = (clone $rows)->whereNotNull('response_encrypted')->orderByDesc('acknowledged_at')->limit($limit)->get()
            ->map(fn ($r) => ['id' => $r->id, 'member_id' => $r->member_id, 'label' => $labels[$r->id] ?? '', 'reviewed' => $r->response_disposition,
                'object_id' => $r->response_object_id, 'data' => json_decode(Crypt::decryptString($r->response_encrypted), true)]);
        return ['summary' => (array) $summary, 'states' => $states,
            'dispatches' => (clone $dispatches)->orderByDesc('created_at')->orderBy('id')->limit($limit)->get()
                ->map(function ($row) use ($labels) { $row->recipient_label = $labels[$row->recipient_id] ?? ''; return $row; }),
            'events' => $this->table('campaign_delivery_events')->where('tenant_id', $tenant->id)
                ->whereIn('dispatch_id', (clone $dispatches)->select('id'))->orderByDesc('id')->limit($limit)->get(),
            'responses' => $responses, 'dispatch_total' => (clone $dispatches)->count(),
            'response_total' => (clone $rows)->whereNotNull('response_encrypted')->count()];
    }
}
