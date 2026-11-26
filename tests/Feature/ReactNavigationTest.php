<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Services\ReactUi;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Tests\TestCase;

class ReactNavigationTest extends TestCase
{
    public function test_palette_uses_only_visible_native_links_and_translates_labels(): void
    {
        $actor = new Account; $actor->id = 10;
        auth('clubunio')->setUser($actor);
        app()->instance(ReactUi::class, new class extends ReactUi { public function enabled(?int $tenantId = null): bool { return true; } });
        Filament::partialMock()->shouldReceive('getNavigation')->andReturn([
            NavigationGroup::make('Sport')->items([
                NavigationItem::make('<unsafe>')->url('/planning')->childItems([
                    NavigationItem::make('Allowed child')->url('/allowed'),
                    NavigationItem::make('Private child')->url('/private-child')->visible(false),
                ]),
                NavigationItem::make('Private item')->url('/private')->visible(false),
            ]),
        ]);
        foreach (['de' => 'Wohin möchtest du?', 'en' => 'Where would you like to go?'] as $locale => $label) {
            app()->setLocale($locale);
            $html = view('clubunio.react-navigation')->render();
            $head = view('clubunio.react-navigation-head')->render();
            $this->assertStringContainsString('clubunio-react-navigation.css', $head);
            $this->assertStringNotContainsString('clubunio-react-navigation.css', $html);
            $this->assertStringContainsString('/js/react-ui/navigation-', $html);
            $this->assertStringContainsString('dataset.cuMotion', $head);
            $this->assertStringNotContainsString('@view-transition', $head);
            preg_match('/data-props="([^"]*)"/', $html, $match);
            $props = json_decode(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($label, $props['labels']['title']);
            $this->assertContains('/allowed', array_column($props['items'], 'url'));
            $this->assertStringNotContainsString('/private', $html);
            $this->assertStringNotContainsString('<unsafe>', $html);
        }
    }

    public function test_no_parameter_loads_no_menu_react_assets(): void
    {
        $actor = new Account; $actor->id = 10;
        auth('clubunio')->setUser($actor);
        app()->instance(ReactUi::class, new class extends ReactUi { public function enabled(?int $tenantId = null): bool { return false; } });
        $this->assertSame('', trim(view('clubunio.react-navigation')->render()));
        $this->assertSame('', trim(view('clubunio.react-navigation-head')->render()));
    }
}
