<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class PlatformOidcTest extends TestCase
{
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
