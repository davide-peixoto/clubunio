<?php

namespace Tests\Unit;

use App\ClubUnio\Services\KeycloakMobileAssurance;
use PHPUnit\Framework\TestCase;

final class KeycloakMobileAssuranceTest extends TestCase
{
    public function test_only_fresh_exact_assurance_is_accepted(): void
    {
        $now = 1700000000;
        $level = 'clubunio-mobile-mfa';
        $this->assertTrue(KeycloakMobileAssurance::accepts((object) ['acr' => $level, 'auth_time' => $now], $level, $now));
        foreach ([[], ['acr' => '1'], ['acr' => $level], ['acr' => $level, 'auth_time' => $now - 301],
            ['acr' => $level, 'auth_time' => $now + 11], ['acr' => $level, 'auth_time' => (string) $now]] as $claims) {
            $this->assertFalse(KeycloakMobileAssurance::accepts((object) $claims, $level, $now));
        }
    }
}
