<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class PlatformOidcTest extends TestCase
{
    public function test_mobile_pairing_form_is_opt_in_and_rejects_invalid_pair(): void
    {
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'session.driver' => 'array',
            'clubunio-domains.central_hosts' => ['club.test'],
            'clubunio-verein-oidc.enabled' => true, 'clubunio-verein-oidc.origin' => 'https://club.test',
            'clubunio-verein-oidc.client' => 'club-test', 'clubunio-verein-oidc.secret' => 'synthetic',
            'clubunio-verein-oidc.subjects' => [], 'clubunio-verein-oidc.invites_enabled' => true,
            'clubunio-verein-oidc.mobile_enabled' => false]);
        $url = 'https://club.test/vereinslogin/oidc/mobile?pair=10000000-0000-4000-8000-000000000000';
        $this->get($url)->assertNotFound();
        config(['clubunio-verein-oidc.mobile_enabled' => true]);
        $this->get($url)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
        $this->postJson('https://club.test/vereinslogin/oidc/mobile', ['pair' => 'invalid', 'confirmation' => '12345678'])
            ->assertUnprocessable();
    }

    public function test_invite_pilot_is_opt_in_and_secrets_do_not_travel_to_keycloak(): void
    {
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'session.driver' => 'array',
            'clubunio-domains.central_hosts' => ['club.test'],
            'clubunio-verein-oidc.enabled' => true, 'clubunio-verein-oidc.origin' => 'https://club.test',
            'clubunio-verein-oidc.client' => 'club-test', 'clubunio-verein-oidc.secret' => 'synthetic',
            'clubunio-verein-oidc.subjects' => ['dummy' => 1], 'clubunio-verein-oidc.invites_enabled' => false]);
        $url = 'https://club.test/vereinslogin/oidc/invitation';
        $this->get($url)->assertNotFound();
        config(['clubunio-verein-oidc.invites_enabled' => true]);
        $this->get($url)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
        $secret = bin2hex(random_bytes(32));
        $response = $this->post($url, ['invitation' => $secret])->assertRedirect();
        $this->assertStringNotContainsString($secret, $response->headers->get('Location'));
        $response->assertSessionHas('clubunio.oidc.pending.invitation', hash('sha256', $secret));
    }

    public function test_club_pilot_cannot_reuse_platform_state_and_only_cuts_over_mapped_accounts(): void
    {
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'session.driver' => 'array',
            'clubunio-domains.central_hosts' => ['platform.test'],
            'clubunio-platform-oidc.enabled' => true, 'clubunio-platform-oidc.origin' => 'https://platform.test',
            'clubunio-platform-oidc.client' => 'platform-test', 'clubunio-platform-oidc.secret' => 'synthetic',
            'clubunio-platform-oidc.subjects' => ['platform-subject' => 123],
            'clubunio-verein-oidc.enabled' => true, 'clubunio-verein-oidc.origin' => 'https://platform.test',
            'clubunio-verein-oidc.client' => 'club-test', 'clubunio-verein-oidc.secret' => 'synthetic',
            'clubunio-verein-oidc.subjects' => ['club-subject' => 456]]);
        Http::fake();
        $response = $this->get('https://platform.test/vereinslogin/oidc/start')->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('club-test', $query['client_id']);
        $this->assertSame('https://platform.test/vereinslogin/oidc/callback', $query['redirect_uri']);
        $this->get('https://platform.test/platform/oidc/callback?state='.$query['state'].'&code=synthetic')->assertForbidden();
        Http::assertNothingSent();
        $mapped = (new \App\ClubUnio\Models\Account)->forceFill(['id' => 456]);
        $unmapped = (new \App\ClubUnio\Models\Account)->forceFill(['id' => 123]);
        $this->assertFalse(\App\ClubUnio\Http\PlatformOidcController::requiresClubOidc($mapped));
        config(['clubunio-verein-oidc.legacy_allowed' => false]);
        $this->assertTrue(\App\ClubUnio\Http\PlatformOidcController::requiresClubOidc($mapped));
        $this->assertFalse(\App\ClubUnio\Http\PlatformOidcController::requiresClubOidc($unmapped));
    }

    public function test_platform_flow_requires_exact_origin_and_one_time_state(): void
    {
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'session.driver' => 'array',
            'clubunio-domains.central_hosts' => ['platform.test', 'other.test'],
            'clubunio-platform-oidc.enabled' => true, 'clubunio-platform-oidc.origin' => 'https://platform.test',
            'clubunio-platform-oidc.client' => 'platform-test', 'clubunio-platform-oidc.secret' => 'synthetic',
            'clubunio-platform-oidc.subjects' => ['synthetic-subject' => 123]]);
        Http::fake();
        $this->get('https://other.test/platform/oidc/start')->assertNotFound();
        $response = $this->get('https://platform.test/platform/oidc/start')->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('https://platform.test/platform/oidc/callback', $query['redirect_uri']);
        $this->assertNotEmpty($query['nonce']);
        $this->get('https://platform.test/platform/oidc/callback?state=invalid&code=synthetic')->assertForbidden();
        $this->get('https://platform.test/platform/oidc/callback?state='.$query['state'].'&code=synthetic')->assertForbidden();
        Http::assertNothingSent();
        $this->assertGuest('platform');
        $this->assertGuest('clubunio');
    }
}
