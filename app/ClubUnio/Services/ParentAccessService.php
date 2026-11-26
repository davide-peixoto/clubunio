<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Account, Member, Tenant};
use Illuminate\Support\Facades\DB;

/** Explicit member consent and automatic, verified minor representation. */
class ParentAccessService
{
    public function enabled(): bool { return config('clubunio-parent-access.enabled', false) === true; }
    private function db() { return DB::connection('clubunio'); }
    private function links() { return $this->db()->table('clubunio.account_member_links'); }
    private function proofs() { return $this->db()->table('clubunio.account_parent_email_proofs'); }
    public function minor(Member $member): bool
    {
        $birth = $member->birth_date?->toDateString();
        $today = now('Europe/Zurich')->toDateString();
        return $birth !== null && $birth <= $today
            && \Carbon\CarbonImmutable::parse($birth, 'Europe/Zurich')->addYearsNoOverflow(18)->toDateString() > $today;
    }
    private function adult(Member $member): bool
    {
        $birth = $member->birth_date?->toDateString();
        return $birth !== null && \Carbon\CarbonImmutable::parse($birth, 'Europe/Zurich')->addYearsNoOverflow(18)->toDateString() <= now('Europe/Zurich')->toDateString();
    }
    private function email(string $email): string { return mb_strtolower(trim($email)); }

    /** Caller has already verified OIDC signature, issuer and subject-to-account binding. */
    public function verifiedLogin(Account $actor, \stdClass $claims, ?Tenant $onlyTenant = null): void
    {
        if (! $this->enabled() || ! $actor->fresh()?->active || $actor->api_only) return;
        if (($claims->email_verified ?? false) !== true) {
            if (property_exists($claims, 'email_verified')) $this->proofs()->where('account_id', $actor->id)->delete();
            return;
        }
        if (! is_string($claims->email ?? null)
            || strlen($claims->email) > 254 || ! filter_var($claims->email, FILTER_VALIDATE_EMAIL)
            || ! is_string($claims->iss ?? null) || $claims->iss !== config('clubunio-verein-oidc.issuer')) return;
        $this->rememberEmail($actor, $claims->email, $claims->iss, $onlyTenant);
    }

    /** Called only after a consumed, account-bound email-verification challenge. */
    public function verifiedEmailChallenge(Account $actor, string $email, Tenant $tenant): void
    {
        if (! $this->enabled() || ! $actor->fresh()?->active || $actor->api_only
            || strlen($email) > 254 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) return;
        $this->rememberEmail($actor, $email, 'verified_email_challenge', $tenant);
    }

    private function rememberEmail(Account $actor, string $email, string $issuer, ?Tenant $onlyTenant): void
    {
        $email = $this->email($email);
        $this->proofs()->upsert([['account_id' => $actor->id, 'email' => $email,
            'verified_at' => now(), 'issuer_hash' => hash('sha256', $issuer)]], ['account_id'], ['email', 'verified_at', 'issuer_hash']);
        $this->synchronize($actor, $onlyTenant);
    }

    /** Existing session may use a previously verified email, never an unverified username. */
    public function synchronize(Account $actor, ?Tenant $onlyTenant = null): void
    {
        if (! $this->enabled() || ! $actor->fresh()?->active || $actor->api_only) return;
        $proof = $this->proofs()->where('account_id', $actor->id)->first();
        if (! $proof) return;
        $members = app(MemberAccessService::class)->currentMembers()
            ->when($onlyTenant !== null, fn ($q) => $q->where('tenant_id', $onlyTenant->id))
            ->where(fn ($q) => $q->whereRaw('lower(trim(mother_email)) = ?', [$proof->email])
                ->orWhereRaw('lower(trim(father_email)) = ?', [$proof->email]))
            ->orderBy('tenant_id')->orderBy('id')->limit(501)->get();
        abort_if($members->count() > 500, 503);
        foreach ($members as $candidate) {
            $this->db()->transaction(function () use ($actor, $candidate, $proof) {
                $tenant = Tenant::whereKey($candidate->tenant_id)->lockForUpdate()->firstOrFail();
                $member = app(MemberAccessService::class)->activeMembers($tenant)->find($candidate->id);
                if (! $member || ! $actor->fresh()?->active || $actor->fresh()?->api_only) return;
                $freshProof = $this->proofs()->where('account_id', $actor->id)->first();
                if (! $freshProof || $freshProof->email !== $proof->email
                    || ! in_array($proof->email, [$this->email($member->mother_email ?? ''), $this->email($member->father_email ?? '')], true)) return;
                // The member's own login never becomes its own parent.
                if ($this->links()->where('tenant_id', $tenant->id)->where('account_id', $actor->id)
                    ->where('member_id', $member->id)->where('kind', 'self')->where('state', 'approved')->exists()) return;
                $row = $this->links()->where('tenant_id', $tenant->id)->where('account_id', $actor->id)
                    ->where('member_id', $member->id)->where('kind', 'parent')->lockForUpdate()->first();
                if ($row && ($row->state !== 'pending' || $row->reviewed_by !== null)) return;
                $minor = $this->minor($member);
                if (! $minor && $row?->source === 'verified_parent_login') return;
                $key = ['tenant_id' => $tenant->id, 'account_id' => $actor->id, 'member_id' => $member->id, 'kind' => 'parent'];
                $values = ['state' => $minor ? 'approved' : 'pending', 'source' => 'verified_parent_login',
                    'revision' => $row ? $row->revision + 1 : 1];
                $this->membershipForParent($actor, $tenant);
                if ($row) $this->links()->where('id', $row->id)->update($values + ['updated_at' => now()]);
                else $key['id'] = $this->links()->insertGetId($key + $values);
                $id = $row->id ?? $key['id'];
                $this->audit($actor, $tenant, (int) $id, $row, $values + ['operation' => $minor ? 'minor_auto_approved' : 'adult_consent_required']);
            });
        }
    }

