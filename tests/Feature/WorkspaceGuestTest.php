<?php

namespace Tests\Feature;

use Tests\TestCase;

class WorkspaceGuestTest extends TestCase
{
    public function test_administrative_deep_links_use_the_normal_login(): void
    {
        foreach (['/bus', '/bus/new', '/planning', '/planning/calendar', '/planning/absences', '/planning/training-plans', '/documents/members/1', '/documents/invoices/1/local/pdf', '/integrations/bexio'] as $path) {
            $this->get($path)->assertRedirect('/login');
        }
        $this->get('/planning/portal-fixture/calendar')->assertRedirect('/portal/login');
    }
}
