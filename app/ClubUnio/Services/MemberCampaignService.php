<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Account, Member, MemberChangeRequest, Tenant};
use Illuminate\Support\Facades\{Crypt, DB};

class MemberCampaignService
{
    public function canView(?Account $actor, ?Tenant $tenant): bool
    {
        return $this->allowed($actor, $tenant);
    }

    public const FIELDS = ['email', 'street', 'house_number', 'postal_code', 'city', 'mobile'];
    public const CHANNELS = ['email', 'post', 'both'];
    public const KINDS = ['member_update', 'acknowledgement'];

    public static function kindLabel(string $kind): string
    {
        return $kind === 'acknowledgement' ? __('campaign.kind_acknowledgement') : __('campaign.kind_member_update');
    }

    public function saveContent(Account $actor, Tenant $tenant, int $id, string $kind, array $content): void
    {
        abort_unless($this->allowed($actor, $tenant), 403);
        $data = validator(['kind' => $kind, 'content' => $content], [
            'kind' => 'required|in:member_update,acknowledgement',
            'content' => 'array:de,en', 'content.*' => 'array:subject,body',
            'content.*.subject' => 'nullable|string|max:160|regex:/\A[^\r\n]*\z/',
            'content.*.body' => 'nullable|string|max:1500',
        ], [], ['kind' => __('campaign.kind'), 'content.*.subject' => __('campaign.subject'), 'content.*.body' => __('campaign.body')])->validate();
        abort_unless($this->campaigns($tenant)->where('id', $id)->whereNull('released_at')->whereNull('revoked_at')
            ->update(['kind' => $kind, 'content' => json_encode($data['content'], JSON_THROW_ON_ERROR)]) === 1, 409);
    }

    public function copy(object $campaign): array
    {
        $content = json_decode($campaign->content ?? '{}', true);
        $variant = $content[app()->getLocale()] ?? [];
        $kind = in_array($campaign->kind, self::KINDS, true) ? $campaign->kind : 'member_update';

        return [
            'subject' => trim($variant['subject'] ?? '') ?: self::kindLabel($kind),
            'body' => trim($variant['body'] ?? '') ?: ($kind === 'acknowledgement'
                ? __('campaign.body_acknowledgement') : __('campaign.body_member_update')),
        ];
    }

    private function guardian(Tenant $tenant, Member $member): ?object
    {
        $email = $this->email($member);
        if (! $email || ! in_array($member->billingRecipient?->email_field, ['mother_email', 'father_email'], true)) return null;
        $hash = app(\App\ClubUnio\Mobile\MobileIdentityChallenges::class)->emailHash($email);
        $matches = DB::connection('clubunio')->table('clubunio.account_member_links as l')
            ->join('clubunio.tenant_accounts as ta', fn ($j) => $j->on('ta.tenant_id', 'l.tenant_id')->on('ta.account_id', 'l.account_id'))
            ->join('clubunio.accounts as a', 'a.id', 'l.account_id')
            ->join('clubunio.mobile_account_emails as e', 'e.account_id', 'l.account_id')
            ->where('l.tenant_id', $tenant->id)->where('l.member_id', $member->id)
            ->where('l.kind', 'parent')->where('l.state', 'approved')->where('a.active', true)
            ->where('ta.portal_enabled', true)->where('ta.role', '!=', 'profile')
            ->whereNotNull('e.verified_at')->where('e.email_hash', $hash)
            ->distinct()->limit(2)->get(['l.account_id', 'e.email_hash']);

        return $matches->count() === 1 ? $matches->first() : null;
    }

    public function deliveryMember(Tenant $tenant, object $row, bool $lock = false): ?Member
    {
        $today = now('Europe/Zurich')->toDateString();
        $query = ($row->guardian_account_id ?? null) ? app(MemberAccessService::class)->activeMembers($tenant)
            ->where('birth_date', '<=', $today)
            ->whereRaw("birth_date + interval '18 years' > CAST(? AS date)", [$today]) : $this->eligible($tenant);
        $member = $query->when($lock, fn ($q) => $q->lockForUpdate())->find($row->member_id);
        if (! $member || ! ($row->guardian_account_id ?? null)) return $member;
        $guardian = $this->guardian($tenant, $member);

        return $guardian && (int) $guardian->account_id === (int) $row->guardian_account_id
            && hash_equals($row->guardian_email_hash, $guardian->email_hash) ? $member : null;
    }

