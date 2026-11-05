<?php

namespace Tests\Feature;

use Tests\TestCase;

final class MarketingWebsiteTest extends TestCase
{
    public function test_public_pages_render_the_requested_language_and_real_contact_without_database_access(): void
    {
        $this->withoutMiddleware();
        \Illuminate\Support\Facades\DB::shouldReceive('connection')->never();
        foreach (['de' => 'Mehr Verein.', 'en' => 'More club life.'] as $locale => $heading) {
            $response = $this->get('/'.$locale);
            $response->assertOk()->assertHeader('Content-Language', $locale)
                ->assertSee('<html lang="'.$locale.'">', false)->assertSee($heading)
                ->assertSee('mailto:info@clubunio.com', false)->assertSee('bexio')->assertSee('SFV / ASF')
                ->assertSee('/images/marketing/'.$locale.'/members.png', false)
                ->assertSee('/images/marketing/'.$locale.'/planning.png', false);
            foreach (config('clubunio-marketing.plans') as $plan) {
                $response->assertSee('data-limit="'.$plan['members'].'"', false)
                    ->assertSee('data-price="'.$plan['price'].'"', false);
            }
        }
    }

    public function test_disabled_language_cannot_be_served(): void
    {
        $this->withoutMiddleware();
        config(['clubunio-languages.enabled' => ['de' => 'Deutsch']]);
        $this->get('/en')->assertNotFound();
    }
}
