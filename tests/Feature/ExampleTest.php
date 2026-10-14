<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Filament\Facades\Filament;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }

    public function test_old_pilot_is_removed(): void
    {
        $this->get('/admin')->assertRedirect('/');
        $this->get('/admin/login')->assertRedirect('/');
        $this->get('/admin/invoices/123')->assertRedirect('/');
        $this->get('/invoices/123/pdf')->assertNotFound();
        $this->post('/invoices/123/pdf/connect')->assertNotFound();
        $this->assertNull(config('database.connections.legacy'));
        $this->assertSame(['clubunio'], array_keys(Filament::getPanels()));
    }
}
