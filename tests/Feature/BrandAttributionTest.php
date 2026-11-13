<?php

namespace Tests\Feature;

use Tests\TestCase;

class BrandAttributionTest extends TestCase
{
    public function test_public_sponsor_form_keeps_the_platform_attribution_in_both_languages(): void
    {
        foreach (['de', 'en'] as $locale) {
            app()->setLocale($locale);
            $html = view('clubunio.sponsor-run-public')->render();

            $this->assertStringContainsString('class="clubunio-attribution">Powered by ClubUnio</span>', $html);
        }
    }
}
