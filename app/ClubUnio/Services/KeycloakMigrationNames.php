<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Account, Tenant};

final class KeycloakMigrationNames
{
    public function forAccount(Account $account, ?Tenant $tenant = null): array
    {
        if ($tenant) {
            $member = app(MemberAccessService::class)->represented($account, $tenant, ownOnly: true)
                ->first(['first_name', 'last_name']);
            if ($member && trim((string) $member->first_name) !== '' && trim((string) $member->last_name) !== '') {
                return ['firstName' => trim($member->first_name), 'lastName' => trim($member->last_name)];
            }
        }

        return self::fromDisplayName((string) $account->display_name);
    }

    public static function fromDisplayName(string $name): array
    {
        $parts = preg_split('/\s+/u', $name, 2, PREG_SPLIT_NO_EMPTY);
        if (!$parts || count($parts) !== 2 || str_contains($name, '@')) {
            return [];
        }

        return ['firstName' => $parts[0], 'lastName' => trim($parts[1])];
    }
}
