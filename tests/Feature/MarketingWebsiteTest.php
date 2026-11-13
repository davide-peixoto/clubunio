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
                ->assertDontSee('CHF')
                ->assertDontSee('data-price=', false)
                ->assertSee('/images/marketing/'.$locale.'/members.png', false)
                ->assertSee('/images/marketing/'.$locale.'/planning.png', false);
            foreach (config('clubunio-marketing.plans') as $plan) {
                $response->assertSee('data-inquiry-plan="'.$plan['key'].'"', false);
            }
        }
    }

    public function test_disabled_language_cannot_be_served(): void
    {
        $this->withoutMiddleware();
        config(['clubunio-languages.enabled' => ['de' => 'Deutsch']]);
        $this->get('/en')->assertNotFound();
    }
    public function test_valid_enquiry_sends_to_clubunio_and_confirms_after_transport_accepts_it(): void
    {
        config([
            'mail.mailers.clubunio_marketing' => ['transport' => 'array', 'host' => 'smtp.example.invalid'],
            'clubunio-marketing.mail_enabled' => true,
            'clubunio-marketing.mail_from' => 'website@example.invalid',
        ]);
        $mailer = \Illuminate\Support\Facades\Mail::mailer('clubunio_marketing');

        $this->post('/en/inquiry', [
            'kind' => 'both', 'club' => 'Demo Club', 'name' => 'Demo Contact',
            'email' => 'contact@example.invalid', 'members' => 300,
            'interests' => ['Signage'],
        ])->assertStatus(303)->assertSessionHas('marketing_inquiry_sent', true);

        $message = $mailer->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
        $this->assertSame('info@clubunio.com', $message->getTo()[0]->getAddress());
        $this->assertSame('contact@example.invalid', $message->getReplyTo()[0]->getAddress());
        $this->assertStringContainsString('Signage', $message->getTextBody());
    }
}
