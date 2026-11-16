<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use Illuminate\Support\Facades\DB;

final class PlatformAdminKeycloakLink
{
    public static function configured(): bool
    {
        return config('clubunio-platform-oidc.enabled') && KeycloakMobileAssurance::level() !== null
            && config('clubunio-platform-oidc.origin') === config('clubunio-verein-oidc.origin');
    }

    public function resolve(\stdClass $claims): ?Account
    {
        $level = KeycloakMobileAssurance::level();
        if (!self::configured() || $level === null
            || !self::matchesMfa($claims, config('clubunio-verein-oidc.issuer'), $level, time())) {
            return null;
        }
        // This allowlist is separate from both the legacy platform mapping and
        // tenant identity links. Only explicit, operator-reviewed entries qualify.
        $links = config('clubunio-platform-oidc.migrated_subjects', []);
        $id = is_array($links) ? ($links[$claims->sub] ?? null) : null;
        $invite = null;
        if ($id === null && self::invitationsEnabled()) {
            $invite = $this->invitations()->where('issuer', $claims->iss)->where('subject', $claims->sub)
                ->where('email', mb_strtolower(trim($claims->email)))->whereNotNull('invited_at');
            $id = $invite->value('account_id');
            $id = $id === null ? null : (int) $id;
        }
        $actor = is_int($id) && $id > 0 ? Account::find($id) : null;
        if (!app(PlatformAdministrationService::class)->allowed($actor)
            || !hash_equals(mb_strtolower(trim($actor->username)), mb_strtolower(trim($claims->email)))) {
            return null;
        }
        $invite?->whereNull('activated_at')->update(['activated_at' => now()]);

        return $actor;
    }

    public static function invitationsEnabled(): bool
    {
        return self::configured() && config('clubunio-platform-oidc.invitations_enabled')
            && PlatformKeycloakMigration::enabled();
    }

    public function invitations()
    {
        return DB::connection('clubunio')->table('clubora.platform_keycloak_invitations');
    }

    public function send(Account $operator, int $id, string $confirmedEmail): string
    {
        abort_unless(self::invitationsEnabled(), 404);
        abort_if((int) $operator->id === $id, 409);
        $administration = app(PlatformAdministrationService::class);
        $gateway = app(KeycloakMigrationGateway::class);
        $db = DB::connection('clubunio');
        $row = $db->transaction(function () use ($db, $administration, $gateway, $operator, $id, $confirmedEmail) {
            $administration->authorize($operator);
            $db->select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['clubunio-platform-access']);
            $administration->authorize($operator, true);
            $target = Account::whereKey($id)->lockForUpdate()->first();
            abort_unless($administration->allowed($target), 404);
            $email = mb_strtolower(trim($target->username));
            abort_unless(hash_equals($email, $confirmedEmail) && strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL), 422);
            foreach (['clubunio-platform-oidc.subjects', 'clubunio-platform-oidc.migrated_subjects', 'clubunio-verein-oidc.subjects'] as $key) {
                abort_if(in_array($id, array_map('intval', (array) config($key)), true), 409);
            }
            $issuer = config('clubunio-verein-oidc.issuer');
            $existing = $this->invitations()->where('account_id', $id)->first();
            if ($existing) {
                abort_unless($existing->issuer === $issuer && $existing->email === $email && $existing->activated_at === null, 409);
                return $existing;
            }
            $subject = $gateway->create($email);
            if ($subject === null) {
                return null;
            }
            $data = ['account_id' => $id, 'issuer' => $issuer, 'subject' => $subject, 'email' => $email];
            $this->invitations()->insert($data);

            return (object) $data;
        });
        if ($row === null) {
            return 'conflict';
        }
        $gateway->invite($row->subject, $row->email);
        $this->invitations()->where('account_id', $id)->where('subject', $row->subject)->update(['invited_at' => now()]);
        $db->table('clubora.platform_changes')->insert(['actor_id' => $operator->id, 'tenant_id' => null,
            'action' => 'platform.keycloak.invite', 'details' => json_encode(['account_id' => $id], JSON_THROW_ON_ERROR)]);

        return 'invited';
    }

    public static function matchesMfa(\stdClass $claims, string $issuer, string $level, int $now): bool
    {
        return ($claims->iss ?? null) === $issuer && is_string($claims->sub ?? null) && $claims->sub !== ''
            && ($claims->email_verified ?? false) === true && is_string($claims->email ?? null)
            && KeycloakMobileAssurance::accepts($claims, $level, $now);
    }
}
