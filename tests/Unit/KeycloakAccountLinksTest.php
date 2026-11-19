<?php

namespace Tests\Unit;

use App\ClubUnio\Services\KeycloakAccountLinks;
use PHPUnit\Framework\TestCase;

final class KeycloakAccountLinksTest extends TestCase
{
    public function test_invitation_requires_matching_verified_email_and_issuer(): void
    {
        $invite = (object) ['email' => 'qa@example.invalid', 'issuer' => 'https://idp.test'];
        $claims = ['email' => 'QA@example.invalid', 'issuer' => 'ignored', 'iss' => 'https://idp.test',
            'sub' => 'synthetic-subject', 'email_verified' => true];
        $this->assertTrue(KeycloakAccountLinks::matches($invite, (object) $claims));
        foreach ([['email_verified' => 'true'], ['email_verified' => false], ['email' => 'other@example.invalid'],
            ['iss' => 'https://different.test'], ['sub' => ''], ['sub' => str_repeat('a', 256)]] as $invalid) {
            $this->assertFalse(KeycloakAccountLinks::matches($invite, (object) ($invalid + $claims)));
        }
    }
}
