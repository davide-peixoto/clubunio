<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\DB;

final class KeycloakAccountLinks
{
    /** For an operator-approved existing account, never an email-based account lookup. */
    public function invite(Tenant $tenant, Account $account, string $email, ?string $firstName = null, ?string $lastName = null): string
    {
        abort_unless(config('clubunio-verein-oidc.invites_enabled'), 404);
        $email = mb_strtolower(trim($email));
        abort_unless(strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL), 422);
        abort_if(($firstName === null) !== ($lastName === null), 422);
        if ($firstName !== null) {
            abort_unless(mb_strlen($firstName) <= 255 && mb_strlen($lastName) <= 255
                && app(KeycloakMemberMatch::class)->candidates($tenant, $email, $firstName, $lastName), 422);
        }
        abort_unless($account->active && ! $account->api_only && $account->tenants()->whereKey($tenant->id)->exists(), 403);
        $secret = bin2hex(random_bytes(32));
        $this->invitations()->insert([
            'token_hash' => hash('sha256', $secret), 'tenant_id' => $tenant->id, 'account_id' => $account->id,
            'issuer' => config('clubunio-verein-oidc.issuer'), 'email' => $email, 'expires_at' => now()->addHours(2),
            'first_name' => $firstName, 'last_name' => $lastName,
        ]);

        return $secret;
    }

    public function accept(string $digest, \stdClass $claims): Account
    {
        abort_unless(config('clubunio-verein-oidc.invites_enabled'), 404);

        return DB::connection('clubunio')->transaction(function () use ($digest, $claims) {
            $invite = $this->invitations()->where('token_hash', $digest)->whereNull('accepted_at')
                ->where('expires_at', '>', now())->lockForUpdate()->first();
            abort_unless($invite && self::matches($invite, $claims), 403);
            $tenant = Tenant::whereKey($invite->tenant_id)->lockForUpdate()->first();
            $account = Account::whereKey($invite->account_id)->lockForUpdate()->first();
            abort_unless($account?->active && ! $account->api_only, 403);
            $pilot = (array) config('clubunio-verein-oidc.subjects');
            abort_if(array_key_exists($claims->sub, $pilot) || in_array((int) $account->id, $pilot, true), 409);
            abort_unless($tenant && $account->tenants()->whereKey($tenant->id)->exists(), 403);
            if ($invite->first_name !== null && $invite->last_name !== null) {
                $matches = app(KeycloakMemberMatch::class)->candidates($tenant, $invite->email, $invite->first_name, $invite->last_name);
                abort_unless($matches, 403);
                $this->activateRepresentations($tenant, $account, $matches);
            }
            $backoffice = app(ModulePermissionService::class)->canEnter($account, $tenant);
            if (! $backoffice) {
                // Do not use this pilot as an alternative to enrolled local factors.
                abort_if(app(AccountSecurity::class)->requiresStrongLogin($account), 403);
                abort_unless(app(MemberAccessService::class)->canEnter($account, $tenant), 403);
            }
            $inserted = $this->links()->insertOrIgnore([
                'issuer' => $claims->iss, 'subject' => $claims->sub, 'account_id' => $account->id,
            ]);
            abort_unless($inserted === 1, 409);
            $this->invitations()->where('token_hash', $digest)->update(['accepted_at' => now()]);

            return $account;
        });
    }

    private function activateRepresentations(Tenant $tenant, Account $account, array $matches): void
    {
        $db = DB::connection('clubunio');
        $scope = ['tenant_id' => $tenant->id, 'account_id' => $account->id];
        $links = $db->table('clubunio.account_member_links')->where($scope);
        $own = (clone $links)->where('kind', 'self')->where('state', 'approved')->value('member_id');
        abort_if($own !== null && $matches['member_id'] !== null && (int) $own !== $matches['member_id'], 409);
        foreach (['self' => array_filter([$matches['member_id']]), 'parent' => $matches['child_ids']] as $kind => $ids) {
            foreach ($ids as $id) {
                $key = $scope + ['member_id' => $id, 'kind' => $kind];
                $existing = (clone $links)->where($key)->first();
                if ($existing) {
                    // Existing approvals remain intact; a manual pending or revoked decision wins.
                    continue;
                }
                $link = $db->table('clubunio.account_member_links')->insertGetId($key + [
                    'state' => 'approved', 'source' => 'keycloak_invitation',
                ]);
                $db->table('clubunio.object_changes')->insert([
                    'tenant_id' => $tenant->id, 'actor_id' => $account->id,
                    'object_type' => 'account_member_links', 'object_id' => $link,
                    'after_value' => json_encode($key + ['state' => 'approved', 'source' => 'keycloak_invitation'], JSON_THROW_ON_ERROR),
                ]);
            }
        }
    }

    public function accountId(string $issuer, string $subject): ?int
    {
        $id = $this->links()->where('issuer', $issuer)->where('subject', $subject)->value('account_id');

        return $id === null ? null : (int) $id;
    }

    public static function matches(object $invite, \stdClass $claims): bool
    {
        return ($claims->iss ?? null) === $invite->issuer
            && is_string($claims->sub ?? null) && $claims->sub !== '' && strlen($claims->sub) <= 255
            && ($claims->email_verified ?? false) === true && is_string($claims->email ?? null)
            && hash_equals($invite->email, mb_strtolower(trim($claims->email)));
    }

    private function invitations()
    {
        return DB::connection('clubunio')->table('clubunio.keycloak_account_invitations');
    }

    private function links()
    {
        return DB::connection('clubunio')->table('clubunio.keycloak_account_links');
    }
}
