<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\AccessProfile;
use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\AccountRole;
use App\ClubUnio\Models\Setting;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PlatformAdministrationService
{
    public function allowed(?Account $actor): bool
    {
        return $actor && DB::connection('clubunio')->table('clubora.platform_admins as p')
            ->join('clubora.accounts as a', 'a.id', '=', 'p.account_id')->where('a.id', $actor->id)
            ->where('a.active', true)->where('a.api_only', false)->where('p.active', true)->exists();
    }

    public function authorize(?Account $actor, bool $lock = false): void
    {
        abort_unless($this->allowed($actor), 403);
        if ($lock) {
            abort_unless(Account::whereKey($actor->id)->where('active', true)->where('api_only', false)->sharedLock()->first(), 403);
            $row = DB::connection('clubunio')->table('clubora.platform_admins')->where('account_id', $actor->id)
                ->where('active', true)->sharedLock()->first();
            abort_unless($row && $this->allowed($actor), 403);
        }
    }

    public function bootstrap(string $username): void
    {
        abort_unless(app()->runningInConsole(), 403);
        DB::connection('clubunio')->transaction(function () use ($username) {
            DB::connection('clubunio')->statement('LOCK TABLE clubora.platform_admins IN EXCLUSIVE MODE');
            $actor = $this->human($username);
            $existing = DB::connection('clubunio')->table('clubora.platform_admins')->first();
            if ($existing) {
                abort_unless((int) $existing->account_id === (int) $actor->id && $this->allowed($actor), 409);

                return;
            }
            DB::connection('clubunio')->table('clubora.platform_admins')->insert(['account_id' => $actor->id]);
            $this->audit($actor, null, 'platform.bootstrap', ['account_id' => $actor->id]);
        });
    }

    private function human(string $username): Account
    {
        $actor = Account::whereRaw('lower(username) = ?', [mb_strtolower(trim($username))])
            ->where('active', true)->where('api_only', false)->first();
        if (! $actor) {
            throw ValidationException::withMessages(['username' => 'Aktives persönliches Konto nicht gefunden.']);
        }

        return $actor;
    }

    private function audit(Account $actor, ?Tenant $tenant, string $action, array $details): void
    {
        DB::connection('clubunio')->table('clubora.platform_changes')->insert(['actor_id' => $actor->id,
            'tenant_id' => $tenant?->id, 'action' => $action, 'details' => json_encode($details, JSON_THROW_ON_ERROR)]);
    }

    public function createAccount(Account $actor, array $input): void
    {
        $this->authorize($actor);
        abort_if(array_diff(array_keys($input), ['username', 'display_name', 'password', 'password_confirmation', 'reason']), 422);
        $data = Validator::make($input, ['username' => 'required|string|max:255|regex:/^[^\s]+$/u',
            'display_name' => 'required|string|max:255', 'password' => 'required|string|min:12|max:72|confirmed',
            'reason' => 'required|string|min:10|max:500'])->validate();
        if (strlen($data['password']) > 72) {
            throw ValidationException::withMessages(['password' => 'Passwort darf höchstens 72 UTF-8-Bytes enthalten.']);
        }
        DB::connection('clubunio')->transaction(function () use ($actor, $data) {
            $this->authorize($actor, true);
            $username = mb_strtolower(trim($data['username']));
            DB::connection('clubunio')->select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['clubunio-account:'.$username]);
            if (Account::whereRaw('lower(username) = ?', [$username])->exists()) {
                throw ValidationException::withMessages(['username' => 'Benutzername ist bereits vergeben.']);
            }
            $account = new Account;
            $account->forceFill(['username' => $username, 'display_name' => trim($data['display_name']),
                'password' => Hash::make($data['password']), 'active' => true, 'api_only' => false])->save();
            $this->audit($actor, null, 'account.create', ['account_id' => $account->id, 'username' => $username, 'reason' => $data['reason']]);
        });
    }

    public function create(Account $actor, array $input): Tenant
    {
        $this->authorize($actor);
        abort_if(array_diff(array_keys($input), ['name', 'slug', 'username', 'reason']), 422);
        $data = Validator::make($input, ['name' => 'required|string|max:200',
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', Rule::unique('clubunio.clubora.tenants', 'slug')],
            'username' => 'required|string|max:255', 'reason' => 'required|string|min:10|max:500'])->validate();

        return DB::connection('clubunio')->transaction(function () use ($actor, $data) {
            $this->authorize($actor, true);
            $admin = $this->human($data['username']);
            $tenant = Tenant::create(['name' => trim($data['name']), 'slug' => $data['slug']]);
            DB::connection('clubunio')->table('clubora.tenant_accounts')->insert(['tenant_id' => $tenant->id,
                'account_id' => $admin->id, 'role' => 'admin', 'portal_enabled' => false]);
            $this->audit($actor, $tenant, 'tenant.create', ['name' => $tenant->name, 'slug' => $tenant->slug,
                'admin_account_id' => $admin->id, 'reason' => $data['reason']]);

            return $tenant;
        });
    }

    public function change(Account $actor, int $id, array $input): void
    {
        $this->authorize($actor);
        $data = Validator::make($input, ['revision' => 'required|integer|min:1',
            'operation' => ['required', Rule::in(['name', 'brand', 'domain', 'membership', 'revoke'])],
            'reason' => 'required|string|min:10|max:500'])->validate();
        $fields = match ($data['operation']) {
            'name' => ['name'], 'brand' => ['color', 'logo', 'title'],
            'domain' => ['host', 'ownership_verified', 'dns_verified', 'tls_verified', 'active'],
            'membership' => ['username', 'role', 'portal_enabled', 'access_profile_id'],
            'revoke' => ['username'],
        };
        abort_if(array_diff(array_keys($input), ['revision', 'reason', 'operation', ...$fields]), 422);
        DB::connection('clubunio')->transaction(function () use ($actor, $id, $data, $input) {
            $this->authorize($actor, true);
            $tenant = Tenant::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $tenant->platform_revision === (int) $data['revision'], 409, 'Verein wurde inzwischen geändert.');
            $details = match ($data['operation']) {
                'name' => $this->rename($tenant, $input),
                'brand' => $this->brand($tenant, $input),
                'domain' => $this->domain($actor, $tenant, $input),
                'membership' => $this->membership($tenant, $input),
                'revoke' => $this->revokeMembership($tenant, $input),
            };
            $tenant->forceFill(['platform_revision' => $tenant->platform_revision + 1])->save();
            $this->audit($actor, $tenant, 'tenant.'.$data['operation'], $details + ['reason' => $data['reason']]);
        });
    }

    private function rename(Tenant $tenant, array $input): array
    {
        $data = Validator::make($input, ['name' => 'required|string|max:200'])->validate();
        $before = $tenant->name;
        $tenant->forceFill(['name' => trim($data['name'])]);

        return ['before' => $before, 'after' => $tenant->name];
    }

    private function brand(Tenant $tenant, array $input): array
    {
        $data = app(SignageBrandService::class)->validateDesign($input);
        foreach (['color', 'logo', 'title'] as $field) {
            $row = Setting::where('tenant_id', $tenant->id)->where('area', 'signage')
                ->where('key', SignageBrandService::KEYS[$field])->first() ?? new Setting;
            $row->forceFill(['tenant_id' => $tenant->id, 'area' => 'signage', 'key' => SignageBrandService::KEYS[$field],
                'label' => SignageBrandService::LABELS[$field], 'value_type' => 'string', 'enabled' => true,
                'is_secret' => false, 'encrypted_value' => Crypt::encryptString($data[$field])])->save();
        }

        return $data;
    }

    private function domain(Account $actor, Tenant $tenant, array $input): array
    {
        $data = Validator::make($input, ['host' => ['required', 'string', 'max:253',
            'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]*$/D'],
            'ownership_verified' => 'required|boolean', 'dns_verified' => 'required|boolean',
            'tls_verified' => 'required|boolean', 'active' => 'required|boolean'])->validate();
        $central = [...config('clubunio-domains.central_hosts', []), parse_url(config('app.url'), PHP_URL_HOST), 'localhost'];
        if (in_array($data['host'], $central, true) || filter_var($data['host'], FILTER_VALIDATE_IP)
            || isset(config('clubunio-domains.hosts', [])[$data['host']])) {
            throw ValidationException::withMessages(['host' => 'Zentrale oder bereits fest konfigurierte Domain ist reserviert.']);
        }
        if ($data['active'] && (! $data['ownership_verified'] || ! $data['dns_verified'] || ! $data['tls_verified'])) {
            throw ValidationException::withMessages(['host' => 'Aktivierung benötigt bestätigten Domainbesitz, DNS und TLS.']);
        }
        $table = DB::connection('clubunio')->table('clubora.tenant_domains');
        DB::connection('clubunio')->select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['clubunio-domain:'.$data['host']]);
        $old = (clone $table)->where('host', $data['host'])->lockForUpdate()->first();
        if ($old && (int) $old->tenant_id !== (int) $tenant->id) {
            throw ValidationException::withMessages(['host' => 'Domain ist bereits einem anderen Verein zugeordnet.']);
        }
        $table->updateOrInsert(['host' => $data['host']], $data + ['tenant_id' => $tenant->id,
            'verified_by' => $actor->id, 'verified_at' => now()]);

        return ['host' => $data['host'], 'active' => (bool) $data['active'], 'ownership_verified' => (bool) $data['ownership_verified'],
            'dns_verified' => (bool) $data['dns_verified'], 'tls_verified' => (bool) $data['tls_verified']];
    }

    private function membership(Tenant $tenant, array $input): array
    {
        $data = Validator::make($input, ['username' => 'required|string|max:255',
            'role' => ['required', Rule::in(AccountRole::where('active', true)->pluck('code')->all())],
            'portal_enabled' => 'required|boolean', 'access_profile_id' => 'nullable|integer|min:1'])->validate();
        $account = $this->human($data['username']);
        $profile = $data['access_profile_id'] ?? null;
        if ($data['role'] === 'profile') {
            abort_unless(! $data['portal_enabled'] && $profile && AccessProfile::where('tenant_id', $tenant->id)
                ->where('active', true)->whereKey($profile)->exists(), 422);
        } else {
            abort_if($profile !== null, 422);
        }
        $db = DB::connection('clubunio');
        $old = $db->table('clubora.tenant_accounts')->where('tenant_id', $tenant->id)->where('account_id', $account->id)->first();
        if ($old?->role === 'admin' && $data['role'] !== 'admin') {
            $other = $db->table('clubora.tenant_accounts as m')->join('clubora.accounts as a', 'a.id', '=', 'm.account_id')
                ->where('m.tenant_id', $tenant->id)->where('m.role', 'admin')->where('a.active', true)
                ->where('a.api_only', false)->where('a.id', '<>', $account->id)->exists();
            if (! $other) {
                throw ValidationException::withMessages(['role' => 'Der letzte aktive Vereinsadmin muss erhalten bleiben.']);
            }
        }
        $db->table('clubora.tenant_accounts')->updateOrInsert(['tenant_id' => $tenant->id, 'account_id' => $account->id],
            ['role' => $data['role'], 'access_profile_id' => $profile, 'portal_enabled' => (bool) $data['portal_enabled'],
                'revision' => ($old?->revision ?? 0) + 1]);

        return ['account_id' => $account->id, 'before_role' => $old?->role, 'role' => $data['role'],
            'portal_enabled' => (bool) $data['portal_enabled'], 'access_profile_id' => $profile];
    }

    private function revokeMembership(Tenant $tenant, array $input): array
    {
        $username = Validator::make($input, ['username' => 'required|string|max:255'])->validate()['username'];
        $account = $this->human($username);
        abort_unless(DB::connection('clubunio')->table('clubora.tenant_accounts')->where('tenant_id', $tenant->id)
            ->where('account_id', $account->id)->exists(), 404);

        // Retain FK-bound audit and identity links; do not delete a shared account.
        return $this->membership($tenant, ['username' => $username, 'role' => 'member', 'portal_enabled' => false]);
    }
}
