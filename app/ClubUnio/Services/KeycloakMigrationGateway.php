<?php

namespace App\ClubUnio\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class KeycloakMigrationGateway
{
    public function create(string $email): ?string
    {
        [$http, $users] = $this->users();
        $existing = $http->get($users, ['email' => $email, 'exact' => 'true'])->throw()->json();
        if ($existing !== []) {
            return null;
        }
        $response = $http->post($users, ['username' => $email, 'email' => $email, 'enabled' => true,
            'emailVerified' => false, 'requiredActions' => ['VERIFY_EMAIL', 'UPDATE_PASSWORD', 'CONFIGURE_TOTP']]);
        if ($response->status() === 409) {
            return null;
        }
        $location = (string) $response->header('Location');
        if ($response->status() !== 201 || !preg_match('~/users/([a-f0-9-]{36})$~D', $location, $match)) {
            throw new RuntimeException('Keycloak provisioning failed.');
        }

        return $match[1];
    }

    public function invite(string $subject, string $email): void
    {
        [$http, $users] = $this->users();
        $url = $users.'/'.rawurlencode($subject);
        $user = $http->get($url)->throw()->json();
        if (($user['id'] ?? null) !== $subject || ($user['email'] ?? null) !== $email) {
            throw new RuntimeException('Keycloak recipient changed.');
        }
        $actions = ['UPDATE_PASSWORD', 'CONFIGURE_TOTP'];
        if (($user['emailVerified'] ?? false) !== true) {
            array_unshift($actions, 'VERIFY_EMAIL');
        }
        if ($http->put($url.'/execute-actions-email?lifespan=86400', $actions)->status() !== 204) {
            throw new RuntimeException('Keycloak invitation failed.');
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
