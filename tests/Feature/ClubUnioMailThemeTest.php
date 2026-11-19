<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\ClubUnioMailTheme;
use App\ClubUnio\Services\SignageBrandService;
use Tests\TestCase;

class ClubUnioMailThemeTest extends TestCase
{
    public function test_standard_mail_escapes_message_content(): void
    {
        $html = app(ClubUnioMailTheme::class)->render(
            null, 'Confirm your email address', '<script>example</script>', 'https://example.test/confirm', 'Continue', 'en',
        );

        $this->assertStringContainsString('lang="en"', $html);
        $this->assertStringContainsString('#164b8a', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('Powered by ClubUnio', $html);
    }

    public function test_custom_mail_uses_the_club_brand_and_keeps_clubunio_attribution(): void
    {
        config(['clubunio-domains.hosts' => ['club.example.test' => 'test-club'], 'app.url' => 'https://app.example.test']);
        $tenant = (new Tenant)->setRawAttributes(['name' => 'Testverein', 'slug' => 'test-club']);
        $this->mock(SignageBrandService::class)->shouldReceive('design')->once()->with($tenant)->andReturn([
            'color' => '#ffff00', 'ink' => '#16191e', 'logo' => '/images/club.png',
        ]);

        $html = app(ClubUnioMailTheme::class)->render(
            $tenant, 'E-Mail-Adresse bestätigen', 'Test', 'https://example.test/confirm', 'Vorgang öffnen', 'de',
        );

        $this->assertStringContainsString('lang="de"', $html);
        $this->assertStringContainsString('Testverein', $html);
        $this->assertStringContainsString('#ffff00', $html);
        $this->assertStringContainsString('https://app.example.test/images/club.png', $html);
        $this->assertStringContainsString('Powered by ClubUnio', $html);
    }
}
