<?php

namespace Tests\Feature;

use App\ClubUnio\Http\{EventCheckinEntryController, EventCheckinPrintController, EventCheckinWebRoutes};
use App\ClubUnio\Models\{Account, ClubEvent, Tenant};
use App\ClubUnio\Resources\EventCheckinActions;
use App\ClubUnio\Services\{EventCheckinPoster, ModulePermissionService};
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Lang};
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class EventCheckinWebTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'clubunio-languages.enabled' => ['de' => 'Deutsch', 'en' => 'English'],
            'clubunio-event-links.app_schemes' => [
                'stage.example.invalid' => [['scheme' => 'com-my-clubunio-core-event', 'label' => 'ClubUnio']],
                'custom.example.invalid' => [
                    ['scheme' => 'com-my-sportvereinsissach-test-event', 'label' => 'My SV Sissach Test'],
                    ['scheme' => 'com-my-clubunio-core-event', 'label' => 'ClubUnio'],
                ],
            ]]);
        EventCheckinWebRoutes::public();
    }

    public function test_public_entry_opens_only_an_explicit_app_link_and_does_not_query_or_write_attendance(): void
    {
        DB::shouldReceive('connection')->never();
        $token = str_repeat('a', 64);
        foreach (['de', 'en'] as $locale) {
            $response = $this->get('https://stage.example.invalid/event-check-in/'.$token.'?lang='.$locale);
            $response->assertOk()->assertHeader('Content-Language', $locale)->assertHeader('Referrer-Policy', 'no-referrer')
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('com-my-clubunio-core-event://open?url=https%3A%2F%2Fstage.example.invalid%2Fevent-check-in%2F'.$token, $response->getContent());
            $response->assertDontSee('<script', false)->assertDontSee('window.location', false)->assertDontSee('http-equiv="refresh"', false);
        }
    }

    public function test_custom_host_has_own_build_first_and_core_as_an_explicit_fallback(): void
    {
        $response = $this->get('https://custom.example.invalid/event-check-in/'.str_repeat('b', 64));
        $response->assertOk()->assertSee('My SV Sissach Test')->assertSee('ClubUnio');
        $this->assertLessThan(strpos($response->getContent(), 'com-my-clubunio-core-event'),
            strpos($response->getContent(), 'com-my-sportvereinsissach-test-event'));
        $response->assertDontSee('member_id')->assertDontSee('attendance_id')->assertDontSee('checked_in_at');
    }

    public function test_unconfigured_host_http_and_nonstandard_port_cannot_be_forwarded_into_the_app(): void
    {
        $path = '/event-check-in/'.str_repeat('c', 64);
        foreach (['https://evil.example.invalid', 'http://stage.example.invalid', 'https://stage.example.invalid:8443'] as $origin) {
            $request = Request::create($origin.$path, 'GET');
            try { (new EventCheckinEntryController)($request, str_repeat('c', 64)); $this->fail('Untrusted origin accepted'); }
            catch (HttpException $error) { $this->assertSame(404, $error->getStatusCode()); }
        }
    }

    public function test_poster_qr_is_local_svg_with_quiet_zone_and_no_external_image_service(): void
    {
        $uri = (new EventCheckinPoster)->qrDataUri('https://stage.example.invalid/event-check-in/'.str_repeat('d', 64));
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $uri);
        $svg = base64_decode(substr($uri, strpos($uri, ',') + 1), true);
        $xml = new \DOMDocument;
        $this->assertTrue($xml->loadXML($svg, LIBXML_NONET));
        $this->assertSame('svg', $xml->documentElement->localName);
        $this->assertGreaterThan(0, $xml->getElementsByTagName('path')->length);
        $this->assertSame(0, $xml->getElementsByTagName('image')->length);
        $this->assertStringNotContainsString('<script', $svg);
    }

    public function test_qr_renderer_rejects_credentials_queries_and_wrong_token_shapes(): void
    {
        $poster = new EventCheckinPoster; $token = str_repeat('e', 64);
        foreach (['http://stage.example.invalid/event-check-in/'.$token,
            'https://user@stage.example.invalid/event-check-in/'.$token,
            'https://stage.example.invalid:8443/event-check-in/'.$token,
            'https://stage.example.invalid/event-check-in/12',
            'https://stage.example.invalid/event-check-in/'.$token.'?secret=1'] as $url) {
            try { $poster->qrDataUri($url); $this->fail('Unsafe QR URL accepted'); }
            catch (HttpException $error) { $this->assertSame(409, $error->getStatusCode()); }
        }
    }

    public function test_print_controller_rejects_a_route_from_another_current_tenant_before_reading_the_event(): void
    {
        $tenant = new Tenant; $tenant->forceFill(['id' => 10]);
        Filament::shouldReceive('getTenant')->once()->andReturn($tenant);
        $this->expectException(HttpException::class);
        (new EventCheckinPrintController)(Request::create('https://stage.example.invalid/event-check-in-print/11/7'), 11, 7);
    }

    public function test_a4_poster_escapes_event_text_displays_window_and_hides_browser_controls_when_printed(): void
    {
        $html = view('clubunio.event-checkin-print', ['card' => ['Event' => [
            'Name' => 'Meeting <script>alert(1)</script>', 'Location' => 'Club House',
            'OpensAt' => '2026-11-22T17:00:00Z', 'ClosesAt' => '2026-11-22T19:00:00Z']],
            'brand' => ['PrimaryColor' => '#cf1032', 'LogoData' => base64_encode('synthetic-image'), 'LogoMime' => 'image/png'],
            'tenantName' => 'Synthetic Club', 'qrImage' => 'data:image/svg+xml;base64,'.base64_encode('<svg/>')])->render();
        $this->assertStringContainsString('size:A4 portrait', $html);
        $this->assertStringContainsString('@media print', $html);
        $this->assertStringContainsString('.toolbar{display:none}', $html);
        $this->assertStringContainsString('22.11.2026 18:00', $html);
        $this->assertStringContainsString('22.11.2026 20:00 CET', $html);
        $this->assertStringContainsString('Meeting &lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('--club-color:#cf1032', $html);
        $this->assertStringNotContainsString('PRIVATE', $html);
    }

    public function test_print_is_disabled_until_an_active_event_has_been_configured(): void
    {
        $event = new ClubEvent; $event->forceFill(['id' => 2, 'tenant_id' => 1, 'active' => true, 'checkin_enabled' => false]);
        $actions = EventCheckinActions::make(fn () => $event);
        $print = collect($actions)->first(fn ($action) => $action->getName() === 'printEventCheckin');
        // This test isolates disabled state; the visible callback separately enforces permissions.
        $print->visible(true);
        $this->assertTrue($print->isDisabled());
        $event->checkin_enabled = true;
        $this->assertFalse($print->isDisabled());
        $event->active = false;
        $this->assertTrue($print->isDisabled());
    }

    public function test_print_requires_event_update_permission_before_it_loads_a_qr_secret(): void
    {
        config(['clubunio-event-checkin.enabled' => true]);
        $actor = \Mockery::mock(Account::class)->makePartial(); $actor->shouldReceive('fresh')->andReturnSelf();
        $tenant = new Tenant; $tenant->forceFill(['id' => 5]);
        $permissions = \Mockery::mock(new ModulePermissionService);
        $permissions->shouldReceive('authorize')->once()->with($actor, $tenant, 'events', 'update')->andThrow(new HttpException(403));
        $this->app->instance(ModulePermissionService::class, $permissions);
        DB::shouldReceive('connection')->never();
        $this->expectException(HttpException::class);
        (new EventCheckinPoster)->data($actor, $tenant, 7);
    }
}
