<?php

namespace App\ClubUnio\Services;

use Illuminate\Support\Facades\DB;

final class PlatformKeycloakMigration
{
    public static function enabled(): bool
    {
        return (bool) (config('clubunio-keycloak-migration.enabled')
            && config('clubunio-keycloak-migration.client') && config('clubunio-keycloak-migration.secret')
            && config('clubunio-verein-oidc.enabled') && config('clubunio-verein-oidc.invites_enabled'));
    }

    public function preview(int $tenant, array $ids): array
    {
        $db = DB::connection('clubunio');
        $issuer = config('clubunio-verein-oidc.issuer');
        $configured = array_merge((array) config('clubunio-verein-oidc.subjects'), (array) config('clubunio-platform-oidc.subjects'));
        $rows = $db->table('clubora.accounts as a')
            ->join('clubora.tenant_accounts as ta', 'ta.account_id', '=', 'a.id')
            ->leftJoin('clubora.keycloak_migrations as km', 'km.account_id', '=', 'a.id')
            ->where('ta.tenant_id', $tenant)->whereIn('a.id', $ids)->orderBy('a.id')
            ->get(['a.id', 'a.username', 'a.display_name', 'a.active', 'a.api_only', 'km.invited_at', 'km.activated_at', 'km.email', 'km.issuer']);
        abort_unless($rows->count() === count($ids), 404);
        $memberships = $db->table('clubora.tenant_accounts as ta')->join('clubora.tenants as t', 't.id', '=', 'ta.tenant_id')
            ->whereIn('ta.account_id', $ids)->orderBy('t.id')->get(['ta.account_id', 't.id', 't.name'])->groupBy('account_id');
        $linked = $db->table('clubora.keycloak_account_links')->where('issuer', $issuer)->whereIn('account_id', $ids)->pluck('account_id')->map(fn ($id) => (int) $id)->all();
        $admins = $db->table('clubora.platform_admins')->whereIn('account_id', $ids)->pluck('account_id')->map(fn ($id) => (int) $id)->all();

        return $rows->map(function ($row) use ($memberships, $linked, $admins, $configured, $issuer) {
            $id = (int) $row->id;
            $email = mb_strtolower(trim($row->username));
            $status = match (true) {
                $row->activated_at !== null || in_array($id, $linked, true) => 'active',
                !$row->active || $row->api_only || in_array($id, $admins, true) || in_array($id, $configured, true) => 'ineligible',
                strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL) => 'invalid_email',
                $row->issuer !== null && ($row->email !== $email || $row->issuer !== $issuer) => 'conflict',
                $row->invited_at !== null => 'invited',
                default => 'pending',
            };

            return ['id' => $id, 'name' => $row->display_name, 'email' => $email, 'status' => $status,
                'tenants' => $memberships[$id]->map(fn ($t) => ['id' => (int) $t->id, 'name' => $t->name])->all()];
        })->all();
    }

    public function send(int $tenant, array $item): string
    {
        if (!in_array($item['status'], ['pending', 'invited'], true)) {
            return $item['status'];
        }
        $db = DB::connection('clubunio');
        $gateway = app(KeycloakMigrationGateway::class);
        $subject = $db->transaction(function () use ($db, $gateway, $tenant, $item) {
            $db->table('clubora.accounts')->where('id', $item['id'])->lockForUpdate()->first();
            $db->table('clubora.tenant_accounts')->where('tenant_id', $tenant)->where('account_id', $item['id'])->lockForUpdate()->first();
            if ($this->preview($tenant, [$item['id']])[0] !== $item) {
                return null;
            }
            $row = $db->table('clubora.keycloak_migrations')->where('account_id', $item['id'])->first();
            if ($row) {
                return $row->subject;
            }
            $subject = $gateway->create($item['email']);
            if ($subject !== null) {
                $db->table('clubora.keycloak_migrations')->insert(['account_id' => $item['id'],
                    'issuer' => config('clubunio-verein-oidc.issuer'), 'subject' => $subject, 'email' => $item['email']]);
            }

            return $subject;
        });
        if ($subject === null) {
            return 'conflict';
        }
        $gateway->invite($subject, $item['email']);
        $db->table('clubora.keycloak_migrations')->where('account_id', $item['id'])->where('subject', $subject)->update(['invited_at' => now()]);

        return 'invited';
    }

    public function activate(\stdClass $claims): ?int
    {
        if (!self::enabled() || ($claims->email_verified ?? false) !== true || !is_string($claims->email ?? null)) {
            return null;
        }
        $db = DB::connection('clubunio');

        return $db->transaction(function () use ($db, $claims) {
            $rows = $db->table('clubora.keycloak_migrations')->where('issuer', $claims->iss)->where('subject', $claims->sub)
                ->where('email', mb_strtolower(trim($claims->email)))->whereNotNull('invited_at');
            $row = (clone $rows)->lockForUpdate()->first();
            if (!$row || $row->activated_at !== null) {
                return null;
            }
            $account = \App\ClubUnio\Models\Account::find($row->account_id);
            if (!$account?->active || $account->api_only || mb_strtolower(trim($account->username)) !== $row->email || app(PlatformAdministrationService::class)->allowed($account)) {
                return null;
            }
            $access = app(MemberAccessService::class);
            if (!$account->hasBackofficeTenant() && (app(AccountSecurity::class)->requiresStrongLogin($account)
                || !$account->tenants()->get()->contains(fn ($tenant) => $access->canEnter($account, $tenant)))) {
                return null;
            }
            if (!$db->table('clubora.keycloak_account_links')->insertOrIgnore(['issuer' => $claims->iss, 'subject' => $claims->sub, 'account_id' => $row->account_id])) {
                return null;
            }
            $rows->update(['activated_at' => now()]);

            return (int) $row->account_id;
        });
    }
}