    private function membershipForParent(Account $actor, Tenant $tenant): void
    {
        $table = $this->db()->table('clubunio.tenant_accounts');
        $row = (clone $table)->where('tenant_id', $tenant->id)->where('account_id', $actor->id)->first();
        if (! $row) $table->insert(['tenant_id' => $tenant->id, 'account_id' => $actor->id, 'role' => 'member', 'portal_enabled' => true]);
        // An explicit portal suspension or administrative role is never overwritten.
    }

    private function own(Account $actor, Tenant $tenant, int $member): bool
    {
        return app(MemberAccessService::class)->represented($actor, $tenant, true)->whereKey($member)->exists();
    }

    public function profile(Account $actor, Tenant $tenant): array
    {
        abort_unless($this->enabled(), 503);
        $actor = $actor->fresh();
        abort_unless($actor?->active && ! $actor->api_only, 403);
        $this->synchronize($actor, $tenant);
        $members = app(MemberAccessService::class)->represented($actor, $tenant, true)->orderBy('id')->get();
        return ['Members' => $members->map(function ($member) use ($tenant) {
            $parents = $this->links()->where('tenant_id', $tenant->id)->where('member_id', $member->id)->where('kind', 'parent')
                ->join('clubunio.accounts as parent_account', 'parent_account.id', '=', 'account_member_links.account_id')
                ->orderBy('account_member_links.id')->get(['account_member_links.*', 'parent_account.display_name', 'parent_account.username']);
            return ['MemberID' => (int) $member->id, 'Name' => trim($member->first_name.' '.$member->last_name),
                'Adult' => $this->adult($member), 'Parents' => $parents->map(fn ($row) => $this->parentDto($row, $member))->all()];
        })->all(), 'Children' => $this->links()->where('tenant_id', $tenant->id)->where('account_id', $actor->id)->where('kind', 'parent')
            ->orderBy('id')->get()->map(function ($row) use ($tenant) {
                $member = app(MemberAccessService::class)->activeMembers($tenant)->find($row->member_id);
                if (! $member) return null;
                $effective = app(ParentAccessPolicy::class)->effective($row, $member);
                if (! $effective && $row->reviewed_by === null) {
                    $email = $this->proofs()->where('account_id', $row->account_id)->value('email');
                    if (! $email || ! in_array($email, [$this->email($member->mother_email ?? ''), $this->email($member->father_email ?? '')], true)) return null;
                }
                return ['MemberID' => (int) $member->id, 'Name' => trim($member->first_name.' '.$member->last_name),
                    'Adult' => $this->adult($member), 'State' => $effective ? 'approved' : ($row->state === 'revoked' ? 'revoked' : 'pending'),
                    'ConsentRequired' => $this->adult($member) && ! $effective];
            })->filter()->values()->all()];
    }

    private function parentDto(object $row, Member $member): array
    {
        $effective = app(ParentAccessPolicy::class)->effective($row, $member);
        $email = $this->proofs()->where('account_id', $row->account_id)->value('email');
        return ['LinkID' => (int) $row->id, 'Name' => (string) $row->display_name, 'Email' => $email,
            'State' => $effective ? 'approved' : ($row->state === 'revoked' ? 'revoked' : 'pending'),
            'CanApprove' => $this->adult($member) && ! $effective, 'CanRevoke' => $this->adult($member) && $row->state !== 'revoked',
            'Revision' => (int) $row->revision, 'ConsentRequired' => $this->adult($member) && ! $effective];
    }