    public function email(Member $member): ?string
    {
        $recipient = $member->billingRecipient;
        if ($member->billing_recipient_id && (! $recipient?->active || (int) $recipient->tenant_id !== (int) $member->tenant_id)) return null;
        $field = $recipient?->email_field ?? 'email';
        if (! in_array($field, ['email', 'mother_email', 'father_email'], true)) return null;
        $email = trim((string) $member->$field);

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    public function defaultChannel(string $channel, Member $member): string
    {
        return $channel === 'both' ? ($this->email($member) ? 'email' : 'post') : $channel;
    }

    public function deleteDraft(Account $actor, Tenant $tenant, int $id): void
    {
        abort_unless($this->allowed($actor, $tenant), 403);
        DB::connection('clubunio')->transaction(function () use ($tenant, $id) {
            $query = $this->campaigns($tenant)->where('id', $id);
            $row = (clone $query)->lockForUpdate()->firstOrFail();
            abort_if($row->released_at || $row->revoked_at, 409);
            $this->recipients($tenant, $id)->delete();
            $query->delete();
        });
    }

    public function allowed(?Account $actor, ?Tenant $tenant): bool
    {
        return $actor && $tenant
            && ! request()->attributes->has('clubunio_impersonation')
            && in_array(app(MemberAccessService::class)->membership($actor->fresh() ?? $actor, $tenant)?->role, ModulePermissionService::FULL_BACKOFFICE_ROLES, true)
            && app(ModulePermissionService::class)->allows($actor, $tenant, 'member_changes', 'approve');
    }

    public function campaigns(Tenant $tenant)
    {
        return DB::connection('clubunio')->table('clubunio.campaigns')->where('tenant_id', $tenant->id);
    }

    public function recipients(Tenant $tenant, int $id)
    {
        return DB::connection('clubunio')->table('clubunio.campaign_recipients')->where('tenant_id', $tenant->id)->where('campaign_id', $id);
    }

    public function eligible(Tenant $tenant)
    {
        // Postal delivery is limited to adults.
        return app(MemberAccessService::class)->activeMembers($tenant)
            ->whereRaw("birth_date + interval '18 years' <= CAST(? AS date)", [now('Europe/Zurich')->toDateString()]);
    }

    public function create(Account $actor, Tenant $tenant, string $title, string $channel, bool $populate): int
    {
        abort_unless($this->allowed($actor, $tenant), 403);
        abort_unless(in_array($channel, self::CHANNELS, true) && trim($title) !== '' && mb_strlen($title) <= 160, 422);

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $title, $channel, $populate) {
            $id = $this->campaigns($tenant)->insertGetId(['tenant_id' => $tenant->id, 'title' => trim($title), 'channel' => $channel, 'created_by' => $actor->id]);
            if ($populate) {
                app(MemberAccessService::class)->activeMembers($tenant)->with('billingRecipient')->select(['id','tenant_id','billing_recipient_id','email','mother_email','father_email'])->chunkById(300, function ($members) use ($tenant, $id, $channel) {
                    $this->recipients($tenant, $id)->insert($members->map(fn ($member) => [
                        'tenant_id' => $tenant->id, 'campaign_id' => $id, 'member_id' => $member->id, 'channel' => $this->defaultChannel($channel, $member),
                    ])->all());
                });
            }

            return $id;
        });
    }

    public function recipient(Account $actor, Tenant $tenant, int $id, int $memberId, ?string $channel): void
    {
        abort_unless($this->allowed($actor, $tenant), 403);
        DB::connection('clubunio')->transaction(function () use ($tenant, $id, $memberId, $channel) {
            $campaign = $this->campaigns($tenant)->where('id', $id)->lockForUpdate()->firstOrFail();
            abort_if($campaign->released_at || $campaign->revoked_at, 409);
            if ($channel === null) {
                $this->recipients($tenant, $id)->where('member_id', $memberId)->delete();
                return;
            }
            abort_unless(in_array($channel, self::CHANNELS, true), 422);
            app(MemberAccessService::class)->activeMembers($tenant)->findOrFail($memberId);
            $this->recipients($tenant, $id)->updateOrInsert(
                ['tenant_id' => $tenant->id, 'campaign_id' => $id, 'member_id' => $memberId], ['channel' => $channel]);
        });
    }

    public function release(Account $actor, Tenant $tenant, int $id): void
    {
        abort_unless($this->allowed($actor, $tenant), 403);
        abort_unless($this->isPilot($tenant), 403);
        DB::connection('clubunio')->transaction(function () use ($tenant, $id) {
            $campaign = $this->campaigns($tenant)->where('id', $id)->lockForUpdate()->firstOrFail();
            abort_if($campaign->released_at || $campaign->revoked_at, 409);
            abort_unless($this->recipients($tenant, $id)->exists(), 422);
            $this->recipients($tenant, $id)->chunkById(200, function ($rows) use ($tenant, $id) {
                $members = app(MemberAccessService::class)->activeMembers($tenant)->with('billingRecipient')
                    ->whereIn('id', $rows->pluck('member_id'))->get()->keyBy('id');
                $today = now('Europe/Zurich')->toDateString();
                $minors = app(MemberAccessService::class)->activeMembers($tenant)
                    ->where('birth_date', '<=', $today)->whereRaw("birth_date + interval '18 years' > CAST(? AS date)", [$today])
                    ->whereIn('id', $rows->pluck('member_id'))->pluck('id')->flip();
                $adults = $this->eligible($tenant)->whereIn('id', $rows->pluck('member_id'))->pluck('id')->flip();
                foreach ($rows as $row) {
                    $member = $members->get($row->member_id);
                    $guardian = $minors->has($row->member_id) && $member && $row->channel === 'email' ? $this->guardian($tenant, $member) : null;
                    if (! $member || (! $adults->has($row->member_id) && ! $guardian)) {
                        throw \Illuminate\Validation\ValidationException::withMessages(['release' => __('campaign.guardian_required')]);
                    }
                    $token = bin2hex(random_bytes(32));
                    $this->recipients($tenant, $id)->where('id', $row->id)->update([
                        'token_hash' => hash('sha256', $token), 'token_encrypted' => Crypt::encryptString($token),
                        'guardian_account_id' => $guardian?->account_id, 'guardian_email_hash' => $guardian?->email_hash,
                    ]);
                }
            });
            $this->campaigns($tenant)->where('id', $id)->update(['released_at' => now(), 'expires_at' => now()->addDays(30)]);
            app(CampaignMailQueue::class)->enqueueReleased($tenant, $id);
        });
    }

    public function invitation(Tenant $tenant, string $token, bool $lock = false): array
    {
        abort_unless($this->isPilot($tenant), 404);
        abort_unless(preg_match('/\A[a-f0-9]{64}\z/', $token), 404);
        $row = DB::connection('clubunio')->table('clubunio.campaign_recipients')->where('tenant_id', $tenant->id)
            ->where('token_hash', hash('sha256', $token))->whereNull('request_id')
            ->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
        $campaign = $this->openCampaign($tenant, $row->campaign_id);
        $member = $this->deliveryMember($tenant, $row, $lock);
        abort_unless($member, 404);

        return [$row, $member, $campaign];
    }

    public function acknowledge(Tenant $tenant, string $token): void
    {
        DB::connection('clubunio')->transaction(function () use ($tenant, $token) {
            [$row, , $campaign] = $this->invitation($tenant, $token, true);
            abort_unless($campaign->kind === 'acknowledgement', 404);
            $this->recipients($tenant, $row->campaign_id)->where('id', $row->id)->update([
                'acknowledged_at' => now(), 'token_hash' => null, 'token_encrypted' => null,
            ]);
        });
    }

    public function openCampaign(Tenant $tenant, int $id): object
    {
        abort_unless($this->isPilot($tenant), 404);
        return $this->campaigns($tenant)->where('id', $id)->whereNotNull('released_at')->whereNull('revoked_at')
            ->where('expires_at', '>', now())->firstOrFail();
    }

    public function isPilot(Tenant $tenant): bool
    {
        return app()->environment('staging') && $tenant->slug === 'stage-mobile-pilot';
    }

    public function consume(Tenant $tenant, object $row, MemberChangeRequest $request): void
    {
        $this->recipients($tenant, $row->campaign_id)->where('id', $row->id)->update([
            'request_id' => $request->id, 'acknowledged_at' => now(), 'token_hash' => null, 'token_encrypted' => null,
        ]);
    }

    public function reviewMember(Tenant $tenant, MemberChangeRequest $request): Member
    {
        $row = DB::connection('clubunio')->table('clubunio.campaign_recipients')->where('tenant_id', $tenant->id)
            ->where('member_id', $request->member_id)->where('request_id', $request->id)->firstOrFail();

        $member = $this->deliveryMember($tenant, $row, true);
        abort_unless($member, 404);

        return $member;
    }

    public function url(Tenant $tenant, object $row): string
    {
        return rtrim(config('app.url'), '/').route('clubunio.campaign.response', [
            'tenant' => $tenant->slug, 'token' => Crypt::decryptString($row->token_encrypted),
        ], false);
    }
}
