<?php

namespace App\ClubUnio\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class KeycloakMigrationGateway
{
    public function create(string $email, array $names = []): ?string
    {
        [$http, $users] = $this->users();
        $existing = $http->get($users, ['email' => $email, 'exact' => 'true'])->throw()->json();
        if ($existing !== []) {
            return null;
        }
        $response = $http->post($users, ['username' => $email, 'email' => $email, 'enabled' => true,
            'emailVerified' => false, 'requiredActions' => ['VERIFY_EMAIL', 'UPDATE_PASSWORD', 'CONFIGURE_TOTP']]
            + array_intersect_key($names, array_flip(['firstName', 'lastName'])));
        if ($response->status() === 409) {
            return null;
        }
        $location = (string) $response->header('Location');
        if ($response->status() !== 201 || !preg_match('~/users/([a-f0-9-]{36})$~D', $location, $match)) {
            throw new RuntimeException('Keycloak provisioning failed.');
        }

        return $match[1];
    }

    public function invite(string $subject, string $email, array $names = []): void
    {
        [$http, $url, $user] = $this->recipient($subject, $email);
        $this->fillMissingNames($http, $url, $user, $names);
        $actions = ['UPDATE_PASSWORD', 'CONFIGURE_TOTP'];
        if (($user['emailVerified'] ?? false) !== true) {
            array_unshift($actions, 'VERIFY_EMAIL');
        }
        $status = $http->put($url.'/execute-actions-email?lifespan=86400', $actions)->status();
        if ($status !== 204) {
            Log::warning('keycloak.migration.invite_rejected', ['http_status' => $status]);
            throw new RuntimeException('Keycloak invitation failed.');
        }
    }

    public function refreshNames(string $subject, string $email, array $names): void
    {
        [$http, $url, $user] = $this->recipient($subject, $email);
        $this->fillMissingNames($http, $url, $user, $names);
    }

    private function recipient(string $subject, string $email): array
    {
        [$http, $users] = $this->users();
        $url = $users.'/'.rawurlencode($subject);
        $user = $http->get($url)->throw()->json();
        if (($user['id'] ?? null) !== $subject || ($user['email'] ?? null) !== $email) {
            throw new RuntimeException('Keycloak recipient changed.');
        }
        return [$http, $url, $user];
    }

    private function fillMissingNames(PendingRequest $http, string $url, array $user, array $names): void
    {
        $profile = array_intersect_key($user, array_flip(['username', 'email', 'firstName', 'lastName', 'attributes']));
        $changed = false;
        foreach (['firstName', 'lastName'] as $key) {
            if (trim((string) ($profile[$key] ?? '')) === '' && trim((string) ($names[$key] ?? '')) !== '') {
                $profile[$key] = $names[$key];
                $changed = true;
            }
        }
        // Keycloak replaces profile attributes on PUT; preserve all existing attributes.
        if ($changed && ($http->put($url, $profile)->status() !== 204)) {
            throw new RuntimeException('Keycloak profile update failed.');
        }
    }

    /** @return array{PendingRequest, string} */
    private function users(): array
    {
        $issuer = config('clubunio-verein-oidc.issuer');
        if (!preg_match('~^https://[^/]+/realms/([a-zA-Z0-9_-]+)$~D', $issuer, $m)) {
            throw new RuntimeException('Invalid Keycloak issuer.');
        }
        $http = Http::acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(6);
        $token = (clone $http)->asForm()->withBasicAuth(config('clubunio-keycloak-migration.client'),
            config('clubunio-keycloak-migration.secret'))->post($issuer.'/protocol/openid-connect/token',
            ['grant_type' => 'client_credentials'])->throw()->json('access_token');
        if (!is_string($token) || $token === '') {
            throw new RuntimeException('Keycloak service authentication failed.');
        }

        return [$http->withToken($token), strstr($issuer, '/realms/', true).'/admin/realms/'.$m[1].'/users'];
    }
}
