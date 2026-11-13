<?php

namespace Tests\Feature;

use Tests\TestCase;

class WebProfileRoutesTest extends TestCase
{
    public function test_profile_routes_require_the_matching_web_session(): void
    {
        foreach (['/my-profile' => '/login', '/portal/profile' => '/portal/login'] as $path => $login) {
            $this->get($path)->assertRedirect($login);
            $this->get($path.'/photo')->assertRedirect($login);
            $this->post($path.'/photo')->assertRedirect($login);
            $this->post($path.'/password')->assertRedirect($login);
        }
    }
}
