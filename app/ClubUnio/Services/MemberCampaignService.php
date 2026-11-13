<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Account, Member, MemberChangeRequest, Tenant};
use Illuminate\Support\Facades\{Crypt, DB};

class MemberCampaignService
{
    public function canView(?Account $actor, ?Tenant $tenant): bool
    {
        if ($this->allowed($actor, $tenant)) return true;
        if (! $actor || ! $tenant || request()->attributes->has('clubunio_impersonation')) return false;

        return app(MemberAccessService::class)->membership($actor, $tenant)?->role === 'admin';
    }

    public const FIELDS = ['email', 'street', 'house_number', 'postal_code', 'city', 'mobile'];
    public const CHANNELS = ['email', 'post', 'both'];

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
            && app(MemberAccessService::class)->membership($actor->fresh() ?? $actor, $tenant)?->role === 'office'
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
        // Unknown ages and minors need a separately verified guardian workflow.
        return app(MemberAccessService::class)->activeMembers($tenant)
            ->where('birth_date', '<=', now('Europe/Zurich')->subYears(18)->toDateString());
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
            if ($this->recipients($tenant, $id)->whereNotIn('member_id', $this->eligible($tenant)->select('id'))->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['release' => __('campaign.guardian_required')]);
            }
            $this->recipients($tenant, $id)->chunkById(200, function ($rows) use ($tenant, $id) {
                foreach ($rows as $row) {
                    $token = bin2hex(random_bytes(32));
                    $this->recipients($tenant, $id)->where('id', $row->id)->update([
                        'token_hash' => hash('sha256', $token), 'token_encrypted' => Crypt::encryptString($token),
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
        $this->openCampaign($tenant, $row->campaign_id);

        return [$row, $this->eligible($tenant)->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($row->member_id)];
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
        DB::connection('clubunio')->table('clubunio.campaign_recipients')->where('tenant_id', $tenant->id)
            ->where('member_id', $request->member_id)->where('request_id', $request->id)->firstOrFail();

        return $this->eligible($tenant)->lockForUpdate()->findOrFail($request->member_id);
    }

    public function url(Tenant $tenant, object $row): string
    {
        return rtrim(config('app.url'), '/').route('clubunio.campaign.response', [
            'tenant' => $tenant->slug, 'token' => Crypt::decryptString($row->token_encrypted),
        ], false);
    }
}
