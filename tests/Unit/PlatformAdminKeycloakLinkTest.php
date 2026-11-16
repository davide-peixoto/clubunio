<?php

namespace Tests\Unit;

use App\ClubUnio\Services\PlatformAdminKeycloakLink;
use PHPUnit\Framework\TestCase;

final class PlatformAdminKeycloakLinkTest extends TestCase
{
    public function test_platform_link_requires_fresh_mfa_and_verified_identity(): void
    {
        $issuer = 'https://idp.test/realms/qa';
        $now = 1700000000;
        $claims = ['iss' => $issuer, 'sub' => 'qa-subject', 'acr' => 'qa-mfa',
            'auth_time' => $now, 'email' => 'admin@example.invalid', 'email_verified' => true];
        $this->assertTrue(PlatformAdminKeycloakLink::matchesMfa((object) $claims, $issuer, 'qa-mfa', $now));
        foreach ([['iss' => 'https://other.test'], ['sub' => ''], ['email_verified' => false],
            ['acr' => 'password-only'], ['auth_time' => $now - 301]] as $wrong) {
            $this->assertFalse(PlatformAdminKeycloakLink::matchesMfa((object) ($wrong + $claims), $issuer, 'qa-mfa', $now));
        }
    }
}
