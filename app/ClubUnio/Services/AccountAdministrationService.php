<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\AccountRole;
use App\ClubUnio\Models\AccessProfile;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Models\TenantAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountAdministrationService
{
    public function allowed(Account $actor, Tenant $tenant): bool
    {
        return Account::whereKey($actor->id)->where('active', true)->whereHas('tenants', fn ($q) => $q->where('clubora.tenants.id', $tenant->id)->where('clubora.tenant_accounts.role', 'admin'))->exists();
    }

    public function save(Account $actor, Tenant $tenant, ?int $id, array $input): TenantAccount
    {
        abort_unless($this->allowed($actor, $tenant), 403);

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $input) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->allowed($actor, $tenant), 403);
            $rules = ['username' => $id ? 'prohibited' : 'required|string|max:255|regex:/^[^\s]+$/u',
                'display_name' => 'required|string|max:255', 'active' => 'required|boolean',
                'role' => ['required', Rule::in(AccountRole::where('active', true)->pluck('code')->all())],
                'access_profile_id' => 'nullable|integer|min:1',
                'portal_enabled' => 'required|boolean', 'reason' => 'required|string|min:10|max:500',
                'revision' => $id ? 'required|integer' : 'prohibited', 'account_revision' => $id ? 'required|integer' : 'prohibited',
                'password' => $id ? 'nullable|string|min:12|max:72|confirmed' : 'required|string|min:12|max:72|confirmed',
                'password_confirmation' => 'nullable|string|max:72'];
            if (array_diff(array_keys($input), array_keys($rules))) {
                throw ValidationException::withMessages(['role' => 'Unerlaubte Felder.']);
            }
            $data = Validator::make($input, $rules)->validate();
            if (isset($data['password']) && strlen($data['password']) > 72) {
                throw ValidationException::withMessages(['password' => 'Das Passwort darf höchstens 72 UTF-8-Bytes enthalten.']);
            }
            $link = $id ? TenantAccount::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($id) : new TenantAccount;
            $profileId = $data['access_profile_id'] ?? ($data['role'] === 'profile' ? $link->access_profile_id : null);
            if ($data['role'] === 'profile') {
                if (! $profileId || $data['portal_enabled'] || ! AccessProfile::where('tenant_id', $tenant->id)
                    ->whereKey($profileId)->where('active', true)->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages(['access_profile_id' => 'Ein aktives Profil dieses Vereins ist erforderlich. Der Portalzugang muss deaktiviert sein.']);
                }
            } elseif ($profileId !== null) {
                throw ValidationException::withMessages(['access_profile_id' => 'Ein Profil darf nur der Systemrolle Berechtigungsprofil zugeordnet werden.']);
            }
            $account = $id ? Account::lockForUpdate()->findOrFail($link->account_id) : new Account;
            if ($id && ((int) $link->revision !== (int) $data['revision'] || (int) $account->revision !== (int) $data['account_revision'])) {
                throw ValidationException::withMessages(['role' => 'Benutzer wurde inzwischen geändert. Bitte neu öffnen.']);
            }
            $passwordChanged = ! empty($data['password']);
            if ($id && $account->tenants()->where('clubora.tenants.id', '<>', $tenant->id)->exists()
                && ($account->display_name !== $data['display_name'] || $account->active !== (bool) $data['active'] || $passwordChanged)) {
                throw ValidationException::withMessages(['active' => 'Vereinsübergreifendes Konto: globale Kontodaten nur nach gesonderter Prüfung ändern.']);
            }
            if ($id && $link->role === 'admin' && $account->active && ($data['role'] !== 'admin' || ! $data['active'])) {
                $other = TenantAccount::where('tenant_id', $tenant->id)->where('role', 'admin')->whereKeyNot($id)->whereHas('account', fn ($q) => $q->where('active', true))->exists();
                if (! $other) {
                    throw ValidationException::withMessages(['role' => 'Der letzte aktive Administrator muss erhalten bleiben.']);
                }
            }
            if (! $id) {
                $name = mb_strtolower(trim($data['username']));
                if (Account::whereRaw('lower(username) = ?', [$name])->exists()) {
                    throw ValidationException::withMessages(['username' => 'Dieser Benutzername ist nicht verfügbar.']);
                }
                $account->forceFill(['username' => $name]);
            }
            $before = $id ? ['display_name' => $account->display_name, 'active' => $account->active, 'role' => $link->role, 'portal_enabled' => $link->portal_enabled, 'access_profile_id' => $link->access_profile_id] : null;
            $account->forceFill(['display_name' => $data['display_name'], 'active' => (bool) $data['active'], 'revision' => ($account->revision ?? 0) + 1]);
            if ($passwordChanged) {
                $account->forceFill(['password' => Hash::make($data['password'])]);
            }
            $account->save();
            // Omitted for legacy roles until the additive migration is installed.
            if ($profileId !== null || ($id && $link->access_profile_id !== null)) {
                $link->forceFill(['access_profile_id' => $profileId]);
            }
            $link->forceFill(['tenant_id' => $tenant->id, 'account_id' => $account->id, 'role' => $data['role'], 'portal_enabled' => (bool) $data['portal_enabled'], 'revision' => ($link->revision ?? 0) + 1])->save();
            DB::connection('clubunio')->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'object_type' => 'tenant_accounts', 'object_id' => $link->id,
                'before_value' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null,
                'after_value' => json_encode(['account_id' => $account->id, 'display_name' => $account->display_name, 'active' => $account->active, 'role' => $link->role, 'portal_enabled' => $link->portal_enabled, 'access_profile_id' => $link->access_profile_id, 'password_changed' => $passwordChanged, 'reason' => $data['reason']], JSON_THROW_ON_ERROR)]);

            return $link;
        });
    }
}
