<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Account, Tenant, TenantAccount};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Hash, Validator};
use Illuminate\Validation\ValidationException;

final class TenantSecurityRecovery
{
    public function __construct(private AccountSecurity $security) {}

    public function operator(Account $actor, Tenant $tenant): bool
    {
        return $this->security->enabled() && $actor->active && !$actor->api_only
            && !request()->attributes->has('clubunio_impersonation')
            && TenantAccount::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('account_id', $actor->id)
                ->whereIn('role', ['admin', 'office'])->exists();
    }

    public function allowed(Account $actor, Tenant $tenant, TenantAccount $link): bool
    {
        $target = $link->account;
        return $this->operator($actor, $tenant) && (int)$link->tenant_id === (int)$tenant->id
            && $target?->active && !$target->api_only && $actor->id !== $target->id
            && !in_array($link->role, ['admin', 'office', 'profile'], true)
            && !TenantAccount::withoutGlobalScopes()->where('account_id', $target->id)->where('tenant_id', '<>', $tenant->id)->exists()
            && !$this->security->db()->table('clubora.platform_admins')->where('account_id', $target->id)->exists()
            && $this->security->configured($target);
    }

    public function reset(Request $request, Account $actor, Tenant $tenant, int $linkId, array $input): string
    {
        $data = Validator::make($input, ['operator_password' => 'required|string|max:4096',
            'reason' => 'required|string|min:10|max:500', 'identity_verified' => 'accepted'])->validate();
        $this->security->rate('support-reset', (string)$actor->id, 6, 300);
        return $this->security->db()->transaction(function () use ($request, $actor, $tenant, $linkId, $data) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $link = TenantAccount::withoutGlobalScopes()->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($linkId);
            $accounts = Account::whereIn('id', [$actor->id, $link->account_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $actor = $accounts->get($actor->id);
            $target = $accounts->get($link->account_id);
            abort_unless($actor && $target, 403);
            $link->setRelation('account', $target);
            abort_unless($this->allowed($actor, $tenant, $link), 403);
            $stamp = $request->session()->get('clubunio.security.clubunio');
            $verifiedAt = $request->session()->get('clubunio.security.verified_at.clubunio');
            if (!$this->security->configured($actor) || $this->security->resetPending($actor)
                || !is_string($stamp) || !hash_equals($this->security->stamp($actor), $stamp)
                || !is_int($verifiedAt) || $verifiedAt < time() - 600 || !Hash::check($data['operator_password'], $actor->password)) {
                throw ValidationException::withMessages(['operator_password' => trans('account-security.reset_reauth')]);
            }
            $this->security->settings()->where('account_id', $target->id)->update([
                'reset_pending' => true, 'session_version' => bin2hex(random_bytes(16)), 'recovery_hashes' => '[]',
            ]);
            $this->security->db()->table('clubora.mobile_sessions')->where('account_id', $target->id)
                ->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $code = $this->security->invitation($target, recovery: true);
            $this->security->db()->table('clubora.object_changes')->insert([
                'tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'object_type' => 'tenant_accounts', 'object_id' => $link->id,
                'after_value' => json_encode(['action' => 'strong_login_reset', 'account_id' => $target->id, 'reason' => $data['reason']], JSON_THROW_ON_ERROR),
            ]);
            return $code;
        });
    }
}
