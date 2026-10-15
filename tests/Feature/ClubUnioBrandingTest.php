<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Tests\TestCase;

class ClubUnioBrandingTest extends TestCase
{
    public function test_blue_identity_is_available_on_both_login_surfaces(): void
    {
        $panel = Filament::getPanel('clubunio');
        $this->assertStringEndsWith('/brand/blue-v1/clubunio-logo-horizontal.svg', $panel->getBrandLogo());
        $this->assertStringEndsWith('/brand/blue-v1/clubunio-logo-horizontal-white.svg', $panel->getDarkModeBrandLogo());
        $this->assertSame(\Filament\Support\Colors\Color::convertToOklch('#164B8A'), $panel->getColors()['primary'][600]);
        $this->assertSame(\Filament\Support\Colors\Color::convertToOklch('#48A9E8'), $panel->getColors()['primary'][400]);
        foreach (['/login', '/portal/login'] as $url) {
            $this->get($url)->assertOk()
                ->assertSee('brand/blue-v1/clubunio-logo-horizontal.svg')
                ->assertSee('brand/blue-v1/clubunio-symbol.svg')
                ->assertSee('brand/blue-v1/apple-touch-icon.png')
                ->assertSee('#164B8A');
        }
        foreach (['clubunio-logo-horizontal.svg', 'clubunio-logo-horizontal-white.svg', 'clubunio-symbol.svg'] as $file) {
            $svg = file_get_contents(public_path('brand/blue-v1/'.$file));
            $this->assertNotFalse(simplexml_load_string($svg));
            $this->assertStringNotContainsString('<image', $svg);
            $this->assertStringNotContainsString('<script', $svg);
            $this->assertStringNotContainsString('<text', $svg);
        }
        $this->assertSame([180, 180], array_slice(getimagesize(public_path('brand/blue-v1/apple-touch-icon.png')), 0, 2));
        foreach (['clubunio-portal.css', 'clubunio-planning.css'] as $file) {
            $css = file_get_contents(public_path('css/'.$file));
            $this->assertStringNotContainsString('#12644b', $css);
            $this->assertStringContainsString('#164B8A', $css);
        }
    }

    public function test_brand_and_panel_path_use_clubunio(): void
    {
        $this->assertSame('ClubUnio', config('app.name'));
        $panel = Filament::getPanel('clubunio');
        $this->assertSame('ClubUnio', $panel->getBrandName());
        $this->assertSame('', $panel->getPath());
        $this->get('/')->assertRedirect('/login');
        $this->get('/clubunio/login')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('ClubUnio');
        $this->get('/portal/login')->assertOk()->assertSee('ClubUnio');
    }

    public function test_old_bookmarks_keep_the_path_and_filters(): void
    {
        $this->get('/clubora')->assertRedirect('/clubunio');
        $this->get('/clubora/login')->assertRedirect('/clubunio/login');
        $this->get('/clubora/sv-sissach/members?search=Test')
            ->assertRedirect('/clubunio/sv-sissach/members?search=Test');
    }

    public function test_bexio_callback_remains_registered_at_the_old_address(): void
    {
        $paths = array_map(fn ($route) => $route->uri(), app('router')->getRoutes()->getRoutes());
        $this->assertContains('clubora-bexio/{tenant}/callback', $paths);
    }

    public function test_internal_names_change_without_moving_the_database(): void
    {
        $this->assertSame('clubunio', (new \App\ClubUnio\Models\Member)->getConnectionName());
        $this->assertSame('clubora.members', (new \App\ClubUnio\Models\Member)->getTable());
        $this->assertSame('clubora', config('clubunio.connection.search_path'));
        $this->assertSame('clubunio', config('auth.defaults.guard'));
        $this->assertSame('/integrations/bexio/callback', route('clubunio.bexio.callback', [], false));
        $this->assertTrue(view()->exists('clubunio.invoices.viewer'));
        $this->assertFileExists(public_path('clubunio/pdf-viewer.js'));
        $this->assertFileExists(public_path('css/clubunio-portal.css'));
    }
}
