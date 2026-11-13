<?php

namespace Tests\Feature;

use App\ClubUnio\Services\TenantDomainService;
use Tests\TestCase;

final class PublicRegistrationCopyTest extends TestCase
{
    public function test_membership_application_copy_is_rendered_in_both_languages(): void
    {
        $this->mock(TenantDomainService::class)->shouldReceive('brand')->andReturnNull();
        $data = [
            'tenant' => (object) ['slug' => 'synthetic', 'name' => 'Synthetic Club'],
            'form' => ['id' => 'synthetic'],
            'errors' => new \Illuminate\Support\ViewErrorBag,
            'choices' => array_fill_keys(['gender_id', 'nationality_id', 'country_id', 'billing_recipient_id'], []),
        ];
        foreach ([
            'de' => ['Mitgliedsantrag absenden', 'zur Bearbeitung meines Mitgliedsantrags', 'Mitgliedsantrag eingegangen'],
            'en' => ['Submit membership application', 'to process my membership application', 'Membership application received'],
        ] as $locale => [$submit, $consent, $received]) {
            app()->setLocale($locale);
            session()->forget('submitted');
            $form = $this->view('clubunio.public.registration', $data);
            $form->assertSee($submit)->assertSee($consent)->assertDontSee('Testanmeldung');
            $form->assertSee('name="consent" value="1" required', false);
            session()->put('submitted', true);
            $this->view('clubunio.public.registration', $data)->assertSee($received);
        }
    }
}
