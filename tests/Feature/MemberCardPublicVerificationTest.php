<?php

namespace Tests\Feature;

use App\ClubUnio\Http\MemberCardRoutes;
use App\ClubUnio\Services\MemberCardService;
use Tests\TestCase;

class MemberCardPublicVerificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'clubunio-languages.enabled' => ['de' => 'Deutsch', 'en' => 'English']]);
    }

    private function bindResult(array $result, bool $failure = false): void
    {
        $this->app->instance(MemberCardService::class, new class($result, $failure) extends MemberCardService {
            public function __construct(private array $result, private bool $failure) {}
            public function resolve(string $token): array
            {
                if ($this->failure) throw new \RuntimeException('PRIVATE DATABASE PATH '.$token);
                return $this->result;
            }
        });
    }

    public function test_live_public_result_needs_no_login_and_never_exposes_private_member_details(): void
    {
        foreach (['de', 'en'] as $locale) {
            $this->bindResult(['Known' => true, 'Valid' => true, 'CheckedAt' => '2026-11-22T12:34:56+00:00',
                'Name' => 'Synthetic Member', 'TenantName' => 'Synthetic Club',
                'Brand' => ['PrimaryColor' => '#123456', 'LogoData' => null, 'LogoMime' => null]]);
            $response = $this->get('/member-card/'.str_repeat('a', 64).'?lang='.$locale);
            $response->assertOk()->assertSee('Synthetic Member')->assertSee('Synthetic Club')
                ->assertHeader('Content-Language', $locale)->assertHeader('Referrer-Policy', 'no-referrer')
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
                ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
                ->assertSee(__('Membership currently valid', [], $locale));
            $this->assertStringContainsString('12:34:56+00:00', $response->getContent());
            $this->assertStringNotContainsString('Geburtsdatum', $response->getContent());
            $this->assertStringNotContainsString('Email', $response->getContent());
            $this->assertStringNotContainsString('MitgliederID', $response->getContent());
            $this->assertStringNotContainsString('<script', $response->getContent());
        }
    }

    public function test_unknown_and_revoked_results_are_never_displayed_as_valid(): void
    {
        $this->bindResult(['Known' => false, 'Valid' => false, 'CheckedAt' => '2026-11-22T12:34:56+00:00']);
        $this->get('/member-card/'.str_repeat('b', 64).'?lang=en')->assertOk()->assertSee('Member card not valid')
            ->assertDontSee('Membership currently valid')->assertDontSee('Synthetic Member');
    }

    public function test_backend_failure_is_explicitly_unavailable_and_not_a_false_valid_status(): void
    {
        $this->bindResult([], true);
        $this->get('/member-card/'.str_repeat('c', 64).'?lang=en')->assertStatus(503)
            ->assertSee('Verification currently unavailable')->assertDontSee('Membership currently valid')
            ->assertDontSee('PRIVATE DATABASE PATH')->assertDontSee(str_repeat('c', 64));
    }

    public function test_disabled_verification_service_is_unavailable_not_an_invalid_member(): void
    {
        config(['clubunio-member-cards.verification_enabled' => false]);
        $this->app->instance(MemberCardService::class, new MemberCardService);
        $this->get('/member-card/'.str_repeat('e', 64).'?lang=en')->assertStatus(503)
            ->assertSee('Verification currently unavailable')->assertDontSee('Membership currently valid')
            ->assertDontSee('Member card not valid');
    }

    public function test_disabled_languages_and_non_scalar_language_input_fall_back_to_enabled_language(): void
    {
        $this->bindResult(['Known' => false, 'Valid' => false, 'CheckedAt' => '2026-11-22T12:34:56+00:00']);
        $this->withHeader('Accept-Language', 'de');
        $this->get('/member-card/'.str_repeat('d', 64).'?lang=fr')->assertOk()->assertHeader('Content-Language', 'de');
        $this->get('/member-card/'.str_repeat('d', 64).'?lang[]=en')->assertOk()->assertHeader('Content-Language', 'de');
    }
}