    public function decide(Account $actor, Tenant $tenant, int $id, int $revision, bool $approve, ?\Closure $reauthorize = null): void
    {
        abort_unless($this->enabled(), 503);
        $this->db()->transaction(function () use ($actor, $tenant, $id, $revision, $approve, $reauthorize) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            if ($reauthorize) $reauthorize();
            $row = $this->links()->where('tenant_id', $tenant->id)->where('kind', 'parent')->where('id', $id)->lockForUpdate()->firstOrFail();
            $fresh = $actor->fresh();
            abort_unless($fresh?->active && ! $fresh->api_only && $this->own($fresh, $tenant, (int) $row->member_id), 403);
            abort_unless((int) $row->revision === $revision, 409);
            $member = app(MemberAccessService::class)->activeMembers($tenant)->findOrFail($row->member_id);
            abort_unless($this->adult($member), 422);
            $values = ['state' => $approve ? 'approved' : 'revoked', 'revision' => $revision + 1,
                'reviewed_by' => $fresh->id, 'reviewed_at' => now(), 'updated_at' => now(),
                'adult_consent_at' => $approve ? now() : null, 'adult_consent_by' => $approve ? $fresh->id : null,
                'adult_consent_source' => $approve ? 'member' : null];
            $this->links()->where('id', $row->id)->update($values);
            if ($approve) {
                $parent = Account::whereKey($row->account_id)->where('active', true)->where('api_only', false)->firstOrFail();
                $this->membershipForParent($parent, $tenant);
            }
            $this->audit($fresh, $tenant, (int) $row->id, $row, $values + ['operation' => $approve ? 'member_consent' : 'member_revoked']);
        });
    }

    /** Explicit own-member grant to an already verified login; no account search/enumeration. */
    public function grant(Account $actor, Tenant $tenant, int $memberId, string $email, ?\Closure $reauthorize = null): void
    {
        abort_unless($this->enabled(), 503);
        $this->db()->transaction(function () use ($actor, $tenant, $memberId, $email, $reauthorize) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            if ($reauthorize) $reauthorize();
            $actor = $actor->fresh();
            abort_unless($actor?->active && ! $actor->api_only && $this->own($actor, $tenant, $memberId), 403);
            $member = app(MemberAccessService::class)->activeMembers($tenant)->findOrFail($memberId);
            abort_unless($this->adult($member), 422);
            $proofs = $this->proofs()->where('email', $this->email($email))->join('clubunio.accounts as a', 'a.id', '=', 'account_parent_email_proofs.account_id')
                ->where('a.active', true)->where('a.api_only', false)->get(['a.id']);
            abort_unless($proofs->count() === 1 && (int) $proofs->sole()->id !== (int) $actor->id, 422,
                __('parent_access.verified_login_required'));
            $parent = Account::findOrFail($proofs->sole()->id);
            $row = $this->links()->where('tenant_id', $tenant->id)->where('member_id', $memberId)->where('account_id', $parent->id)->where('kind', 'parent')->lockForUpdate()->first();
            $key = ['tenant_id' => $tenant->id, 'member_id' => $memberId, 'account_id' => $parent->id, 'kind' => 'parent'];
            $values = ['state' => 'approved', 'source' => 'member_parent_grant', 'revision' => $row ? $row->revision + 1 : 1,
                'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'adult_consent_at' => now(), 'adult_consent_by' => $actor->id,
                'adult_consent_source' => 'member'];
            $this->membershipForParent($parent, $tenant);
            $id = $row->id ?? $this->links()->insertGetId($key + $values);
            if ($row) $this->links()->where('id', $id)->update($values + ['updated_at' => now()]);
            $this->audit($actor, $tenant, (int) $id, $row, $values + ['operation' => 'member_parent_grant']);
        });
    }

    private function audit(Account $actor, Tenant $tenant, int $id, ?object $before, array $after): void
    {
        $this->db()->table('clubunio.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id,
            'object_type' => 'account_member_links', 'object_id' => $id,
            'before_value' => $before ? json_encode(['state' => $before->state, 'revision' => $before->revision], JSON_THROW_ON_ERROR) : null,
            'after_value' => json_encode($after, JSON_THROW_ON_ERROR)]);
    }
}
